<?php

namespace Drupal\Tests\field_defaults\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests that defaults are set on fields.
 *
 * @group field_defaults
 */
abstract class FieldDefaultsTestBase extends BrowserTestBase {

  /**
   * The administrator account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $administratorAccount;

  /**
   * The field name.
   *
   * @var string
   */
  protected $fieldName;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['block', 'node', 'field_ui', 'field_defaults'];

  /**
   * {@inheritdoc}
   *
   * Once installed, a content type with the desired field is created.
   */
  protected function setUp(): void {
    // Install Drupal.
    parent::setUp();

    // Add the system menu blocks to appropriate regions.
    $this->setupMenus();

    // Create a Content type and some nodes.
    $this->drupalCreateContentType(['type' => 'page']);

    // Create and login a user that creates the content type.
    $permissions = [
      'administer nodes',
      'administer content types',
      'administer node fields',
      'edit any page content',
      'administer field defaults',
    ];
    $this->administratorAccount = $this->drupalCreateUser($permissions);
    $this->drupalLogin($this->administratorAccount);

    // Create some dummy content.
    for ($i = 0; $i < 20; $i++) {
      $this->drupalCreateNode();
    }
  }

  /**
   * Set up menus and tasks in their regions.
   *
   * Since menus and tasks are now blocks, we're required to explicitly set them
   * to regions.
   *
   * Note that subclasses must explicitly declare that the block module is a
   * dependency.
   */
  protected function setupMenus() {
    $this->drupalPlaceBlock('system_menu_block:tools', ['region' => 'primary_menu']);
    $this->drupalPlaceBlock('local_tasks_block', ['region' => 'secondary_menu']);
    $this->drupalPlaceBlock('local_actions_block', ['region' => 'content']);
    $this->drupalPlaceBlock('page_title_block', ['region' => 'content']);
  }

  /**
   * Creates a field on a content entity.
   */
  protected function createField($type = 'boolean', $cardinality = '1', $contentType = 'page') {
    // Drupal keeps changing the UI :(.
    $oldUI = version_compare(\Drupal::VERSION, '11.1.6', '<=');
    $this->drupalGet('admin/structure/types/manage/' . $contentType . '/fields');
    $this->clickLink('Create a new field');

    // Make a name for this field.
    $field_name = strtolower($this->randomMachineName(10));
    $edit = [
      'field_name' => $field_name,
      'label' => $field_name,
    ];

    if ($type == 'plain_text') {
      $optionsName = $oldUI ? 'group_field_options_wrapper' : 'field_options_wrapper';
      $edit += [
        $optionsName => 'string',
      ];
    }

    if ($oldUI) {
      $this->submitForm(['new_storage_type' => $type], 'Continue');
      $this->submitForm($edit, 'Continue');
    }
    else {
      $this->clickLink(ucfirst(str_replace('_', ' ', $type)));
      $this->submitForm($edit, 'Continue');
    }

    // Fill out the $cardinality form as if we're not using an unlimited values.
    $edit = [
      'field_storage[subform][cardinality]' => 'number',
      'field_storage[subform][cardinality_number]' => (string) $cardinality,
    ];

    // -1 for $cardinality, we should change to 'Unlimited'.
    if (-1 == $cardinality) {
      $edit = [
        'field_storage[subform][cardinality]' => '-1',
        'field_storage[subform][cardinality_number]' => '1',
      ];
    }

    // Save.
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains("Saved $field_name configuration.");

    return $field_name;
  }

  /**
   * Sets a default value and runs the batch update.
   *
   * @todo Add support for cardinality.
   * @todo Add support for language.
   */
  protected function setDefaultValues($fieldName, $field_type = 'boolean', $value = NULL, $contentType = 'page') {
    $this->drupalGet('admin/structure/types/manage/' . $contentType . '/fields/node.' . $contentType . '.field_' . $fieldName);

    $field_setup = $this->setupFieldByType($field_type, $value);

    // Fill out the field form.
    $edit = [
      'set_default_value' => TRUE,
      'default_value_input[field_' . $fieldName . ']' . $field_setup['structure'] => $field_setup['value'],
      'default_value_input[field_defaults][update_defaults]' => TRUE,
    ];

    // Run batch.
    $this->submitForm($edit, 'Save settings');
    $this->assertSession()->responseNotContains('Initial progress message is not double escaped.');
    // Now also go to the next step.
    $this->maximumMetaRefreshCount = 3;
    $this->assertSession()->responseContains('Default values were updated');
  }

  /**
   * Helper for field structure.
   *
   * @todo Add support for cardinality.
   */
  protected function setupFieldByType($type, $defaultValue = NULL) {
    switch ($type) {
      case 'string':
        // Defaults for boolean per function def.
        $structure = '[0][value]';
        $value = $defaultValue ?? 'field default';
        break;

      default:
        // Defaults for boolean per function def.
        $structure = '[value]';
        $value = $defaultValue ?? TRUE;
    }
    return ['structure' => $structure, 'value' => $value];
  }

}
