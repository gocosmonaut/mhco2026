<?php

namespace Drupal\Tests\lscache\Unit;

use Drupal\Component\Render\MarkupInterface;
use Drupal\lscache\Element\Esi;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\lscache\Element\Esi
 * @group lscache
 */
class EsiTest extends UnitTestCase {

  /**
   * @covers ::preRenderEsi
   */
  public function testMissingCallbackEmitsMarkerComment(): void {
    // The missing-callback path emits a Markup-wrapped HTML comment so
    // dev-tools see the placeholder while Xss::filterAdmin would not
    // strip the result. This test does not need the container because
    // the early-return path never resolves a callback or builds a URL.
    $element = ['#callback' => NULL];
    $result = Esi::preRenderEsi($element);
    $this->assertArrayHasKey('#markup', $result);
    $this->assertInstanceOf(MarkupInterface::class, $result['#markup']);
    $this->assertStringContainsString('lscache_esi: missing #callback', (string) $result['#markup']);
  }

  /**
   * @covers ::preRenderEsi
   */
  public function testNonStringCallbackEmitsMarkerComment(): void {
    // The early-return path also catches the case where #callback is
    // present but not a string (e.g. an array slipped through).
    $element = ['#callback' => ['array', 'callback']];
    $result = Esi::preRenderEsi($element);
    $this->assertInstanceOf(MarkupInterface::class, $result['#markup']);
    $this->assertStringContainsString('missing #callback', (string) $result['#markup']);
  }

}
