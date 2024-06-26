<?php

namespace dgrigg\migrationassistant\services;

use Craft;
use craft\models\FieldGroup;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\db\Query;
use yii\base\Event;
use dgrigg\migrationassistant\events\ImportEvent;
use dgrigg\migrationassistant\events\ExportEvent;
use dgrigg\migrationassistant\helpers\MigrationManagerHelper;

class Fields extends BaseMigration
{
  protected $source = 'field';
  protected $destination = 'fields';

  /**
   * @param int $id
   * @param bool $fullExport
   * @return array|bool|null
   */
  public function exportItem($id, $fullExport = false)
  {
    $includeID = false;
    $field = Craft::$app->fields->getFieldById($id);
    if (!$field) {
      return false;
    }

    $this->addManifest($field->handle);

    $newField = [
      'group' => $field->group->name,
      'name' => $field->name,
      'handle' => $field->handle,
      'instructions' => $field->instructions,
      'translationMethod' => $field->translationMethod,
      'translationKeyFormat' => $field->translationKeyFormat,
      'required' => $field->required,
      'searchable' => (empty($field->searchable) ? true : $field->searchable),
      'type' => $field->className(),
      'typesettings' => $field->settings
    ];

    if ($field->className() == 'craft\fields\Matrix') {
      $this->getMatrixField($newField, $field->id, $includeID);
    }

    if ($field->className() == 'verbb\supertable\fields\SuperTableField') {
      $this->getSuperTableField($newField, $field->id, $includeID);
    }

    if ($field->className() == 'benf\neo\Field') {
      $this->getNeoField($newField, $field->id, $includeID);
    }

    $this->getSettingHandles($newField);


    if ($fullExport) {
      $newField = $this->onBeforeExport($field, $newField);
    }

    return $newField;
  }

  /**
   * @param array $data
   * @return bool
   */
  public function importItem(array $data)
  {
    $existing = Craft::$app->fields->getFieldByHandle($data['handle']);

    if ($existing) {
      $this->mergeUpdates($data, $existing);
    } else {
      $data['id'] = 0;
    }

    $field = $this->createModel($data);
    $event = $this->onBeforeImport($field, $data);

    if ($event->isValid) {
      $result = Craft::$app->fields->saveField($event->element);
      if ($result) {
        //after the field is saved
        $this->onAfterImport($event->element, $data);
      } else {
        $this->addError('error', 'Could not save the ' . $data['handle'] . ' field.');
      }

      return $result;
    } else {
      $this->addError('error', 'Error importing ' . $data['handle'] . ' field.');
      $this->addError('error', $event->error);
      return false;
    }
  }

  /**
   * @param array $data
   * @return mixed
   */
  public function createModel(array $data)
  {
    $fieldsService = Craft::$app->fields();

    $group = $this->getFieldGroupByName($data['group']);
    if (!$group) {
      $group = new FieldGroup();
      $group->name = $data['group'];
      $fieldsService->saveGroup($group);
    }

    //go get any extra settings that need to be set based on handles
    $this->getSettingIds($data);

    $fieldData = [
      'type' => $data['type'],
      'id' => $data['id'],
      'groupId' => $group->id,
      'name' => $data['name'],
      'handle' => $data['handle'],
      'instructions' => $data['instructions'],
      'translationMethod' => $data['translationMethod'],
      'translationKeyFormat' => $data['translationKeyFormat'],
      'settings' => $data['typesettings']
    ];

    if (MigrationManagerHelper::isVersion('3.1')) {
      $fieldData['searchable'] = empty($data['searchable']) ? true : $data['searchable'];
    }

    $field = $fieldsService->createField($fieldData);

    //if this is a neo field create field layouts
    if (MigrationManagerHelper::isVersion('3.5')) {

      if ($fieldData['type'] == 'benf\neo\Field') {

        $blockTypes = $fieldData['settings']['blockTypes'];
        $newBlockTypes = [];
        foreach ($blockTypes as $key => &$block) {
          $fieldLayout = $this->createFieldLayout($block);
          $fieldLayout->type = \benf\neo\models\BlockType::class;
          unset($block['fieldLayout']);
          unset($block['fieldLayouts']);
          $newBlockType = new \benf\neo\models\BlockType($block);
          $newBlockType->setFieldLayout($fieldLayout);

          if (Craft::$app->fields->saveLayout($fieldLayout)) {
            $newBlockType->fieldLayoutId = $fieldLayout->id;
            $newBlockTypes[] = $newBlockType;
          } else {
            $this->addError('error', Craft::t('Could not save Neo block type ' . $block['handle']));
          }
        }
        $field->setBlockTypes($newBlockTypes);
      }
    }

    return $field;
  }

