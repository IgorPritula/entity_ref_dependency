<?php

namespace Drupal\entity_ref_dependency\Services;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Database\Connection;

/**
 * Class EntityReferenceInfoHelper.
 */
class EntityReferenceInfoHelper {

  const ENTITY_REFERENCE_CLASS = 'Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem';

  /**
   * DsaCoreJobListingHelper constructor.
   */
  public function __construct(EntityFieldManagerInterface $entity_field_manager, EntityTypeManagerInterface $entity_type_manager, Connection $database) {
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
  }

  /**
   * Description.
   */
  public function getFieldableEntities() {
    $entity_types = $this->entityTypeManager->getDefinitions();
    $entities = [];
    foreach ($entity_types as $entity_type) {
      $fieldable = $entity_type->get('field_ui_base_route');
      if ($fieldable != FALSE) {
        $entities[$entity_type->get('id')] = $entity_type;
      }
    }
    return $entities;
  }

  /**
   * Description.
   */
  public function getEntityBundles($bundle_entity_type) {
    $bundles = $this->entityTypeManager->getStorage($bundle_entity_type)->loadMultiple();
    $bundlesTypesList = [];
    foreach ($bundles as $bundle) {
      $bundlesTypesList[$bundle->id()] = $bundle;
    }
    return $bundlesTypesList;
  }

  /**
   * Description.
   */
  public function getEntityReferenceFields($entity_id, $bundle_id) {
    $fields = $this->entityFieldManager->getFieldDefinitions($entity_id, $bundle_id);
    $reference_fields = [];
    foreach ($fields as $field) {
      $class = $field->getItemDefinition()->getClass();
      // Check if field is entity reference field.
      $is_entity_reference_class = ($class === self::ENTITY_REFERENCE_CLASS) || is_subclass_of($class, self::ENTITY_REFERENCE_CLASS);

      // Check if field isn't base.
      $is_base = $field->getFieldStorageDefinition()->isBaseField();
      // Check if storage does not provide by custom module.
      $storage = $field->getFieldStorageDefinition()->hasCustomStorage();
      if ($is_entity_reference_class && !$storage && !$is_base) {
        $field_name = $field->getName();
        $reference_fields[] = $field_name;
      }
    }
    return $reference_fields;
  }

}
