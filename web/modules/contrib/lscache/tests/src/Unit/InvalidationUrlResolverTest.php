<?php

declare(strict_types=1);

namespace Drupal\Tests\lscache\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lscache_purger\Affinity\TagAffinityStore;
use Drupal\lscache_purger\Resolver\InvalidationUrlResolver;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \Drupal\lscache_purger\Resolver\InvalidationUrlResolver
 * @group lscache
 */
final class InvalidationUrlResolverTest extends UnitTestCase {

  /**
   * Builds a resolver with stubbed entity-type-manager + alias manager.
   *
   * @param array<string, array<int, \Drupal\Core\Entity\EntityInterface|null>> $entities
   *   Map of entity_type => [entity_id => loaded entity or NULL].
   *   NULL simulates a stale invalidation (deleted entity).
   * @param array<string, string> $aliases
   *   Map of canonical path => alias path. Paths not in the map
   *   return unchanged from the alias manager (the no-alias case).
   * @param array<string, string[]> $affinity
   *   Map of tag => recorded affinity URLs, stubbed on the store.
   * @param array<string, string[]> $static_map
   *   Map of tag => operator-pinned static URLs (static_url_map).
   */
  private function makeResolver(array $entities = [], array $aliases = [], array $affinity = [], array $static_map = []): InvalidationUrlResolver {
    $type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $type_manager->method('getStorage')->willReturnCallback(
      function (string $type) use ($entities): EntityStorageInterface {
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('load')->willReturnCallback(
          fn(int|string $id) => $entities[$type][(int) $id] ?? NULL,
        );
        return $storage;
      },
    );

    $alias_manager = $this->createMock(AliasManagerInterface::class);
    $alias_manager->method('getAliasByPath')->willReturnCallback(
      static fn(string $path) => $aliases[$path] ?? $path,
    );

    // Affinity store stub: maps tag => array of URLs. Tests that
    // do not care about the affinity union pass [], simulating a
    // never-recorded site.
    $affinity_store = $this->createMock(TagAffinityStore::class);
    $affinity_store->method('getUrlsForTag')->willReturnCallback(
      static fn(string $tag) => $affinity[$tag] ?? [],
    );

    // Config stub exposing the static_url_map. Tests that do not
    // exercise the static map pass [], simulating an unconfigured map.
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(
      static fn(string $key) => $key === 'static_url_map' ? $static_map : NULL,
    );
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('lscache_purger.settings')->willReturn($settings);

    return new InvalidationUrlResolver($type_manager, $alias_manager, $affinity_store, $config_factory, new NullLogger());
  }

  /**
   * Covers InvalidationUrlResolver::resolve().
   */
  public function testNodeWithAliasReturnsBoth(): void {
    $node = $this->createMock(EntityInterface::class);
    $resolver = $this->makeResolver(
      entities: ['node' => [38 => $node]],
      aliases: ['/node/38' => '/about-us'],
    );

    $result = $resolver->resolve('node:38');

    $this->assertFalse($result->isUnmappable);
    $this->assertSame(['/node/38', '/about-us'], $result->urls);
  }

  /**
   * Covers InvalidationUrlResolver::resolve().
   */
  public function testNodeWithoutAliasReturnsOnlyCanonical(): void {
    $node = $this->createMock(EntityInterface::class);
    $resolver = $this->makeResolver(entities: ['node' => [12 => $node]]);

    $result = $resolver->resolve('node:12');

    $this->assertFalse($result->isUnmappable);
    $this->assertSame(['/node/12'], $result->urls);
  }

  /**
   * Covers InvalidationUrlResolver::resolve().
   */
  public function testStaleNodeIsMappableButEmpty(): void {
    // Deleted entity: tag remains mappable in principle, but there is
    // nothing left to evict. The purger marks SUCCEEDED in this case.
    $resolver = $this->makeResolver(entities: ['node' => [99 => NULL]]);

    $result = $resolver->resolve('node:99');

    $this->assertFalse($result->isUnmappable);
    $this->assertSame([], $result->urls);
  }

  /**
   * Covers InvalidationUrlResolver::resolve().
   */
  public function testTaxonomyTermResolvesToTaxonomyPath(): void {
    $term = $this->createMock(EntityInterface::class);
    $resolver = $this->makeResolver(entities: ['taxonomy_term' => [5 => $term]]);

    $result = $resolver->resolve('taxonomy_term:5');

    $this->assertFalse($result->isUnmappable);
    $this->assertSame(['/taxonomy/term/5'], $result->urls);
  }