  /**
   * @param $name
   * @return bool|FieldGroup
   */
  private function getFieldGroupByName($name)
  {
    $query = (new Query())
      ->select(['id', 'name'])
      ->from(['{{%fieldgroups}}'])
      ->orderBy(['name' => SORT_DESC])
      ->where(['name' => $name]);

    $result = $query->one();

    if ($result) {
      $group = new FieldGroup();
      $group->id = $result['id'];
      $group->name = $result['name'];
      return $group;
    } else {
      return false;
    }
  }

  /**
   * @param $newField
   * @param $fieldId
   * @param bool $includeID
   */
  private function getMatrixField(&$newField, $fieldId, $includeID = false)
  {
    $blockTypes = Craft::$app->matrix->getBlockTypesByFieldId($fieldId);
    $blockCount = 1;
    foreach ($blockTypes as $blockType) {
      if ($includeID) {
        $blockId = $blockType->id;
      } else {
        $blockId = 'new' . $blockCount;
      }

      $newField['typesettings']['blockTypes'][$blockId] = [
        'name' => $blockType->name,
        'handle' => $blockType->handle,
        'fields' => [],
      ];
      $fieldCount = 1;
      foreach ($blockType->fields as $blockField) {
        if ($includeID) {
          $fieldId = $blockField->id;
        } else {
          $fieldId = 'new' . $fieldCount;
        }

        $newField['typesettings']['blockTypes'][$blockId]['fields'][$fieldId] = [
          'name' => $blockField->name,
          'handle' => $blockField->handle,
          'instructions' => $blockField->instructions,
          'required' => $blockField->required,
          'type' => $blockField->className(),
          'translationMethod' => $blockField->translationMethod,
          'translationKeyFormat' => $blockField->translationKeyFormat,
          'typesettings' => $blockField->settings,
        ];

        if ($blockField->className() == 'verbb\supertable\fields\SuperTableField') {
          $this->getSuperTableField($newField['typesettings']['blockTypes'][$blockId]['fields'][$fieldId], $blockField->id);
        }

        ++$fieldCount;
      }
      ++$blockCount;
    }
  }

  /**
   * @param $newField
   * @param $fieldId
   * @param bool $includeID
   */

  private function getSuperTableField(&$newField, $fieldId, $includeID = false)
  {
    $plugin =  Craft::$app->plugins->getPlugin('super-table');
    $blockTypes = $plugin->service->getBlockTypesByFieldId($fieldId);
    $fieldCount = 1;
    foreach ($blockTypes as $blockType) {
      if ($includeID) {
        $blockId = $blockType->id;
      } else {
        $blockId = 'new';
      }
      foreach ($blockType->fields() as $field) {
        if ($includeID) {
          $fieldId = $field->id;
        } else {
          $fieldId = 'new' . $fieldCount;
        }

        $newField['typesettings']['blockTypes'][$blockId]['fields'][$fieldId] = [
          'name' => $field->name,
          'handle' => $field->handle,
          'instructions' => $field->instructions,
          'required' => $field->required,
          'type' => $field->className(),
          'translationMethod' => $field->translationMethod,
          'translationKeyFormat' => $field->translationKeyFormat,
          'typesettings' => $field->settings,
        ];


        if ($field->className() == 'craft\fields\Matrix') {
          $this->getMatrixField($newField['typesettings']['blockTypes'][$blockId]['fields'][$fieldId], $field->id);
        }

        ++$fieldCount;
      }
    }

    unset($newField['typesettings']['columns']);
  }

