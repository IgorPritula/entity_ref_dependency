<?php

namespace Drupal\entity_ref_dependency\Services;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

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
  protected $allowedEntityTypes = [];

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
   * EntityReferenceInfoHelper object.
   *
   * @var \Drupal\entity_ref_dependency\Services\EntityReferenceInfoHelper
   */
  public $entityRefHelper;

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * DsaCoreJobListingHelper constructor.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager, EntityReferenceInfoHelper $entity_ref_helper, ConfigFactoryInterface $config_factory) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityRefHelper = $entity_ref_helper;
    $this->configFactory = $config_factory;
  }

  /**
   * Build entity index.
   *
   * Function add index data to {entity_ref_dependency} table about entities
   * dependency in reference fields of this entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Some entity (node, taxonomy term)
   * @param mixed $reference_fields
   *   Entity reference field names.
   */
  public function buildEntityIndex(EntityInterface $entity, $reference_fields = NULL) {
    if (!$reference_fields) {
      $reference_fields = $this->entityRefHelper->getEntityReferenceFields($entity->getEntityTypeId(), $entity->bundle());
      if (!$reference_fields) {
        return;
      }
    }
    $entity_ref_id_all = [];
    foreach ($reference_fields as $field_name) {
      $field_def = $entity->$field_name->getFieldDefinition();
      $target_id = $field_def->getSetting('target_type');
      foreach ($entity->$field_name as $item) {
        if (!$item->isEmpty()) {
          $entity_ref_id_all[$target_id][$field_name][] = $item->target_id;
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

        foreach ($fields as $field_name => $entity_ids) {
          $query_values['field_name'] = $field_name;

          foreach (array_unique($entity_ids) as $entity_id) {
            $query_values['ref_entity_id'] = $entity_id;
            $query->values($query_values);
          }
        }
      }
      $query->execute();
    }
  }

  /**
   * Delete index data in {entity_ref_dependency} table.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity object.
   */
  public function deleteEntityIndex(EntityInterface $entity) {
    $this->database->delete('entity_ref_dependency')
      ->condition('entity_type_id', $entity->getEntityTypeId())
      ->condition('entity_id', $entity->id())
      ->execute();
  }

  /**
   * Delete index data in {entity_ref_dependency} table for reference entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity object.
   */
  public function deleteRefEntityIndex(EntityInterface $entity) {
    $this->database->delete('entity_ref_dependency')
      ->condition('ref_entity_type_id', $entity->getEntityTypeId())
      ->condition('ref_entity_id', $entity->id())
      ->execute();
  }

  /**
   * Delete reference items.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity object.
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

  /**
   * Perform indexing of dependencies for certain bundle.
   *
   * @param string $entity_id
   *   Entity type machine name.
   * @param string $bundle_id
   *   Bundle machine name.
   */
  public function buildEntitiesIndex($entity_id, $bundle_id) {
    $reference_fields = $this->entityRefHelper->getEntityReferenceFields($entity_id, $bundle_id);
    if (!$reference_fields) {
      return;
    }

    $bundle_key = $this->entityTypeManager->getDefinition($entity_id)->getKey('bundle');
    if ($bundle_key) {
      $entities = $this->entityTypeManager->getStorage($entity_id)
        ->loadByProperties([$bundle_key => $bundle_id]);
    }
    else {
      $entities = $this->entityTypeManager->getStorage($entity_id)->loadMultiple();
    }

    foreach ($entities as $entity) {
      $this->buildEntityIndex($entity, $reference_fields);
    }
  }

  /**
   * Get depend entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity object.
   * @param bool $recursion
   *   Recursive search.
   * @param array $common_count
   *   Count of founded depend entities.
   *
   * @return array
   *   Return hierarchical array of dependency.
   */
  public function dependEntities(EntityInterface $entity, $recursion = FALSE, array &$common_count = []) {
    $dep_entities = $this->getDependEntities($entity->getEntityTypeId(), $entity->id(), $recursion, $common_count);
    if ($dep_entities && is_array($dep_entities)) {
      foreach ($dep_entities as $key => $entities) {
        if ($key == '#entity') {
          continue;
        }
        $this->loadEntities($key, $dep_entities[$key]);
      }
      return $dep_entities;
    }
    return [];
  }

  /**
   * Search depended entities and build hierarchical array of dependency.
   *
   * @param string $entity_type_id
   *   Entity type machine name.
   * @param string $entity_id
   *   Entity id.
   * @param bool $recursion
   *   Recursive search.
   * @param array $entity_used
   *   Array of founding entities. Used to avoid infinite recursion.
   *
   * @return mixed
   *   Return array of entities or $entity_id if there are no dependencies.
   */
  protected function getDependEntities($entity_type_id, $entity_id, $recursion = FALSE, array &$entity_used = []) {
    if (in_array($entity_type_id . '__' . $entity_id, $entity_used)) {
      return $entity_id;
    }
    $entity_used[] = $entity_type_id . '__' . $entity_id;

    $allowed_entity_type = $this->getAllowedEntityType();
    if (!$allowed_entity_type) {
      return $entity_id;
    }

    $query = $this->database->select('entity_ref_dependency', 'erd')
      ->condition('erd.ref_entity_type_id', $entity_type_id)
      ->condition('erd.ref_entity_id', $entity_id)
      ->condition('entity_type_id', $allowed_entity_type, 'IN')
      ->fields('erd', ['entity_type_id', 'entity_id']);
    $result = $query->execute()->fetchAll();
    if ($result != NULL) {
      $dep_entities = ['#entity' => $entity_id];
      if (!$recursion) {
        foreach ($result as $row) {
          $dep_entities[$row->entity_type_id][$row->entity_id] = $row->entity_id;
          $entity_used[] = $row->entity_type_id . '__' . $row->entity_id;
        }
        return $dep_entities;
      }
      else {
        foreach ($result as $row) {
          $dep_entities[$row->entity_type_id][$row->entity_id] = $this->getDependEntities($row->entity_type_id, $row->entity_id, $recursion, $entity_used);
        }
        return $dep_entities;
      }
    }
    return $entity_id;
  }

  /**
   * Load entities and save hierarchy structure of array.
   *
   * @param string $entity_type
   *   Entity type of $all_entities array's key.
   * @param array $all_entities
   *   Hierarchical array of entities.
   */
  protected function loadEntities($entity_type, array &$all_entities) {
    $entity_to_load = [];
    $related_entities = [];
    foreach ($all_entities as $key => $entity) {
      if (is_array($entity)) {
        $related_entities[$key] = $entity;
      }
      else {
        $entity_to_load[$key] = $entity;
      }
    }

    $entity_to_load = $this->entityTypeManager->getStorage($entity_type)->loadMultiple($entity_to_load);
    $all_entities = $entity_to_load + $all_entities;
    foreach ($related_entities as $key => $entities) {
      $all_entities[$key]['#entity'] = $this->entityTypeManager->getStorage($entity_type)->load($entities['#entity']);
      foreach ($entities as $deep_entity_type => $deep_entities) {
        if ($deep_entity_type == '#entity') {
          continue;
        }
        $this->loadEntities($deep_entity_type, $all_entities[$key][$deep_entity_type]);
      }
    }
  }

  /**
   * Return entity types which user allowed to delete.
   *
   * @return array|mixed|null
   *   Array of entity type machine names.
   */
  protected function getAllowedEntityType() {
    if ($this->allowedEntityTypes) {
      return $this->allowedEntityTypes;
    }
    return $this->allowedEntityTypes = $this->configFactory->get('entity_ref_dependency.allow_delete')->get('allowed_entity_types');
  }

  /**
   * Delete all table data.
   */
  public function clearStorage() {
    $this->database->truncate('entity_ref_dependency')->execute();
  }

}
