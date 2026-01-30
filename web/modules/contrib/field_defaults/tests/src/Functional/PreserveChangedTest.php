<?php

namespace Drupal\Tests\field_defaults\Functional;

/**
 * Tests that entity times are preserved or not based on setting.
 *
 * @group field_defaults
 */
class PreserveChangedTest extends FieldDefaultsTestBase {

  /**
   * Test changing two fields with different preserve options.
   */
  public function testPreserveChanged() {
    // Add seconds to the core short date format.
    $this->config('core.date_format.short')
      ->set('pattern', 'Y-m-d H:i:s')
      ->save();

    $this->drupalGet('node/1/edit');
    $page = $this->getSession()->getPage();
    $origChanged = $page->find('css', '.entity-meta__last-saved')->getText();

    $fieldName = $this->createField();
    $this->setDefaultValues($fieldName);

    // Ensure value is checked on any random node.
    $this->drupalGet('node/1/edit');
    $this->assertSession()->checkboxChecked('edit-field-' . $fieldName . '-value');

    // Date should not have changed.
    $page = $this->getSession()->getPage();
    $updatedChanged = $page->find('css', '.entity-meta__last-saved')->getText();
    $this->assertEquals($origChanged, $updatedChanged);

    // Change the settings and retest.
    $this->config('field_defaults.settings')
      ->set('retain_changed_date', 0)
      ->save();
    $this->rebuildContainer();

    $this->assertEquals(
      $this->config('field_defaults.settings')->get('retain_changed_date'),
      0
    );

    // Set the value to something different and validate.
    $this->setDefaultValues($fieldName, 'boolean', 0);
    $this->drupalGet('node/1/edit');
    $this->assertSession()->checkboxNotChecked('edit-field-' . $fieldName . '-value');

    // Now check that the date changed.
    $page = $this->getSession()->getPage();
    $updatedChanged = $page->find('css', '.entity-meta__last-saved')->getText();
    $this->assertNotEquals($origChanged, $updatedChanged);
  }

}