  /**
   * @param $newField
   * @param $fieldId
   * @param bool $includeID
   */
  private function getNeoField(&$newField, $fieldId, $includeID = false)
  {

    $neo = Craft::$app->plugins->getPlugin('neo');
    $groups = $neo->blockTypes->getGroupsByFieldId($fieldId);
    if (count($groups)) {
      $newField['typesettings']['groups'] = [];
      $groupId = 0;
      foreach ($groups as $group) {
        $newField['typesettings']['groups']['uid' . $groupId] = [
          'name' => $group->name,
          'sortOrder' => $group->sortOrder
        ];
        $groupId++;
      }
    }

    $blockTypes = $neo->blockTypes->getByFieldId($fieldId);

    $blockCount = 1;
    foreach ($blockTypes as $blockType) {
      if ($includeID) {
        $blockId = $blockType->id;
      } else {
        $blockId = 'new' . $blockCount;
      }

      $newField['typesettings']['blockTypes'][$blockId] = [
        'name' => $blockType->name,
        'handle' => $blockType->handle,
        'maxBlocks' => $blockType->maxBlocks,
        'maxSiblingBlocks' => $blockType->maxSiblingBlocks,
        'maxChildBlocks' => $blockType->maxChildBlocks,
        'childBlocks' => $blockType->childBlocks,
        'topLevel' => $blockType->topLevel,
        'sortOrder' => $blockType->sortOrder,
      ];

      if (MigrationManagerHelper::isVersion('3.5')) {
        $this->getFieldLayout($blockType->getFieldLayout(), $newField['typesettings']['blockTypes'][$blockId]);
      } else {
        $fieldLayout = $blockType->getFieldLayout();
        foreach ($fieldLayout->getTabs() as $tab) {
          $newField['typesettings']['blockTypes'][$blockId]['fieldLayout'][$tab->name] = array();
          foreach ($tab->fields() as $tabField) {
            $newField['typesettings']['blockTypes'][$blockId]['fieldLayout'][$tab->name][] = $this->exportItem($tabField->id, true);
            if ($tabField->required) {
              $newField['typesettings']['blockTypes'][$blockId]['requiredFields'][] = Craft::$app->fields->getFieldById($tabField->id)->handle;
            }
          }
        }
      }
      ++$blockCount;
    }
  }

  /**
   * @param $field
   */

  private function getSettingHandles(&$field)
  {
    $this->getSourceHandles($field);
    $this->getTransformHandles($field);

    if ($field['type'] == 'craft\fields\Matrix' && key_exists('blockTypes', $field['typesettings'])) {
      foreach ($field['typesettings']['blockTypes'] as &$blockType) {
        foreach ($blockType['fields'] as &$childField) {
          $this->getSettingHandles($childField);
        }
      }
    }

    if ($field['type'] == 'verbb\supertable\fields\SuperTableField' && key_exists('blockTypes', $field['typesettings'])) {
      foreach ($field['typesettings']['blockTypes'] as &$blockType) {
        foreach ($blockType['fields'] as &$childField) {
          $this->getSettingHandles($childField);
        }
      }
    }
  }

  /**
   * @param $field
   */

