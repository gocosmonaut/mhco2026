<?php

namespace Drupal\Tests\lscache\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests the helper functions defined in lscache.install.
 *
 * The .install file is not auto-loaded by Drupal except during
 * hook_update invocation, so we require_once it explicitly. The
 * helper under test (`_lscache_flatten_install_defaults`) is a pure
 * function with no Drupal dependencies, so unit-test scope is
 * sufficient.
 *
 * @group lscache
 */
class InstallHelperTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/../../../lscache.install';
  }

  /**
   * Top-level scalar values flatten to themselves.
   */
  public function testFlatScalarsPassThrough(): void {
    $input = [
      'enabled' => FALSE,
      'default_ttl' => 3600,
      'tag_prefix' => '',
    ];
    $this->assertSame($input, _lscache_flatten_install_defaults($input));
  }

  /**
   * Nested mappings flatten to dotted-path keys.
   */
  public function testNestedMappingFlattened(): void {
    $input = [
      'private_cache' => [
        'enabled' => FALSE,
        'ttl' => 600,
      ],
    ];
    $this->assertSame(
      [
        'private_cache.enabled' => FALSE,
        'private_cache.ttl' => 600,
      ],
      _lscache_flatten_install_defaults($input),
    );
  }

  /**
   * Numeric-indexed lists are kept whole as leaf values.
   *
   * Sequences round-trip through Drupal's Config::set() as the entire
   * value, so the flattener must not recurse into them.
   */
  public function testListsKeptWholeAsLeafValues(): void {
    $input = [
      'private_cache' => [
        'contexts' => ['user', 'session'],
      ],
    ];
    $this->assertSame(
      [
        'private_cache.contexts' => ['user', 'session'],
      ],
      _lscache_flatten_install_defaults($input),
    );
  }

  /**
   * Empty arrays are preserved as leaf values.
   *
   * The install YAML uses `vary_cookies: {  }` (empty mapping notation)
   * to declare the default; the flattener must store the empty array
   * as-is rather than discarding the key.
   */
  public function testEmptyArrayPreservedAsLeaf(): void {
    $input = ['vary_cookies' => []];
    $this->assertSame(['vary_cookies' => []], _lscache_flatten_install_defaults($input));
  }

  /**
   * Deeply-nested mappings recurse correctly.
   */
  public function testDeepNestingFlattenedFully(): void {
    $input = [
      'a' => [
        'b' => [
          'c' => 'leaf',
        ],
      ],
    ];
    $this->assertSame(
      ['a.b.c' => 'leaf'],
      _lscache_flatten_install_defaults($input),
    );
  }

  /**
   * Mixed mappings, lists, and scalars at the same level all flatten.
   */
  public function testMixedShapeFlattened(): void {
    $input = [
      'enabled' => TRUE,
      'private_cache' => [
        'enabled' => FALSE,
        'contexts' => ['user'],
      ],
      'vary_cookies' => [],
    ];
    $expected = [
      'enabled' => TRUE,
      'private_cache.enabled' => FALSE,
      'private_cache.contexts' => ['user'],
      'vary_cookies' => [],
    ];
    $this->assertSame($expected, _lscache_flatten_install_defaults($input));
  }

}
