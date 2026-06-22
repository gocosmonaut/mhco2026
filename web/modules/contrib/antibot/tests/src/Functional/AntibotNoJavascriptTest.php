<?php

namespace Drupal\Tests\antibot\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests Antibot when JavaScript is disabled.
 *
 * @group antibot
 */
class AntibotNoJavascriptTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['antibot'];

  /**
   * Default theme.
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests antibot when JavaScript is disabled.
   *
   * BrowserTestBase tests are, by design, non-JavaScript tests so we're having
   * the perspective of bot trying to post a form.
   */
  public function testNoJavaScript() {
    $this->drupalGet('/user/password');
    $this->submitForm([
      'name' => $this->randomMachineName(),
    ], 'Submit');

    // Check if we reached the antibot closed road when the form is posted by a
    // bot even having JavaScript capabilities.
    $this->assertSession()->addressEquals('/antibot');
    $this->assertSession()->pageTextContains('Submission failed');
    $this->assertSession()->pageTextContains('The Antibot form protection system has detected bot-like behavior and blocked your form submission. This protection is in place to attempt to prevent automated submissions made on forms by bots. Please return to the page that you came from and try to submit again. Also, make sure you have JavaScript enabled on your browser before attempting to submit the form again.');
  }

}
