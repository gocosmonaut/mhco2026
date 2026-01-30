<?php

namespace Drupal\field_defaults\Decorated;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\ChangedItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Override the ChangedItem class to preserve the changed timestamp.
 */
class PreserveChangedItem extends ChangedItem {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['preserve'] = DataDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Changed timestamp should be preserved.'));
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(): void {
    if ($this->preserve) {
      return;
    }
    parent::preSave();
  }

}
