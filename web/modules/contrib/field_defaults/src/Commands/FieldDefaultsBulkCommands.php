<?php

namespace Drupal\field_defaults\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field_defaults\Service\FieldDefaultsProcessor;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\UserAbortException;

/**
 * Defines Drush commands for the module.
 */
class FieldDefaultsBulkCommands extends DrushCommands {

  /**
   * Construct for field defaults drush commands.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type service.
   * @param \Drupal\field_defaults\Service\FieldDefaultsProcessor $fieldDefaultsProcessor
   *   Field defaults processor service.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly FieldDefaultsProcessor $fieldDefaultsProcessor,
  ) {}

  /**
   * Bulk update defaults.
   *
   * @param string $entity_type
   *   The entity type to process.
   * @param string $entity_bundle
   *   The entity bundle to process.
   * @param string $field_name
   *   The field name to process.
   * @param string $lang
   *   A comma-separated list of languages to process.
   * @param bool $no_overwrite
   *   Whether to overwrite existing data.
   *
   * @command field_defaults:bulk-update
   * @aliases fdbu,field_defaults-bulk-update
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drush\Exceptions\UserAbortException
   */
  public function fieldDefaultsBulkUpdate($entity_type, $entity_bundle, $field_name, $lang = '', $no_overwrite = TRUE) {
    $noOverwrite = filter_var($no_overwrite, FILTER_VALIDATE_BOOLEAN);

    /** @var \Drupal\field\FieldConfigInterface $fieldConfig */
    $fieldConfig = $this->entityTypeManager
      ->getStorage('field_config')
      ->load("{$entity_type}.{$entity_bundle}.{$field_name}");

    if (!$fieldConfig) {
      $this->output()->writeln("Field {$entity_type}.{$entity_bundle}.{$field_name} not found.");
      return;
    }

    if (empty($fieldConfig->get('default_value')[0])) {
      $this->output()->writeln("Default value not set for field {$entity_type}.{$entity_bundle}.{$field_name}.");
      return;
    }

    $defaultValue = $fieldConfig->get('default_value')[0];

    $confirm = $this->io()->confirm(dt('Do you wish to continue?'));
    if (!$confirm) {
      throw new UserAbortException();
    }

    $fieldDefaults = [
      'update_defaults' => TRUE,
      'update_defaults_lang' => $lang ? explode(',', $lang) : [],
      'no_overwrite' => $noOverwrite,
    ];

    $this->fieldDefaultsProcessor->processFieldForm($fieldConfig, $fieldDefaults, $defaultValue);
    drush_backend_batch_process();
  }

}