  private function getSourceHandles(&$field)
  {
    if ($field['type'] == 'craft\fields\Assets') {
      if (array_key_exists('sources', $field['typesettings']) && is_array($field['typesettings']['sources'])) {
        foreach ($field['typesettings']['sources'] as $key => $value) {
          if (substr($value, 0, 7) == 'folder:') {
            $folderId = substr($value, 7);
            $source = MigrationManagerHelper::isVersion('3.1') ? Craft::$app->assets->getFolderByUId($folderId) : Craft::$app->assets->getFolderById(intval($folderId));
            if ($source) {
              $field['typesettings']['sources'][$key] = $source->getVolume()->handle;
            }
          } else if (substr($value, 0, 7) == 'volume:') {
            $folderId = substr($value, 7);
            $source = MigrationManagerHelper::isVersion('3.1') ? Craft::$app->volumes->getVolumeByUId($folderId) : Craft::$app->volumes->getVolumeById(intval($folderId));
            if ($source) {
              $field['typesettings']['sources'][$key] = $source->handle;
            }
          }
        }
      } else {
        $field['typesettings']['sources'] = array();
      }

      if (array_key_exists('defaultUploadLocationSource', $field['typesettings'])) {
        $value = $field['typesettings']['defaultUploadLocationSource'];
        if (substr($value, 0, 7) == 'folder:') {
          $folderId = substr($value, 7);
          $source = MigrationManagerHelper::isVersion('3.1') ? Craft::$app->assets->getFolderByUId($folderId) : Craft::$app->assets->getFolderById(intval($folderId));
          if ($source) {
            $field['typesettings']['defaultUploadLocationSource'] = $source->getVolume()->handle;
          }
        } else if (substr($value, 0, 7) == 'volume:') {
          $folderId = substr($value, 7);
          $source = MigrationManagerHelper::isVersion('3.1') ? Craft::$app->volumes->getVolumeByUId($folderId) : Craft::$app->volumes->getVolumeById(intval($folderId));
          if ($source) {
            $field['typesettings']['defaultUploadLocationSource'] = $source->handle;
          }
        }
      }

      if (array_key_exists('singleUploadLocationSource', $field['typesettings'])) {
        $value = $field['typesettings']['singleUploadLocationSource'];
        if (substr($value, 0, 7) == 'folder:') {
          $folderId = substr($value, 7);
          $source = MigrationManagerHelper::isVersion('3.1') ? Craft::$app->assets->getFolderByUid($folderId) : Craft::$app->assets->getFolderById(intval($folderId));
          if ($source) {
            $field['typesettings']['singleUploadLocationSource'] = $source->getVolume()->handle;
          }
        } else if (substr($value, 0, 7) == 'volume:') {
          $folderId = substr($value, 7);
          $source = MigrationManagerHelper::isVersion('3.1') ? Craft::$app->volumes->getVolumeByUid($folderId) : Craft::$app->volumes->getVolumeById(intval($folderId));
          if ($source) {
            $field['typesettings']['singleUploadLocationSource'] = $source->handle;
          }
        }
      }
    }

    if ($field['type'] == 'craft\redactor\Field') {
      if (array_key_exists('availableVolumes', $field['typesettings']) && is_array($field['typesettings']['availableVolumes'])) {
        if ($field['typesettings']['availableVolumes'] !== '*' && $field['typesettings']['availableVolumes'] != '') {
          foreach ($field['typesettings']['availableVolumes'] as $key => $value) {
            $source = Craft::$app->volumes->getVolumeById(intval($value));
            if ($source) {
              $field['typesettings']['availableVolumes'][$key] = $source->handle;
            }
          }
        }
      } elseif (array_key_exists('availableVolumes', $field['typesettings'])) {
        //leave it alone
      } else {
        $field['typesettings']['availableVolumes'] = array();
      }

      if (array_key_exists('defaultUploadLocationSource', $field['typesettings'])) {
        $value = $field['typesettings']['defaultUploadLocationSource'];
        $source = Craft::$app->volumes->getVolumeById(intval($value));
        if ($source) {
          $field['typesettings']['defaultUploadLocationSource'] = $source->handle;
        }
      }

      if (array_key_exists('singleUploadLocationSource', $field['typesettings'])) {
        $value = $field['typesettings']['singleUploadLocationSource'];
        $source = Craft::$app->volumes->getVolumeById(intval($value));

        if ($source) {
          $field['typesettings']['singleUploadLocationSource'] = $source->handle;
        }
      }
    }

    if ($field['type'] == 'craft\fields\Categories') {
      if (array_key_exists('source', $field['typesettings']) && is_string($field['typesettings']['source'])) {
        $value = $field['typesettings']['source'];
        if (substr($value, 0, 6) == 'group:') {
          $categoryId = substr($value, 6);
          $category = MigrationManagerHelper::isVersion('3.1') ? Craft::$app->categories->getGroupByUid($categoryId) : Craft::$app->categories->getGroupById(intval($categoryId));
          if ($category) {
            $field['typesettings']['source'] = $category->handle;
          } else {
            $field['typesettings']['source'] = [];
          }
        }
      }
    }

    if ($field['type'] == 'craft\fields\Entries') {
      if (array_key_exists('sources', $field['typesettings']) && is_array($field['typesettings']['sources'])) {
        foreach ($field['typesettings']['sources'] as $key => $value) {
          if ((substr($value, 0, 8) == 'section:') || (substr($value, 0, 7) == 'single:')) {
            $sourceDetails = explode(':', $value);

            $section = MigrationManagerHelper::isVersion('3.1') ? Craft::$app->sections->getSectionByUid($sourceDetails[1]) : Craft::$app->sections->getSectionById(intval($sourceDetails[1]));
            if ($section) {
              $field['typesettings']['sources'][$key] = $section->handle;
            }
          }
        }
      } else {
        $field['typesettings']['sources'] = [];
      }
    }

    if ($field['type'] == 'craft\fields\Tags') {
      if (array_key_exists('source', $field['typesettings']) && is_string($field['typesettings']['source'])) {
        $value = $field['typesettings']['source'];
        if (substr($value, 0, 9) == 'taggroup:') {
          $value = substr($value, 9);
          $tag = MigrationManagerHelper::isVersion('3.1') ?  MigrationManagerHelper::getTagGroupByUid($value) : Craft::$app->tags->getTagGroupById(intval($value));
          if ($tag) {
            $field['typesettings']['source'] = $tag->handle;
          }
        }
      }
    }

    if ($field['type'] == 'craft\fields\Users') {
      if (array_key_exists('sources', $field['typesettings']) && is_array($field['typesettings']['sources'])) {
        foreach ($field['typesettings']['sources'] as $key => $value) {
          if (substr($value, 0, 6) == 'group:') {
            $value = substr($value, 6);
            $userGroup = MigrationManagerHelper::isVersion('3.1') ? Craft::$app->userGroups->getGroupByUid($value) : Craft::$app->userGroups->getGroupById(intval($value));

            if ($userGroup) {
              $field['typesettings']['sources'][$key] = $userGroup->handle;
            }
          }
        }
      } else {
        $field['typesettings']['sources'] = [];
      }
    }
  }

