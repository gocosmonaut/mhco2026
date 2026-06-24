<?php

namespace Drupal\Tests\user_bundle\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\user_bundle\Entity\UserType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests choosing account types for user registration.
 *
 * Covers the default registration account type used by /user/register and the
 * per-type registration forms at /user/register/{user_type} that anonymous
 * users can use for the account types opened to registration.
 *
 * @group user_bundle
 */
#[RunTestsInSeparateProcesses]
class RegistrationAccountTypeTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['user_bundle'];

  /**
   * The account settings form stores both registration account type settings.
   */
  public function testRegistrationAccountTypeSetting(): void {
    UserType::create(['id' => 'member', 'label' => 'Member'])->save();

    $this->drupalLogin($this->drupalCreateUser([
      'administer account settings',
      'administer account types',
    ]));

    // Both the default-type selector and the open-types checkboxes are present.
    $this->drupalGet('admin/config/people/accounts');
    $this->assertSession()->fieldExists('user_registration_user_type');
    $this->assertSession()->fieldExists('self_registration_types[member]');
    // The default "user" bundle is not offered for per-type registration.
    $this->assertSession()->fieldNotExists('self_registration_types[user]');

    // Saving stores the default type and the open-for-registration types.
    $this->submitForm([
      'user_registration_user_type' => 'member',
      'self_registration_types[member]' => 'member',
    ], 'Save configuration');
    $settings = $this->config('user_bundle.settings');
    $this->assertSame('member', $settings->get('registration_user_type'));
    $this->assertSame(['member'], $settings->get('self_registration_types'));
  }

  /**
   * The plain /user/register form uses the configured default account type.
   */
  public function testRegistrationUsesConfiguredType(): void {
    UserType::create(['id' => 'member', 'label' => 'Member'])->save();
    $this->config('user_bundle.settings')->set('registration_user_type', 'member')->save();
    $this->allowVisitorRegistration();

    $this->registerAt('user/register', 'newcomer');
    $this->assertSame('member', $this->loadAccount('newcomer')->bundle());
  }

  /**
   * Registering at /user/register/{user_type} creates that account type.
   */
  public function testBundleRegistrationCreatesAccountOfType(): void {
    UserType::create(['id' => 'candidate', 'label' => 'Candidate'])->save();
    $this->config('user_bundle.settings')->set('self_registration_types', ['candidate'])->save();
    $this->allowVisitorRegistration();

    $this->drupalGet('user/register/candidate');
    $this->assertSession()->statusCodeEquals(200);
    $this->registerAt('user/register/candidate', 'applicant');
    $this->assertSame('candidate', $this->loadAccount('applicant')->bundle());
  }

  /**
   * Per-type registration is limited to the opened account types.
   */
  public function testBundleRegistrationAccess(): void {
    UserType::create(['id' => 'candidate', 'label' => 'Candidate'])->save();
    UserType::create(['id' => 'internal', 'label' => 'Internal'])->save();
    $this->config('user_bundle.settings')->set('self_registration_types', ['candidate'])->save();
    $this->allowVisitorRegistration();

    // An opened account type is reachable. Account types that exist but aren't
    // open, and account types that don't exist at all, both return 404 so the
    // route can't be used to tell which account types exist.
    $this->drupalGet('user/register/candidate');
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet('user/register/internal');
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet('user/register/missing');
    $this->assertSession()->statusCodeEquals(404);

    // The default "user" bundle is never reachable through the per-type route,
    // even if it is listed as open.
    $this->config('user_bundle.settings')->set('self_registration_types', ['candidate', 'user'])->save();
    $this->drupalGet('user/register/user');
    $this->assertSession()->statusCodeEquals(404);

    // With registration limited to administrators, even an opened type is
    // forbidden for anonymous users.
    $this->config('user.settings')->set('register', UserInterface::REGISTER_ADMINISTRATORS_ONLY)->save();
    $this->drupalGet('user/register/candidate');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Lets visitors register without administrator approval or email check.
   */
  private function allowVisitorRegistration(): void {
    $this->config('user.settings')
      ->set('register', UserInterface::REGISTER_VISITORS)
      ->set('verify_mail', FALSE)
      ->save();
  }

  /**
   * Submits the registration form at the given path for a new account name.
   */
  private function registerAt(string $path, string $name): void {
    $password = $this->randomString();
    $this->drupalGet($path);
    $this->submitForm([
      'name' => $name,
      'mail' => $name . '@example.com',
      'pass[pass1]' => $password,
      'pass[pass2]' => $password,
    ], 'Create new account');
  }

  /**
   * Loads the account with the given name.
   */
  private function loadAccount(string $name): User {
    $accounts = $this->container->get('entity_type.manager')->getStorage('user')
      ->loadByProperties(['name' => $name]);
    $account = reset($accounts);
    $this->assertInstanceOf(User::class, $account);
    return $account;
  }

}
