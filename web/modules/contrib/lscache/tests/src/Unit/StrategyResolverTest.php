<?php

namespace Drupal\Tests\lscache\Unit;

use Drupal\lscache_purger\StrategyResolver;
use Drupal\Tests\UnitTestCase;

/**
 * Tests strategy resolution, including the safe `auto` default.
 *
 * StrategyResolver is the single definition shared by the runtime purger
 * and the status report row. The behaviour that matters most: an unprobed
 * `auto` site resolves to the always-works URL strategy, never to tag, so
 * a fresh install on a build that silently ignores tag-PURGE still evicts.
 *
 * @coversDefaultClass \Drupal\lscache_purger\StrategyResolver
 * @group lscache
 */
class StrategyResolverTest extends UnitTestCase {

  /**
   * Explicit strategies are returned unchanged, whatever the probe says.
   *
   * @dataProvider explicitProvider
   */
  public function testExplicitStrategyIsHonoured(string $configured, mixed $probe): void {
    $this->assertSame($configured, StrategyResolver::resolve($configured, $probe));
  }

  /**
   * Explicit tag/url values paired with assorted probe states.
   *
   * @return array<string, array{string, mixed}>
   *   Test cases keyed by description.
   */
  public static function explicitProvider(): array {
    return [
      'tag, unprobed' => ['tag', NULL],
      'tag, probed ineffective' => ['tag', FALSE],
      'url, probed effective' => ['url', TRUE],
      'url, unprobed' => ['url', NULL],
    ];
  }

  /**
   * Strategy `auto` selects tag only when a probe has confirmed it works.
   *
   * @dataProvider autoProvider
   */
  public function testAutoIsSafeByDefault(mixed $probe, string $expected): void {
    $this->assertSame($expected, StrategyResolver::resolve('auto', $probe));
  }

  /**
   * Probe states mapped to the strategy `auto` should resolve to.
   *
   * @return array<string, array{mixed, string}>
   *   Test cases keyed by description.
   */
  public static function autoProvider(): array {
    return [
      // The fix: never probed must resolve to url, not tag.
      'never probed -> url' => [NULL, 'url'],
      'probed effective -> tag' => [TRUE, 'tag'],
      'probed ineffective -> url' => [FALSE, 'url'],
      // Drifted falsy state values must not slip onto the tag strategy.
      'drifted int 0 -> url' => [0, 'url'],
      'drifted string 0 -> url' => ['0', 'url'],
      'drifted string false -> url' => ['false', 'url'],
      // A truthy non-bool reads as effective.
      'drifted int 1 -> tag' => [1, 'tag'],
    ];
  }

  /**
   * An unrecognised configured value is treated as `auto` (safe).
   */
  public function testUnknownStrategyTreatedAsAuto(): void {
    $this->assertSame('url', StrategyResolver::resolve('bogus', NULL));
    $this->assertSame('tag', StrategyResolver::resolve('bogus', TRUE));
  }

}