  /**
   * @param $field
   */

  private function getTransformHandles(&$field)
  {
    if ($field['type'] == 'craft\redactor\Field') {
      if (array_key_exists('availableTransforms', $field['typesettings']) && is_array($field['typesettings']['availableTransforms'])) {
        foreach ($field['typesettings']['availableTransforms'] as $key => $value) {
          $transform = Craft::$app->assetTransforms->getTransformById($value);
          if ($transform) {
            $field['typesettings']['availableTransforms'][$key] = $transform->handle;
          }
        }
      }
    }
  }

  /**
   * @param $field
   */

  private function getSettingIds(&$field)
  {
    $this->getSourceIds($field);
    $this->getTransformIds($field);
    //get ids for children items

    if ($field['type'] == 'craft\fields\Matrix' && key_exists('blockTypes', $field['typesettings'])) {
      foreach ($field['typesettings']['blockTypes'] as &$blockType) {
        foreach ($blockType['fields'] as &$childField) {
          $this->getSettingIds($childField);
        }
      }
    }

    if ($field['type'] == 'verbb\supertable\fields\SuperTableField' && key_exists('blockTypes', $field['typesettings'])) {
      foreach ($field['typesettings']['blockTypes'] as &$blockType) {
        foreach ($blockType['fields'] as &$childField) {
          $this->getSettingIds($childField);
        }
      }
    }

    if ($field['type'] == 'benf\neo\Field' && key_exists('blockTypes', $field['typesettings'])) {
      foreach ($field['typesettings']['blockTypes'] as &$blockType) {
        $this->createFieldLayout($blockType);
      }
    }
  }

  /**
   * @param $field
   */

