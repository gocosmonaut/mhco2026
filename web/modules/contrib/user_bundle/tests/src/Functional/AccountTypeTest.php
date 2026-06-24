<?php

namespace Drupal\Tests\user_bundle\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user_bundle\Entity\UserType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests managing account types through the admin UI.
 *
 * @group user_bundle
 */
#[RunTestsInSeparateProcesses]
class AccountTypeTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['user_bundle'];

  /**
   * Account types can be added, edited, and deleted through the admin UI.
   */
  public function testAccountTypeCrud(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer account types']));

    // The collection page lists the default "user" account type.
    $this->drupalGet('admin/config/people/types');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Account types');

    // Add an account type.
    $this->drupalGet('admin/config/people/types/add');
    $this->submitForm([
      'label' => 'Editor',
      'id' => 'editor',
      'description' => 'Editorial accounts.',
    ], 'Save account type');
    $this->assertSession()->pageTextContains('The account type Editor has been added.');
    $type = UserType::load('editor');
    $this->assertNotNull($type);
    $this->assertSame('Editorial accounts.', $type->getDescription());

    // The new account type is a bundle of the user entity.
    $bundle_info = $this->container->get('entity_type.bundle.info');
    $bundle_info->clearCachedBundles();
    $this->assertArrayHasKey('editor', $bundle_info->getBundleInfo('user'));

    // Edit the account type.
    $this->drupalGet('admin/config/people/types/editor');
    $this->submitForm(['label' => 'Content editor'], 'Save account type');
    $this->assertSession()->pageTextContains('The account type Content editor has been updated.');
    $this->assertSame('Content editor', UserType::load('editor')->label());

    // Delete the account type.
    $this->drupalGet('admin/config/people/types/editor/delete');
    $this->submitForm([], 'Delete');
    $this->assertNull(UserType::load('editor'));
  }

  /**
   * The account type pages require the administer permission.
   */
  public function testAccessControl(): void {
    $this->drupalLogin($this->drupalCreateUser());
    $this->drupalGet('admin/config/people/types');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('admin/config/people/types/add');
    $this->assertSession()->statusCodeEquals(403);
  }

}
