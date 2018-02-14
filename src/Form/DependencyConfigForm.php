<?php

namespace Drupal\entity_ref_dependency\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\entity_ref_dependency\Services\EntityReferenceInfoHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class DependencyConfigForm.
 */
class DependencyConfigForm extends ConfigFormBase {

  protected $entityInfoHelper;

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
  public function __construct(EntityReferenceInfoHelper $entity_info_helper) {
    $this->entityInfoHelper = $entity_info_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_ref_dependency.entity_info')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $depend_config = $this->config('entity_ref_dependency.allow_delete');

    $form['allow_cascade_delete'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow cascade deleting'),
      '#default_value' => $depend_config->get('allow_cascade_delete'),
    ];

    $allow_entity = $this->entityInfoHelper->getFieldableEntities();
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

}