  private function getTransformIds(&$field)
  {
    if ($field['type'] == 'craft\redactor\Field') {
      if (array_key_exists('availableTransforms', $field['typesettings']) && is_array($field['typesettings']['availableTransforms'])) {
        $newTransforms = array();
        foreach ($field['typesettings']['availableTransforms'] as $value) {
          $transform = Craft::$app->assetTransforms->getTransformByHandle($value);
          if ($transform) {
            $newTransforms[] = $transform->id;
          }
        }
        $field['typesettings']['availableTransforms'] = $newTransforms;
      }
    }
  }

  /**
   * @param $field
   */

  private function getSourceIds(&$field)
  {
    if ($field['type'] == 'craft\fields\Assets') {
      $newSources = array();
      foreach ($field['typesettings']['sources'] as $source) {
        $volume = Craft::$app->volumes->getVolumeByHandle($source);
        if ($volume) {
          $newSource = Craft::$app->assets->getRootFolderByVolumeId($volume->id);
          if ($newSource) {
            $newSources[] = 'folder:' . (MigrationManagerHelper::isVersion('3.1') ? $newSource->uid : $newSource->id);
          } else {
            $this->addError('error', 'Asset source: ' . $source . ' is not defined in system');
          }
        }
      }

      $field['typesettings']['sources'] = $newSources;

      if (array_key_exists('defaultUploadLocationSource', $field['typesettings'])) {
        $volume = Craft::$app->volumes->getVolumeByHandle($field['typesettings']['defaultUploadLocationSource']);
        if ($volume) {
          $folder = Craft::$app->assets->getRootFolderByVolumeId($volume->id);
          if ($folder) {
            $field['typesettings']['defaultUploadLocationSource'] = 'folder:' . (MigrationManagerHelper::isVersion('3.1') ? $folder->uid : $folder->id);
          } else {
            $field['typesettings']['defaultUploadLocationSource'] = '';
          }
        } else {
          $field['typesettings']['defaultUploadLocationSource'] = '';
        }
      }
      if (array_key_exists('singleUploadLocationSource', $field['typesettings'])) {
        $volume = Craft::$app->volumes->getVolumeByHandle($field['typesettings']['singleUploadLocationSource']);
        if ($volume) {
          $folder = Craft::$app->assets->getRootFolderByVolumeId($volume->id);
          if ($folder) {
            $field['typesettings']['singleUploadLocationSource'] = 'folder:' . (MigrationManagerHelper::isVersion('3.1') ? $folder->uid : $folder->id);
          } else {
            $field['typesettings']['singleUploadLocationSource'] = '';
          }
        } else {
          $field['typesettings']['singleUploadLocationSource'] = '';
        }
      }
    }

    if ($field['type'] == 'craft\redactor\Field') {
      if (is_array($field['typesettings']['availableVolumes'])) {
        $newVolumes = array();
        foreach ($field['typesettings']['availableVolumes'] as $volume) {
          $newVolume = Craft::$app->volumes->getVolumeByHandle($volume);
          if ($newVolume) {
            $newVolumes[] = $newVolume->id;
          } else {
            $this->addError('error', 'Asset volume: ' . $volume . ' is not defined in system');
          }
        }
      } else {
        $newVolumes = $field['typesettings']['availableVolumes'];
      }

      $field['typesettings']['availableVolumes'] = $newVolumes;

      if (array_key_exists('defaultUploadLocationSource', $field['typesettings'])) {
        $source = MigrationManagerHelper::getAssetSourceByHandle($field['typesettings']['defaultUploadLocationSource']);
        if ($source) {
          $field['typesettings']['defaultUploadLocationSource'] = 'folder:' . $source->id;
        } else {
          $field['typesettings']['defaultUploadLocationSource'] = '';
        }
      }
      if (array_key_exists('singleUploadLocationSource', $field['typesettings'])) {
        $source = MigrationManagerHelper::getAssetSourceByHandle($field['typesettings']['singleUploadLocationSource']);
        if ($source) {
          $field['typesettings']['singleUploadLocationSource'] = 'folder:' . $source->id;
        } else {
          $field['typesettings']['singleUploadLocationSource'] = '';
        }
      }
    }

    if ($field['type'] == 'craft\fields\Categories') {
      $newSource = Craft::$app->categories->getGroupByHandle($field['typesettings']['source']);
      if ($newSource) {
        $newSource = 'group:' . (MigrationManagerHelper::isVersion('3.1') ?  $newSource->uid : $newSource->id);
      } else {
        $this->addError('error', 'Category: ' . $field['typesettings']['source'] . ' is not defined in system');
      }
      $field['typesettings']['source'] = $newSource;
    }


    if ($field['type'] == 'craft\fields\Entries') {
      $newSources = array();
      foreach ($field['typesettings']['sources'] as $source) {
        $newSource = Craft::$app->sections->getSectionByHandle($source);
        if ($newSource) {
          $type = $newSource->type == "single" ? "single" : "section";

          $newSources[] = $type . ':' . (MigrationManagerHelper::isVersion('3.1') ? $newSource->uid : $newSource->id);
        } elseif ($source == 'singles') {
          $newSources[] = $source;
        } else {
          $this->addError('error', 'Section : ' . $source . ' is not defined in system');
        }
      }

      $field['typesettings']['sources'] = $newSources;
    }

    if ($field['type'] == 'craft\fields\Tags') {
      $newSource = Craft::$app->tags->getTagGroupByHandle($field['typesettings']['source']);
      if ($newSource) {
        $newSource = 'taggroup:' . (MigrationManagerHelper::isVersion('3.1') ? $newSource->uid : $newSource->id);
      } else {
        $this->addError('error', 'Tag: ' . $field['typesettings']['source'] . ' is not defined in system');
      }
      $field['typesettings']['source'] = $newSource;
    }

    if ($field['type'] == 'craft\fields\Users') {
      $newSources = array();
      foreach ($field['typesettings']['sources'] as $source) {
        $newSource = Craft::$app->userGroups->getGroupByHandle($source);
        if ($newSource) {
          $newSources[] = 'group:' . (MigrationManagerHelper::isVersion('3.1') ? $newSource->uid : $newSource->id);
        } elseif ($source == 'admins') {
          $newSources[] = $source;
        } else {
          $this->addError('error', 'User Group: ' . $source . ' is not defined in system');
        }
      }

      $field['typesettings']['sources'] = $newSources;
    }
  }

