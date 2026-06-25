<?php

namespace Drupal\Tests\lscache\Unit\Fixture;

/**
 * Test fixture: a class without TrustedCallbackInterface.
 *
 * Used as a negative case in LscacheFragmentControllerTest's
 * trusted-callable enforcement tests. The class deliberately omits
 * the marker interface so the resolver must reject it even though
 * the class and method exist and are callable in the language-level
 * sense.
 */
class UntrustedCallbackStub {

  /**
   * A reachable render method that the controller must refuse to call.
   */
  public function render(): array {
    return ['#markup' => 'should-not-be-reachable'];
  }

}
