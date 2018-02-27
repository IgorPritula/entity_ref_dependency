<?php

namespace Drupal\entity_ref_dependency\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * A queue worker.
 *
 * @QueueWorker(
 *   id = "entity_ref_delete_entities",
 *   title = @Translation("Delete depend entities"),
 *   cron = {"time" = 10}
 * )
 */
class DeleteDependQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * EntityTypeManager object.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * DeleteDependQueueWorker constructor.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('entity_ref_delete_entities');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $dep_entities = $data->depEntities;
    $entity_types = [];
    foreach ($dep_entities as $entity__id) {
      $entity__id = explode('__', $entity__id);
      if (!isset($entity_types[$entity__id[0]])) {
        $entity_types[$entity__id[0]] = [];
      }
      $entity_types[$entity__id[0]][] = $entity__id[1];
    }
    $entities_count = 0;
    foreach ($entity_types as $entity_type => $ids) {
      $entities = $this->entityTypeManager->getStorage($entity_type)->loadMultiple($ids);
      if ($entities) {
        $this->entityTypeManager->getStorage($entity_type)->delete($entities);
        $entities_count += count($entities);
      }
    }
    if ($entities_count != 0) {
      $source_entity = $data->sourceEntity;
      $source_entity = explode('__', $source_entity);
      $this->logger->info('Was deleted @count depend entities for @entity @id',
        [
          '@count' => $entities_count,
          '@entity' => $source_entity[0],
          '@id' => $source_entity[1],
        ]);
    }
  }

}