  /**
   * @param $newField
   * @param $field
   */

  private function mergeUpdates(&$newField, $field)
  {
    $newField['id'] = $field->id;

    if ($newField['type'] == $field->className()) {
      if ($field->className() == 'craft\fields\Matrix') {
        $this->mergeMatrix($newField, $field);
      }

      if ($field->className() == 'verbb\supertable\fields\SuperTableField') {
        $this->mergeSuperTable($newField, $field);
      }

      if ($field->className() == 'benf\neo\Field') {
        $this->mergeNeo($newField, $field);
      }
    }
  }

  /**
   * @param $newField
   * @param $field
   */

  private function mergeSuperTable(&$newField, $field)
  {
    $newBlockTypes = [];
    $blockTypes = $newField['typesettings']['blockTypes'];
    $plugin =  Craft::$app->plugins->getPlugin('super-table');
    $existingBlockTypes = $plugin->service->getBlockTypesByFieldId($field->id);

    //there's only one blocktype in SuperTables to deal with
    $blockType = reset($blockTypes);
    if ($existingBlockTypes) {
      $existingBlockType = reset($existingBlockTypes);
      $this->mergeSuperTableBlockType($blockType, $existingBlockType);
      $newBlockTypes[$existingBlockType->id] = $blockType;
    } else {
      $newBlockTypes['new1'] = $blockType;
    }

    $settings = $newField['typesettings'];
    $settings['blockTypes'] = $newBlockTypes;
    $newField['typesettings'] = $settings;
  }

  /**
   * @param $newBlockType
   * @param $existingBlockType
   */

