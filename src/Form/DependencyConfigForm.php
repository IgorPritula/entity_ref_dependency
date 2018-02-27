<?php

namespace Drupal\entity_ref_dependency\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\entity_ref_dependency\Services\DependencyService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class DependencyConfigForm.
 */
class DependencyConfigForm extends ConfigFormBase {

  protected $dependService;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'entity_ref_dependency';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'entity_ref_dependency.allow_delete',
    ];
  }

  /**
   * DependencyConfigForm constructor.
   */
  public function __construct(DependencyService $entity_info_helper) {
    $this->dependService = $entity_info_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_ref_dependency.dependency_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $depend_config = $this->config('entity_ref_dependency.allow_delete');

    $form['indexing'] = [
      '#type' => 'submit',
      '#value' => $this->t('Perform indexing'),
      '#submit' => ['::performIndexing'],
    ];

    $form['allow_cascade_delete'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow cascade deleting'),
      '#default_value' => $depend_config->get('allow_cascade_delete'),
    ];

    $allow_entity = $this->dependService->entityRefHelper->getFieldableEntities();
    $options = [];
    foreach ($allow_entity as $id => $entity_type) {
      $options[$id] = $entity_type->getLabel();
    }
    $form['allowed_entity_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Allow cascade deleting'),
      '#options' => $options,
    ];
    if ($depend_config->get('allowed_entity_types')) {
      $form['allowed_entity_types']['#default_value'] = $depend_config->get('allowed_entity_types');
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $triggerElement = $form_state->getTriggeringElement();
    if ($triggerElement['#name'] == 'indexing') {
      return;
    }
    $depend_config = $this->config('entity_ref_dependency.allow_delete');

    $depend_config->set('allow_cascade_delete', $form_state->getValue('allow_cascade_delete'));
    $checked_options = $form_state->getValue('allowed_entity_types');
    $allowed_entity_types = [];
    foreach ($checked_options as $entity_type) {
      if ($entity_type) {
        $allowed_entity_types[] = $entity_type;
      }
    }
    $depend_config->set('allowed_entity_types', $allowed_entity_types);
    $depend_config->save();
  }

  /**
   * Form submit function, perform entities index.
   */
  public function performIndexing(array &$form, FormStateInterface $form_state) {
    $this->dependService->clearStorage();
    $entities = $this->dependService->entityRefHelper->getFieldableEntities();

    foreach ($entities as $entity_type_id => $entity_type) {
      $bundle_entity_type = $entity_type->get('bundle_entity_type');
      if ($bundle_entity_type != NULL) {
        $entities[$entity_type_id] = $this->dependService->entityRefHelper->getEntityBundles($bundle_entity_type);
      }
      else {
        $entities[$entity_type_id] = NULL;
      }
    }

    $batch = [
      'title' => $this->t('Exporting'),
      'finished' => [$this, 'batchFinishedCallback'],
    ];
    $operations = [];
    foreach ($entities as $entity_id => $bundles) {
      if ($bundles != NULL) {
        foreach ($bundles as $bundle_id => $bundle) {
          $operations[] = [
            [$this, 'batchProcessFunction'],
            [$entity_id, $bundle_id],
          ];
        }
      }
      else {
        $operations[] = [
          [$this, 'batchProcessFunction'],
          [$entity_id, $entity_id],
        ];
      }
    }
    $batch['operations'] = $operations;
    batch_set($batch);
  }

  /**
   * Batch operation function.
   *
   * @param string $entity_type_id
   *   Entity type machine name.
   * @param string $bundle_id
   *   Bundle machine name.
   * @param array $context
   *   Batch context.
   */
  public static function batchProcessFunction($entity_type_id, $bundle_id, array &$context) {
    if (empty($context['results'])) {
      $context['results']['count'] = 0;
    }
    $context['message'] = 'Processing ' . $entity_type_id;

    $entity_info = \Drupal::service('entity_ref_dependency.entity_info');
    if (empty($context['sandbox'])) {
      $context['sandbox']['reference_fields'] = $entity_info->getEntityReferenceFields($entity_type_id, $bundle_id);
      if (!$context['sandbox']['reference_fields']) {
        return;
      }
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current_id'] = 0;

      $context['sandbox']['bundle_key'] = $bundle_key = $entity_info->entityTypeManager->getDefinition($entity_type_id)->getKey('bundle');
      $context['sandbox']['id_key'] = $id_key = $entity_info->entityTypeManager->getStorage($entity_type_id)->getEntityType()->getKey('id');

      if ($bundle_key) {
        $context['sandbox']['max'] = $entity_info->entityTypeManager->getStorage($entity_type_id)->getQuery()
          ->condition($bundle_key, $bundle_id)
          ->condition($id_key, $context['sandbox']['current_id'], '>')
          ->count()
          ->execute();
      }
      else {
        $context['sandbox']['max'] = $entity_info->entityTypeManager->getStorage($entity_type_id)->getQuery()
          ->condition($id_key, $context['sandbox']['current_id'], '>')
          ->count()
          ->execute();
      }
    }

    if ($context['sandbox']['bundle_key'] != NULL) {
      $entities_id = $entity_info->entityTypeManager->getStorage($entity_type_id)->getQuery()
        ->condition($context['sandbox']['bundle_key'], $bundle_id)
        ->condition($context['sandbox']['id_key'], $context['sandbox']['current_id'], '>')
        ->range(0, 50)
        ->execute();
      $entities = $entity_info->entityTypeManager->getStorage($entity_type_id)->loadMultiple($entities_id);
    }
    else {
      $entities_id = $entity_info->entityTypeManager->getStorage($entity_type_id)->getQuery()
        ->condition($context['sandbox']['id_key'], $context['sandbox']['current_id'], '>')
        ->range(0, 50)
        ->execute();
      $entities = $entity_info->entityTypeManager->getStorage($entity_type_id)->loadMultiple($entities_id);
    }
    $dep_service = \Drupal::service('entity_ref_dependency.dependency_service');
    foreach ($entities as $id => $entity) {
      $dep_service->buildEntityIndex($entity, $context['sandbox']['reference_fields']);
      $context['sandbox']['progress']++;
      $context['sandbox']['current_id'] = $id;
      $context['results']['count']++;
    }

    if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Batch finished function.
   */
  public static function batchFinishedCallback($success, $results, $operations) {
    if ($success) {
      $message = t('Was indexed @count entities', ['@count' => $results['count']]);
    }
    else {
      $message = t('Finished with an error.');
    }
    drupal_set_message($message);
  }

}
