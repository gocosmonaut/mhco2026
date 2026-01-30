<?php

namespace Drupal\Tests\field_defaults\Functional;

/**
 * Tests that defaults are set on boolean fields.
 *
 * @group field_defaults
 */
class BooleanTest extends FieldDefaultsTestBase {

  /**
   * Test updating a boolean.
   */
  public function testFieldBoolean() {
    $fieldName = $this->createField();
    $this->setDefaultValues($fieldName);

    // Ensure value is checked on any random node.
    $this->drupalGet('node/' . rand(1, 20) . '/edit');
    $this->assertSession()->checkboxChecked('edit-field-' . $fieldName . '-value');
  }

}