  /**
   * Covers InvalidationUrlResolver::resolve().
   */
  public function testMediaResolvesToMediaPath(): void {
    $media = $this->createMock(EntityInterface::class);
    $resolver = $this->makeResolver(entities: ['media' => [7 => $media]]);

    $result = $resolver->resolve('media:7');

    $this->assertSame(['/media/7'], $result->urls);
  }

  /**
   * Covers InvalidationUrlResolver::resolve().
   */
  public function testUserResolvesToUserPath(): void {
    $user = $this->createMock(EntityInterface::class);
    $resolver = $this->makeResolver(entities: ['user' => [3 => $user]]);

    $result = $resolver->resolve('user:3');

    $this->assertSame(['/user/3'], $result->urls);
  }

  /**
   * Covers InvalidationUrlResolver::resolve().
   */
  public function testFileReturnsEmptyButMappable(): void {
    // Binary files are not cached by LSWS as page responses; the
    // resolver returns empty URLs so the purger no-ops on these tags
    // rather than treating them as unmappable.
    $resolver = $this->makeResolver();

    $result = $resolver->resolve('file:10');

    $this->assertFalse($result->isUnmappable);
    $this->assertSame([], $result->urls);
  }

  /**
   * Covers InvalidationUrlResolver::resolve().
   */
  public function testBlockContentIsUnmappable(): void {
    $resolver = $this->makeResolver();

    $result = $resolver->resolve('block_content:10');

    $this->assertTrue($result->isUnmappable);
    $this->assertSame([], $result->urls);
  }

  /**
   * Covers InvalidationUrlResolver::resolve().
   */
  public function testConfigTagIsUnmappable(): void {
    $resolver = $this->makeResolver();

    $this->assertTrue($resolver->resolve('config:system.site')->isUnmappable);
    $this->assertTrue($resolver->resolve('config:block.block.foo')->isUnmappable);
  }

  /**
   * Covers InvalidationUrlResolver::resolve().
   */
  public function testListAndViewSuffixesAreUnmappable(): void {
    $resolver = $this->makeResolver();

    $this->assertTrue($resolver->resolve('node_list')->isUnmappable);
    $this->assertTrue($resolver->resolve('user_view')->isUnmappable);
  }

  /**
   * Covers InvalidationUrlResolver::resolve().
   */
  public function testHttpResponseAndRenderedAreUnmappable(): void {
    $resolver = $this->makeResolver();

    $this->assertTrue($resolver->resolve('http_response')->isUnmappable);
    $this->assertTrue($resolver->resolve('rendered')->isUnmappable);
  }

  /**
   * Covers InvalidationUrlResolver::resolve().
   */
  public function testWebformAndEsiSuffixesAreUnmappable(): void {
    $resolver = $this->makeResolver();

    $this->assertTrue($resolver->resolve('webform:contact')->isUnmappable);
    $this->assertTrue($resolver->resolve('fragment_42_esi')->isUnmappable);
  }

  /**
   * Covers InvalidationUrlResolver::resolve().
   */
  public function testEmptyExpressionIsUnmappable(): void {
    $resolver = $this->makeResolver();

    $this->assertTrue($resolver->resolve('')->isUnmappable);
    $this->assertTrue($resolver->resolve('   ')->isUnmappable);
  }

  /**
   * Covers InvalidationUrlResolver::resolve().
   */
  public function testNonNumericEntityIdIsUnmappable(): void {
    $resolver = $this->makeResolver();

    // Defensive: malformed tag from a contrib emitter should not
    // crash the resolver or pollute the URL list.
    $this->assertTrue($resolver->resolve('node:abc')->isUnmappable);
    $this->assertTrue($resolver->resolve('node:')->isUnmappable);
  }

  /**
   * Covers InvalidationUrlResolver::resolve().
   */
  public function testUnknownEntityTypeIsUnmappable(): void {
    $resolver = $this->makeResolver();

    $this->assertTrue($resolver->resolve('commerce_product:5')->isUnmappable);
  }

