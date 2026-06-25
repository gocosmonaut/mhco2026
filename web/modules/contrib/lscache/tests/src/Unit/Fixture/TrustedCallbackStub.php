<?php

namespace Drupal\Tests\lscache\Unit\Fixture;

use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Test fixture: a class implementing TrustedCallbackInterface.
 *
 * Used as a positive case in LscacheFragmentControllerTest's
 * trusted-callable enforcement tests. The class declares both an
 * instance method and a static method as trusted callbacks so the
 * tests can exercise both `service:method` and `Class::staticMethod`
 * callable shapes through the fragment controller's resolver.
 */
class TrustedCallbackStub implements TrustedCallbackInterface {

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['render', 'staticRender'];
  }

  /**
   * Instance-method render: returns a minimal render array.
   */
  public function render(): array {
    return ['#markup' => 'ok'];
  }

  /**
   * Static render: returns a minimal render array.
   */
  public static function staticRender(): array {
    return ['#markup' => 'ok-static'];
  }

}
