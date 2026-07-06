<?php

namespace Drupal\Tests\lscache\Unit;

use Drupal\lscache_purger\PurgeHost;
use Drupal\Tests\UnitTestCase;

/**
 * Tests loopback detection for configured purge hosts.
 *
 * PurgeHost::isLoopback() is the single definition of "loopback purge
 * host" shared by the purger (TLS verify skip), the settings-form test
 * button, and the scheme-mismatch status report row. It is a pure
 * function over the URL string, so unit-test scope is sufficient.
 *
 * @coversDefaultClass \Drupal\lscache_purger\PurgeHost
 * @group lscache
 */
class PurgeHostTest extends UnitTestCase {

  /**
   * Loopback hosts are recognised across scheme, port, and trailing path.
   *
   * @dataProvider loopbackProvider
   */
  public function testLoopbackHostsAreRecognised(string $url): void {
    $this->assertTrue(PurgeHost::isLoopback($url), $url);
  }

  /**
   * Real destinations are not treated as loopback.
   *
   * @dataProvider nonLoopbackProvider
   */
  public function testNonLoopbackHostsAreRejected(string $url): void {
    $this->assertFalse(PurgeHost::isLoopback($url), $url);
  }

  /**
   * URLs whose host resolves to the loopback interface.
   *
   * @return array<string, array{string}>
   *   Test cases keyed by description.
   */
  public static function loopbackProvider(): array {
    return [
      'http localhost' => ['http://localhost/'],
      'https localhost' => ['https://localhost'],
      'http 127.0.0.1' => ['http://127.0.0.1/'],
      'https 127.0.0.1' => ['https://127.0.0.1'],
      '127.0.0.1 with port' => ['http://127.0.0.1:8080/'],
      'localhost with path' => ['http://localhost/purge'],
      'bracketed ::1' => ['http://[::1]/'],
    ];
  }

  /**
   * URLs whose host is a real, non-loopback destination.
   *
   * @return array<string, array{string}>
   *   Test cases keyed by description.
   */
  public static function nonLoopbackProvider(): array {
    return [
      'public domain' => ['https://www.example.com/'],
      'internal hostname' => ['http://lsws.internal/'],
      'private network address' => ['http://192.168.1.10/'],
      'localhost as a substring' => ['http://localhost.example.com/'],
      'empty string' => [''],
      'bare host without scheme' => ['127.0.0.1'],
    ];
  }

}
