<?php

namespace Drupal\content_type_clone\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class for clone content type form.
 *
 * @package Drupal\content_type_clone\Form
 */
class CloneContentTypeForm extends FormBase {
  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The active request object.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $requestStack;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a CloneContentTypeForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Symfony\Component\HttpFoundation\Request $request_stack
   *   The currently active request object.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, Request $request_stack, ModuleHandlerInterface $module_handler) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->requestStack = $request_stack;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('request_stack')->getCurrentRequest(),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'clone_content_type_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Get the requested node type from url parameter.
    $nodeTypeName = $this->requestStack->query->get('node_type');

    // Load the node type.
    $entity = $this->entityTypeManager->getStorage('node_type')->load($nodeTypeName);

    // Source content type fieldset.
    $form['source'] = [
      '#type' => 'details',
      '#title' => $this->t('Content type source'),
      '#open' => FALSE,
    ];

    // Source content type name.
    $form['source']['source_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#required' => TRUE,
      '#default_value' => $entity->label(),
      '#attributes' => ['readonly' => 'readonly'],
    ];

    // Source content type machine name.
    $form['source']['source_machine_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Machine name'),
      '#required' => TRUE,
      '#default_value' => $entity->id(),
      '#attributes' => ['readonly' => 'readonly'],
    ];

    // Source content type description.
    $form['source']['source_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#required' => FALSE,
      '#default_value' => $entity->getDescription(),
      '#attributes' => ['readonly' => 'readonly'],
    ];

    // Target content type fieldset.
    $form['target'] = [
      '#type' => 'details',
      '#title' => $this->t('Content type target'),
      '#open' => TRUE,
    ];

    // Target content type name.
    $form['target']['target_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#required' => TRUE,
    ];

    // Target content type machine name.
    $form['target']['target_machine_name'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Machine name'),
      '#required' => TRUE,
      '#description' => $this->t('A unique name for this item. It must only contain lowercase letters, numbers, and underscores.'),
      '#machine_name' => [
        'exists' => ['Drupal\node\Entity\NodeType', 'load'],
        'source' => ['target' , 'target_name'],
      ],
    ];

    // Target content type description.
    $form['target']['target_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#required' => FALSE,
    ];

    // Copy nodes checkbox.
    $form['copy_source_nodes'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Copy all nodes from the source content type to the target content type'),
      '#required' => FALSE,
    ];

    // Delete nodes checkbox.
    $form['delete_source_nodes'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Delete all nodes from the source content type after they have been copied to the target content type'),
      '#required' => FALSE,
    ];

    // Token pattern fieldset.
    $form['patterns'] = [
      '#type' => 'details',
      '#title' => $this->t('Replacement patterns'),
      '#open' => FALSE,
    ];

    // Display token options.
    if ($this->moduleHandler->moduleExists('token')) {
      // Display the node title pattern field.
      $placeholder = $this->t('Clone of @title', ['@title' => '[node:title]']);
      $form['patterns']['title_pattern'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Node title pattern'),
        '#attributes' => [
          'placeholder' => $placeholder,
        ],
      ];

      $form['patterns']['token_tree'] = [
        '#title' => $this->t('Tokens'),
        '#theme' => 'token_tree_link',
        '#token_types' => ['node'],
        '#show_restricted' => TRUE,
        '#global_types' => TRUE,
        '#required' => TRUE,
      ];
    }
    else {
      $form['patterns']['token_tree'] = [
        '#markup' => '<p>' . $this->t('Enable the <a href="@drupal-token">Token module</a> to view the available token browser.',
          [
            '@drupal-token' => 'http://drupal.org/project/token',
          ]
        ) . '</p>',
      ];
    }

    // Clone submit button.
    $form['cct_clone'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clone content type'),
    ];

    // Return the result.
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Get the submitted form values.
    $values = $form_state->getValues();

    // Retrieve the existing content type names.
    $contentTypesNames = $this->getContentTypesList();

    // Check if the machine name already exists.
    if (in_array($values['target_machine_name'], $contentTypesNames)) {
      $form_state->setErrorByName(
        'target_machine_name',
        $this->t('The machine name of the target content type already exists.')
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Create the batch process.
    $batch = [
      'title' => $this->t('Batch operations'),
      'operations' => $this->buildOperationsList($form_state),
      'finished' => '\Drupal\content_type_clone\Form\CloneContentType::cloneContentTypeFinishedCallback',
      'init_message' => $this->t('Performing batch operations...'),
      'error_message' => $this->t('Something went wrong. Please check the errors log.'),
    ];

    // Set the batch.
    batch_set($batch);
  }

  /**
   * Builds the operations array for the batch process.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Object describing the current state of the form.
   *
   * @return array
   *   An array of operations to perform
   */
  protected function buildOperationsList(FormStateInterface $form_state) {
    // Get the form values.
    $values = $form_state->getValues();

    // Prepare the operations array.
    $operations = [];

    // Clone content type operation.
    $operations[] = [
      '\Drupal\content_type_clone\Form\CloneContentType::contentTypeClone',
      [$values],
    ];

    // Clone fields operations.
    $fields = $this->entityFieldManager->getFieldDefinitions('node', $values['source_machine_name']);
    foreach ($fields as $field) {
      if (!empty($field->getTargetBundle())) {
        $data = ['field' => $field, 'values' => $values];
        $operations[] = [
          '\Drupal\content_type_clone\Form\CloneContentType::cloneContentTypeField',
          [$data],
        ];
      }
    }

    // Clone nodes operations.
    if ((int) $values['copy_source_nodes'] == 1) {
      $nids = $this->entityTypeManager->getStorage('node')->getQuery('AND')->condition('type', $values['source_machine_name'])->accessCheck()->execute();
      foreach ($nids as $nid) {
        if ((int) $nid > 0) {
          $operations[] = [
            '\Drupal\content_type_clone\Form\CloneContentType::copyContentTypeNode',
            [$nid, $values],
          ];
        }
      }
    }

    // Return the result.
    return $operations;
  }

  /**
   * Get existing content type names.
   */
  protected function getContentTypesList() {
    // Get the existing content types.
    $contentTypes = $this->entityTypeManager->getStorage('node_type')->loadMultiple();

    // Retrieve the existing content type names.
    $contentTypesNames = [];
    foreach ($contentTypes as $contentType) {
      $contentTypesNames[] = $contentType->id();
    }

    // Return the result.
    return $contentTypesNames;
  }

}
