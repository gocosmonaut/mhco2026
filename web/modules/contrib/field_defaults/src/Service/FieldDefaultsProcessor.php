<?php

namespace Drupal\field_defaults\Service;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\SynchronizableInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\field\FieldConfigInterface;

/**
 * Field Defaults helper for processing default values.
 */
class FieldDefaultsProcessor {

  use StringTranslationTrait;

  /**
   * Constructs a new FieldDefaultsProcessor object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected readonly EntityTypeManager $entityTypeManager,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Process the field form.
   *
   * @todo Implement pluggable interface to deal with field types.
   *
   * @param \Drupal\field\FieldConfigInterface $fieldConfig
   *   The field config.
   * @param array $fieldDefaults
   *   The field defaults settings.
   * @param array $fieldValues
   *   The field values.
   */
  public function processFieldForm(FieldConfigInterface $fieldConfig, array $fieldDefaults, $fieldValues): void {
    $fieldDefaultsSettings = $this->configFactory->get('field_defaults.settings');
    // Entity References.
    if (isset($fieldValues['target_id'])) {
      // Fix odd term structure.
      if (is_array($fieldValues['target_id'])) {
        $fieldValues = $fieldValues['target_id'];
      }

      $handler = $fieldConfig->getSetting('handler');
      $provider = '';
      if (!empty($handler)) {
        $definition = explode(':', $handler);
        $provider = $definition[1] ?? $definition[0];
      }

      // Fix media structure.
      if ($provider === 'media') {
        $media_values = explode(':', $fieldValues['target_id']);
        $fieldValues = $media_values[1] ?? $media_values[0];
      }
    }

    // Load languages and overwrite settings.
    $languages = !empty($fieldDefaults['update_defaults_lang']) ? $fieldDefaults['update_defaults_lang'] : [];
    $no_overwrite = !empty($fieldDefaults['no_overwrite']) ? $fieldDefaults['no_overwrite'] : FALSE;
    $preserve = $fieldDefaultsSettings->get('retain_changed_date');

    $batch = new BatchBuilder();
    $batch->setTitle($this->t('Processing default values'))
      ->addOperation([FieldDefaultsProcessor::class, 'processEntityBatch'], [
        $fieldConfig,
        $fieldValues,
        $languages,
        $no_overwrite,
        $preserve,
      ]);
    $batch->setFinishCallback([
      FieldDefaultsProcessor::class,
      'processEntityBatchFinished',
    ]);

    batch_set($batch->toArray());
  }

  /**
   * Batch operation to process entities.
   */
  public static function processEntityBatch(FieldConfigInterface $fieldConfig, $fieldValues, $languages, $noOverwrite, $preserve, &$context) {
    $fieldName = $fieldConfig->getName();
    $entityType = $fieldConfig->getTargetEntityTypeId();
    $bundleKey = \Drupal::entityTypeManager()
      ->getDefinition($entityType)
      ->getKey('bundle');

    // Get all entities of type/bundle to process.
    $baseQuery = \Drupal::entityQuery($entityType)->accessCheck(FALSE);

    // Some entities don't have bundle (i.e. user)
    if (!empty($bundleKey)) {
      $bundle = $fieldConfig->getTargetBundle();
      $baseQuery->condition($bundleKey, $bundle);
    }

    // Setup batch.
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current_entity'] = 1;
      $maxQuery = clone $baseQuery;
      $context['sandbox']['max'] = $maxQuery->count()->execute();
    }

    // @todo allow setting this limit per batch.
    $baseQuery->range($context['sandbox']['progress'], 10);
    $entityIds = $baseQuery->execute();
    $hasChanged = FALSE;
    foreach ($entityIds as $entityId) {
      $context['sandbox']['progress']++;
      $context['sandbox']['current_entity'] = $entityId;

      if ($entity = \Drupal::entityTypeManager()->getStorage($entityType)->load($entityId)) {

        // First set the default on the current language.
        if (!$noOverwrite || $entity->get($fieldName)->isEmpty()) {
          $entity->{$fieldName} = $fieldValues;
          $hasChanged = TRUE;
        }

        // Now set any additional languages.
        foreach ($languages as $languageId => $languageValue) {
          // Value is if was checked in form.
          if ($languageValue) {
            // @todo should this add a translation if not exists?
            if ($entity->hasTranslation($languageId)) {
              $entity = $entity->getTranslation($languageId);

              if (!$noOverwrite || $entity->get($fieldName)->isEmpty()) {
                $entity->{$fieldName} = $fieldValues;
                $hasChanged = TRUE;
              }
            }
          }
        }
      }
      // Save the entity and update batch.
      if ($hasChanged) {
        // Preserve changed date.
        if ($preserve && $entity->hasField('changed')) {
          // @todo D11 will only need syncing.
          if ($entity instanceof SynchronizableInterface) {
            $entity->setSyncing(TRUE);
          }
          $entity->changed->preserve = TRUE;
        }
        $context['results'][] = $entity->save();
        $context['message'] = t("Setting Default Values on entity id: @id", ["@id" => $entityId]);
      }
    }

    // Set progress.
    if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * The batch finish handler.
   */
  public static function processEntityBatchFinished($success, $results, $operations) {
    if ($success) {
      $message = \Drupal::translation()->formatPlural(
        count($results),
        'Default values were updated for one entity.',
        'Default values were updated for @count entities.'
      );
    }
    else {
      $message = t('Finished with an error.');
    }
    \Drupal::messenger()->addMessage($message);
  }

}
