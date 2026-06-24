<?php

namespace Drupal\Tests\user_bundle\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user_bundle\Entity\UserType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that account types act as bundles of the user entity.
 *
 * @group user_bundle
 */
#[RunTestsInSeparateProcesses]
class UserTypeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['user']);
    $this->container->get('module_installer')->install(['user_bundle']);
  }

  /**
   * The user entity type is bundleable once the module is installed.
   */
  public function testUserEntityIsBundleable(): void {
    $entity_type = $this->container->get('entity_type.manager')->getDefinition('user');
    $this->assertSame('type', $entity_type->getKey('bundle'));
    $this->assertSame('user_type', $entity_type->getBundleEntityType());
  }

  /**
   * Accounts created without a type fall back to the default "user" bundle.
   */
  public function testDefaultBundle(): void {
    $account = User::create(['name' => 'no_type']);
    $account->save();
    $this->assertSame('user', $account->bundle());
    $this->assertSame('user', $account->getType());
  }

  /**
   * Accounts can be created in a custom account type.
   */
  public function testCustomBundle(): void {
    UserType::create(['id' => 'staff', 'label' => 'Staff'])->save();
    $account = User::create(['name' => 'a_staffer', 'type' => 'staff']);
    $account->save();
    $this->assertSame('staff', $account->bundle());
    $this->assertSame('staff', $account->getType());
  }

  /**
   * The storage can move every account of one type to another.
   */
  public function testUpdateType(): void {
    UserType::create(['id' => 'staff', 'label' => 'Staff'])->save();
    UserType::create(['id' => 'editor', 'label' => 'Editor'])->save();

    foreach (['one', 'two'] as $name) {
      User::create(['name' => $name, 'type' => 'staff'])->save();
    }

    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    $updated = $storage->updateType('staff', 'editor');
    $this->assertSame(2, (int) $updated);

    $editors = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'editor')->count()->execute();
    $staff = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'staff')->count()->execute();
    $this->assertSame(2, (int) $editors);
    $this->assertSame(0, (int) $staff);
  }

  /**
   * Account types expose their description and locked state.
   */
  public function testAccountTypeProperties(): void {
    $type = UserType::create([
      'id' => 'staff',
      'label' => 'Staff',
      'description' => 'Internal staff accounts.',
    ]);
    $type->save();

    $this->assertSame('Internal staff accounts.', $type->getDescription());
    $this->assertFalse($type->isLocked());

    $this->container->get('state')->set('user.type.locked', ['staff' => TRUE]);
    $this->assertTrue($type->isLocked());
  }

}
