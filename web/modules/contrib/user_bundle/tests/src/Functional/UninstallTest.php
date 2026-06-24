<?php

namespace Drupal\Tests\user_bundle\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;
use Drupal\user_bundle\Entity\UserType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the module's uninstall behavior.
 *
 * Uninstalling removes the account types (user bundles), which must not destroy
 * field data and must not leave behind references to the entity_bundle:user
 * condition that core only derives while the user entity is bundleable.
 *
 * @group user_bundle
 */
#[RunTestsInSeparateProcesses]
class UninstallTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['field', 'user', 'block'];

  /**
   * Field data on every bundle is migrated back onto the "user" bundle.
   */
  public function testUninstallMigratesFieldData(): void {
    // A field on the user entity created before the module is installed, the
    // way the standard profile ships the user_picture field. It is shared with
    // a custom bundle below.
    FieldStorageConfig::create([
      'field_name' => 'field_shared',
      'entity_type' => 'user',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_shared',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Shared field',
    ])->save();

    // A regular user account with data in the shared field.
    $regular = User::create([
      'name' => 'regular_account',
      'status' => 1,
      'field_shared' => 'regular value',
    ]);
    $regular->save();

    // Install the module and add a custom account type.
    $this->container->get('module_installer')->install(['user_bundle']);
    UserType::create(['id' => 'staff', 'label' => 'Staff'])->save();

    // Attach the shared field to the custom bundle, plus a field that only
    // exists on the custom bundle.
    FieldConfig::create([
      'field_name' => 'field_shared',
      'entity_type' => 'user',
      'bundle' => 'staff',
      'label' => 'Shared field',
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_staff_only',
      'entity_type' => 'user',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_staff_only',
      'entity_type' => 'user',
      'bundle' => 'staff',
      'label' => 'Staff only field',
    ])->save();

    // A staff account with data in both fields.
    $staff = User::create([
      'name' => 'staff_account',
      'type' => 'staff',
      'status' => 1,
      'field_shared' => 'staff shared value',
      'field_staff_only' => 'staff only value',
    ]);
    $staff->save();

    // Uninstall the module. Field data is purged on cron, so run it before
    // checking what is left.
    $this->container->get('module_installer')->uninstall(['user_bundle']);
    $this->container->get('cron')->run();

    // The field storages survive.
    $this->assertNotNull(FieldStorageConfig::loadByName('user', 'field_shared'), 'The shared field storage was deleted.');
    $this->assertNotNull(FieldStorageConfig::loadByName('user', 'field_staff_only'), 'The staff field storage was deleted.');

    // Both fields are now instances on the default "user" bundle, and the
    // bundle-specific instances are gone.
    $this->assertNotNull(FieldConfig::loadByName('user', 'user', 'field_shared'));
    $this->assertNotNull(FieldConfig::loadByName('user', 'user', 'field_staff_only'));
    $this->assertNull(FieldConfig::loadByName('user', 'staff', 'field_shared'));
    $this->assertNull(FieldConfig::loadByName('user', 'staff', 'field_staff_only'));

    // The migrated instances don't dangle a dependency on the removed bundle.
    foreach (FieldConfig::loadByName('user', 'user', 'field_staff_only')->getDependencies()['config'] ?? [] as $dependency) {
      $this->assertStringStartsNotWith('user_bundle.', $dependency);
    }

    // No data was lost: the regular account keeps its value, and the staff
    // account's values are migrated onto the "user" bundle.
    $this->assertSame('regular value', $this->readValue('user__field_shared', 'field_shared_value', $regular->id()));
    $this->assertSame('staff shared value', $this->readValue('user__field_shared', 'field_shared_value', $staff->id()));
    $this->assertSame('staff only value', $this->readValue('user__field_staff_only', 'field_staff_only_value', $staff->id()));
  }

  /**
   * The entity_bundle:user block condition is removed on uninstall.
   */
  public function testUninstallRemovesBlockCondition(): void {
    $this->container->get('module_installer')->install(['user_bundle']);

    // A block that is only visible for the "user" account type.
    $block = $this->drupalPlaceBlock('system_powered_by_block', [
      'visibility' => [
        'entity_bundle:user' => [
          'id' => 'entity_bundle:user',
          'bundles' => ['user' => 'user'],
          'negate' => FALSE,
          'context_mapping' => ['user' => '@user.current_user_context:current_user'],
        ],
      ],
    ]);
    $this->assertArrayHasKey('entity_bundle:user', $block->getVisibility());

    // Uninstalling the module must remove the condition so nothing dangles.
    $this->container->get('module_installer')->uninstall(['user_bundle']);

    $reloaded = $this->container->get('entity_type.manager')->getStorage('block')->loadUnchanged($block->id());
    $this->assertArrayNotHasKey('entity_bundle:user', $reloaded->getVisibility());

    // The front page still renders: evaluating the block no longer throws on
    // the missing condition plugin.
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Reads a single field value straight from its dedicated table.
   */
  private function readValue(string $table, string $column, int $uid): ?string {
    $value = $this->container->get('database')->select($table, 'f')
      ->fields('f', [$column])
      ->condition('entity_id', $uid)
      ->execute()
      ->fetchField();
    return $value === FALSE ? NULL : $value;
  }

}
