<?php

namespace Drupal\Tests\lscache\Unit;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\lscache\TagHeaderBuilder;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\lscache\TagHeaderBuilder
 * @group lscache
 */
class TagHeaderBuilderTest extends UnitTestCase {

  /**
   * Creates a TagHeaderBuilder backed by a stubbed config factory.
   *
   * @param string $prefix
   *   The tag_prefix config value. Empty string triggers auto-prefix.
   * @param bool $filter
   *   The tag_filter config value (filtering enabled/disabled).
   */
  private function makeBuilder(string $prefix = '', bool $filter = TRUE): TagHeaderBuilder {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnMap([
      ['tag_prefix', $prefix],
      ['tag_filter', $filter],
    ]);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('lscache.settings')->willReturn($config);

    return new TagHeaderBuilder($factory);
  }

  /**
   * Builds a cacheable response with the given cache tags attached.
   *
   * @param string[] $tags
   *   The cache tags to attach to the response's cacheable metadata.
   */
  private function responseWithTags(array $tags): CacheableResponse {
    $response = new CacheableResponse();
    $response->addCacheableDependency((new CacheableMetadata())->setCacheTags($tags));
    return $response;
  }

  /**
   * @covers ::build
   */
  public function testEmptyTagsReturnsEmptyString(): void {
    $builder = $this->makeBuilder('x', FALSE);
    $this->assertSame('', $builder->build($this->responseWithTags([])));
  }

  /**
   * @covers ::build
   */
  public function testExplicitPrefixWithoutFilterPreservesAllTags(): void {
    $builder = $this->makeBuilder('site-a:', FALSE);
    $this->assertSame(
      'site-a:node:1,site-a:node_list',
      $builder->build($this->responseWithTags(['node:1', 'node_list'])),
    );
  }

  /**
   * @covers ::build
   */
  public function testFilterDropsExactMatches(): void {
    $builder = $this->makeBuilder('p:', TRUE);
    $this->assertSame(
      'p:node:1',
      $builder->build($this->responseWithTags(['node:1', 'http_response', 'rendered'])),
    );
  }

  /**
   * @covers ::build
   */
  public function testFilterDropsSubstringMatches(): void {
    $builder = $this->makeBuilder('p:', TRUE);
    $result = $builder->build($this->responseWithTags([
      'node:5',
      'config:block.block.header',
      'user:1',
      'taxonomy_term:3',
      'some_view',
      'entity_list',
      'webform:contact',
    ]));
    // node:5 and webform:contact survive; the rest match filter patterns.
    $this->assertSame('p:node:5,p:webform:contact', $result);
  }

  /**
   * @covers ::build
   */
  public function testFilteringAllTagsReturnsEmptyString(): void {
    $builder = $this->makeBuilder('p:', TRUE);
    $this->assertSame(
      '',
      $builder->build($this->responseWithTags(['http_response', 'rendered', 'config:system.site'])),
    );
  }

  /**
   * @covers ::build
   */
  public function testCommasInTagsAreStrippedDefensively(): void {
    $builder = $this->makeBuilder('', FALSE);
    $result = $builder->build($this->responseWithTags(['weird,tag']));
    // The auto-prefix is deterministic per-install (4 chars), so the tag
    // portion is what we assert on.
    $this->assertStringEndsWith('weirdtag', $result);
  }

  /**
   * @covers ::getPrefix
   */
  public function testExplicitPrefixIsReturnedVerbatim(): void {
    $builder = $this->makeBuilder('site-a:');
    $this->assertSame('site-a:', $builder->getPrefix());
  }

  /**
   * @covers ::getPrefix
   */
  public function testAutoPrefixIsFourHexChars(): void {
    $builder = $this->makeBuilder('');
    $prefix = $builder->getPrefix();
    $this->assertSame(4, strlen($prefix));
    $this->assertMatchesRegularExpression('/^[0-9a-f]{4}$/', $prefix);
  }

  /**
   * @covers ::getPrefix
   */
  public function testAutoPrefixIsStableAcrossCalls(): void {
    $builder = $this->makeBuilder('');
    $this->assertSame($builder->getPrefix(), $builder->getPrefix());
  }

  /**
   * @covers ::build
   */
  public function testAutoPrefixApplied(): void {
    $builder = $this->makeBuilder('', FALSE);
    $prefix = $builder->getPrefix();
    $this->assertSame(
      $prefix . 'node:1',
      $builder->build($this->responseWithTags(['node:1'])),
    );
  }

}