  /**
   * Affinity URLs union with the canonical lookup.
   *
   * Affinity store contributes URLs alongside canonical lookup. A
   * node:N invalidation evicts the node's canonical URL AND any
   * recently-cached pages whose response carried the node:N tag
   * (Views listings, homepage blocks, related-content widgets).
   */
  public function testAffinityUrlsUnionWithCanonicalForMappableTag(): void {
    $node = $this->createMock(EntityInterface::class);
    $resolver = $this->makeResolver(
      entities: ['node' => [38 => $node]],
      affinity: ['node:38' => ['/patch-notes', '/home']],
    );

    $result = $resolver->resolve('node:38');

    $this->assertFalse($result->isUnmappable);
    $this->assertSame(['/node/38', '/patch-notes', '/home'], $result->urls);
  }

  /**
   * Affinity URLs rescue an unmappable aggregate tag.
   *
   * Affinity store rescues otherwise-unmappable aggregate tags. The
   * portal-master view-eviction case: node_list invalidation cannot
   * resolve via canonical lookup, but the affinity store has
   * recorded that /patch-notes and /blog recently emitted node_list,
   * so URL-strategy mode evicts both.
   */
  public function testAffinityUrlsRescueUnmappableAggregateTag(): void {
    $resolver = $this->makeResolver(
      affinity: ['node_list' => ['/patch-notes', '/blog']],
    );

    $result = $resolver->resolve('node_list');

    $this->assertFalse($result->isUnmappable);
    $this->assertSame(['/patch-notes', '/blog'], $result->urls);
  }

  /**
   * Aggregate tag with no affinity stays unmappable.
   *
   * Aggregate tag + no affinity = isUnmappable. Cold-start state on
   * low-traffic sites where the listing has not been hit since the
   * affinity table was created. Operators can supplement via the
   * optional static_url_map config.
   */
  public function testAggregateTagWithEmptyAffinityStaysUnmappable(): void {
    $resolver = $this->makeResolver();

    $result = $resolver->resolve('node_list');

    $this->assertTrue($result->isUnmappable);
    $this->assertSame([], $result->urls);
  }

  /**
   * Block content resolves via affinity to its pages.
   *
   * Block_content has no canonical URL but DOES surface on a known
   * set of pages once those pages are visited and cached. Affinity
   * is exactly the right resolution path for it.
   */
  public function testBlockContentWithAffinityResolves(): void {
    $resolver = $this->makeResolver(
      affinity: ['block_content:10' => ['/about-us', '/services']],
    );

    $result = $resolver->resolve('block_content:10');

    $this->assertFalse($result->isUnmappable);
    $this->assertSame(['/about-us', '/services'], $result->urls);
  }

  /**
   * Static map resolves an aggregate tag at cold-start.
   *
   * Static map rescues an aggregate tag with NO affinity (cold-start).
   * The portal-stage case: publishing a new patch_note fires node_list,
   * and the operator has pinned node_list -> /patch-notes so the
   * listing evicts immediately, before affinity has observed the page.
   */
  public function testStaticMapResolvesAggregateTagColdStart(): void {
    $resolver = $this->makeResolver(
      static_map: ['node_list' => ['/patch-notes', '/blog']],
    );

    $result = $resolver->resolve('node_list');

    $this->assertFalse($result->isUnmappable);
    $this->assertSame(['/patch-notes', '/blog'], $result->urls);
  }

  /**
   * Static map and affinity union and dedupe.
   *
   * Static map + affinity union and dedupe. A pinned listing URL that
   * affinity has ALSO observed appears once; affinity-only URLs append
   * after the static set.
   */
  public function testStaticMapUnionsAndDedupesWithAffinity(): void {
    $resolver = $this->makeResolver(
      affinity: ['node_list' => ['/patch-notes', '/news']],
      static_map: ['node_list' => ['/patch-notes', '/blog']],
    );

    $result = $resolver->resolve('node_list');

    $this->assertFalse($result->isUnmappable);
    // Static first (/patch-notes, /blog), then affinity-only (/news);
    // /patch-notes is not duplicated.
    $this->assertSame(['/patch-notes', '/blog', '/news'], $result->urls);
  }

  /**
   * Entity tag unions canonical + static + affinity in that order.
   */
  public function testEntityTagUnionsAllThreeSources(): void {
    $node = $this->createMock(EntityInterface::class);
    $resolver = $this->makeResolver(
      entities: ['node' => [38 => $node]],
      aliases: ['/node/38' => '/about-us'],
      affinity: ['node:38' => ['/home']],
      static_map: ['node:38' => ['/pinned']],
    );

    $result = $resolver->resolve('node:38');

    $this->assertFalse($result->isUnmappable);
    $this->assertSame(['/node/38', '/about-us', '/pinned', '/home'], $result->urls);
  }

}
