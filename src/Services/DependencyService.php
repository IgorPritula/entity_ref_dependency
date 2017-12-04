<?php

namespace Drupal\entity_ref_dependency\Services;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Class DependencyService.
 *
 * @package Drupal\entity_ref_dependency\Services
 */
class DependencyService {

  /**
   * Allowed entity which are reference entity in reference field.
   *
   * @var array
   */
  protected $allowTargetType = [
    'document',
    'node',
    'taxonomy_term',
    'widget',
  ];

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;


  /**
   * EntityTypeManager object.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * DsaCoreJobListingHelper constructor.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Build entity index.
   *
   * Function add index data to {entity_ref_dependency} table about entities
   * dependency in reference fields of this entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Some entity (node, taxonomy term)
   */
  public function buildEntityIndex(EntityInterface $entity) {
    $entity_ref_id_all = [];
    $entity_reference_class = 'Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem';
    foreach ($entity->getFieldDefinitions() as $field) {
      $field_name = $field->getName();
      $class = $field->getItemDefinition()->getClass();
      $is_entity_reference_class = ($class === $entity_reference_class) || is_subclass_of($class, $entity_reference_class);

      $allow_target_type = $this->allowTargetType;
      // Check if storage does not provide by custom module.
      $storoge = $field->getFieldStorageDefinition()->hasCustomStorage();
      if ($is_entity_reference_class && in_array($field->getSetting('target_type'), $allow_target_type) && !$storoge) {
        $target_id = $field->getSetting('target_type');
        foreach ($entity->$field_name as $item) {
          if (!$item->isEmpty()) {
            $entity_ref_id_all[$target_id][$field_name][] = $item->target_id;
          }
        }
      }
    }

    if (!empty($entity_ref_id_all)) {
      $query = $this->database->insert('entity_ref_dependency')
        ->fields([
          'entity_type_id',
          'entity_id',
          'ref_entity_type_id',
          'ref_entity_id',
          'field_name',
        ]);
      $query_values = [
        'entity_type_id' => $entity->getEntityTypeId(),
        'entity_id' => $entity->id(),
      ];
      foreach ($entity_ref_id_all as $ref_entity_type_id => $fields) {
        $query_values['ref_entity_type_id'] = $ref_entity_type_id;

        foreach ($fields as $field_name => $field_ids) {
          $query_values['field_name'] = $field_name;

          foreach ($field_ids as $field_id) {
            $query_values['ref_entity_id'] = $field_id;
            $query->values($query_values);
          }
        }
      }
      $query->execute();
    }
  }

  /**
   * Delete index data in {entity_ref_dependency} table.
   */
  public function deleteEntityIndex(EntityInterface $entity) {
    $this->database->delete('entity_ref_dependency')
      ->condition('entity_type_id', $entity->getEntityTypeId())
      ->condition('entity_id', $entity->id())
      ->execute();
  }

  /**
   * Delete index data in {entity_ref_dependency} table for reference entity.
   */
  public function deleteRefEntityIndex(EntityInterface $entity) {
    $this->database->delete('entity_ref_dependency')
      ->condition('ref_entity_type_id', $entity->getEntityTypeId())
      ->condition('ref_entity_id', $entity->id())
      ->execute();
  }

  /**
   * Delete reference items.
   */
  public function deleteReferenceItem(EntityInterface $entity) {
    $query = $this->database->select('entity_ref_dependency', 'erd')
      ->condition('erd.ref_entity_type_id', $entity->getEntityTypeId())
      ->condition('erd.ref_entity_id', $entity->id())
      ->fields('erd', ['entity_type_id', 'entity_id', 'field_name']);
    $result = $query->execute()->fetchAll();
    if ($result != NULL) {
      foreach ($result as $row) {
        $field_name = $row->field_name;
        $ref_entity = $this->entityTypeManager
          ->getStorage($row->entity_type_id)
          ->load($row->entity_id);
        if ($ref_entity != FALSE) {
          $field = $ref_entity->$field_name;
          if ($field != NULL) {
            foreach ($ref_entity->$field_name as $delta => $item) {
              if ($item->target_id == $entity->id()) {
                unset($ref_entity->{$field_name}[$delta]);
                $ref_entity->save();
              }
            }
          }
        }
      }
      $this->deleteRefEntityIndex($entity);
    }
    $this->deleteEntityIndex($entity);
  }

}