  private function mergeSuperTableBlockType(&$newBlockType, $existingBlockType)
  {
    $newFields = [];
    $context = 'superTableBlockType:' . (MigrationManagerHelper::isVersion('3.1') ? $existingBlockType->uid : $existingBlockType->id);
    $existingFields = Craft::$app->fields->getAllFields($context);

    foreach ($newBlockType['fields'] as $key => &$tableField) {
      $existingField = $this->getSuperTableFieldByHandle($tableField['handle'], $existingFields);

      if ($existingField) {
        if ($tableField['type'] == 'craft\fields\Matrix') {
          $this->mergeMatrix($tableField, $existingField);
        }

        $newFields[$existingField->id] = $tableField;
      } else {
        $newFields[$key] = $tableField;
      }
    }
    $newBlockType['fields'] = $newFields;
  }

  /**
   * @param $handle
   * @param $fields
   * @return bool
   */
  private function getSuperTableFieldByHandle($handle, $fields)
  {
    foreach ($fields as $field) {
      if ($field->handle == $handle) {
        return $field;
      }
    }
    return false;
  }

  /**
   * @param $newField
   * @param $field
   */
  private function mergeMatrix(&$newField, $field)
  {
    if (array_key_exists('blockTypes', $newField['typesettings'])) {
      $blockTypes = $newField['typesettings']['blockTypes'];
      $newBlocks = [];
      foreach ($blockTypes as $key => &$block) {

        $existingBlock = $this->getMatrixBlockByHandle($block['handle'], $field->id);
        if ($existingBlock) {
          $this->mergeMatrixBlock($block, $existingBlock);
          $newBlocks[$existingBlock->id] = $block;
        } else {
          $newBlocks[$key] = $block;
        }
      }
      $settings = $newField['typesettings'];
      $settings['blockTypes'] = $newBlocks;
      $newField['typesettings'] = $settings;
    }
  }

  /**
   * @param $newBlock
   * @param $block
   */
  private function mergeMatrixBlock(&$newBlock, $block)
  {
    $newBlock['fieldLayoutId'] = $block->fieldLayoutId;
    $newBlock['sortOrder'] = $block->sortOrder;
    $fields = $newBlock['fields'];
    $newFields = [];

    $context = 'matrixBlockType:' . (MigrationManagerHelper::isVersion('3.1') ? $block->uid : $block->id);
    $existingFields = Craft::$app->fields->getAllFields($context);

    foreach ($fields as $key => &$field) {
      $existingField = $this->getMatrixFieldByHandle($field['handle'], $existingFields);
      if ($existingField) {
        $this->mergeUpdates($field, $existingField);
        $newFields[$existingField->id] = $field;
      } else {
        $newFields[$key] = $field;
      }
    }

    $newBlock['fields'] = $newFields;
  }

  /**
   * @param $handle
   * @param $fields
   * @return bool
   */
  private function getMatrixFieldByHandle($handle, $fields)
  {
    foreach ($fields as $field) {
      if ($field->handle == $handle) {
        return $field;
      }
    }
    return false;
  }

  /**
   * @param $handle
   * @param $id
   * @return bool
   */

  private function getMatrixBlockByHandle($handle, $id)
  {
    $blocks = Craft::$app->matrix->getBlockTypesByFieldId($id);
    foreach ($blocks as $block) {
      if ($block->handle == $handle) {
        return $block;
      }
    }
    return false;
  }

  /**
   * @param $newField
   * @param $field
   */

  private function mergeNeo(&$newField, $field)
  {
    if (array_key_exists('blockTypes', $newField['typesettings'])) {
      $blockTypes = $newField['typesettings']['blockTypes'];
      $newBlocks = [];
      foreach ($blockTypes as $key => &$block) {
        $existingBlock = $this->getNeoBlockByHandle($block['handle'], $field->id);

        if ($existingBlock) {
          $newBlocks[$existingBlock->id] = $block;
        } else {
          $newBlocks[$key] = $block;
        }
      }

      $settings = $newField['typesettings'];
      $settings['blockTypes'] = $newBlocks;
      $newField['typesettings'] = $settings;
    }
  }

  private function getNeoBlockByHandle($handle, $id)
  {

    $plugin = Craft::$app->plugins->getPlugin('neo');
    $blocks = $plugin->blockTypes->getByFieldId($id);

    foreach ($blocks as $block) {
      if ($block->handle == $handle) {
        return $block;
      }
    }
    return false;
  }
}
