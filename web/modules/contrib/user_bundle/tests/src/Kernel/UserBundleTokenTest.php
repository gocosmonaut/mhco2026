<?php

namespace Drupal\Tests\user_bundle\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user_bundle\Entity\UserType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the account type tokens added for the user token type.
 *
 * @group user_bundle
 */
#[RunTestsInSeparateProcesses]
class UserBundleTokenTest extends KernelTestBase {

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
   * The account type tokens are registered for the user token type.
   */
  public function testTokenInfo(): void {
    $info = ['types' => [], 'tokens' => []];
    $this->container->get('module_handler')->alter('token_info', $info);
    $this->assertArrayHasKey('type', $info['tokens']['user']);
    $this->assertArrayHasKey('type-name', $info['tokens']['user']);
  }

  /**
   * The account type tokens resolve to the account's type and label.
   */
  public function testTokenReplacement(): void {
    UserType::create(['id' => 'staff', 'label' => 'Staff'])->save();
    $account = User::create(['name' => 'a_staffer', 'type' => 'staff']);
    $account->save();

    $token = $this->container->get('token');
    $data = ['user' => $account];
    $this->assertSame('staff', $token->replace('[user:type]', $data));
    $this->assertSame('Staff', $token->replace('[user:type-name]', $data));
  }

}
