<?php

declare(strict_types=1);

namespace Drupal\Tests\lscache\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\lscache\TagHeaderBuilder;
use Drupal\lscache_purger\Plugin\Purge\Purger\LscachePurger;
use Drupal\lscache_purger\Resolver\InvalidationUrlResolver;
use Drupal\lscache_purger\Resolver\UrlResolverResult;
use Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the purge-strategy branch in LscachePurger::invalidate().
 *
 * The 1.3.x LSWS 6.3.4 diagnosis surfaced that tag-based PURGE is
 * not reliably honoured by all LSWS builds; the URL strategy here is
 * the field-tested workaround. These tests cover the dispatch logic
 * plus the per-strategy outgoing request shape.
 *
 * @coversDefaultClass \Drupal\lscache_purger\Plugin\Purge\Purger\LscachePurger
 * @group lscache
 */
final class LscachePurgerStrategyTest extends UnitTestCase {

  /**
   * Builds a purger with explicit strategy + url resolver behaviour.
   *
   * Wires the static container with messenger + translation services
   * so MessengerTrait fallback resolves cleanly, mirroring the setup
   * in LscachePurgerTest.
   *
   * @param string $strategy
   *   The `purge_strategy` config value: tag|url|auto.
   * @param string $unmappable_fallback
   *   The `unmappable_fallback` config value: none|purge_all.
   * @param array<int, array<string, mixed>> $history
   *   Reference container for the Guzzle history middleware so tests
   *   can inspect the outgoing PURGE requests.
   * @param int|bool|null $tag_purge_effective
   *   The state value for `lscache_purger.tag_purge_effective`. Only
   *   read by the `auto` strategy; pass NULL to simulate the never-
   *   probed state.
   * @param array<string, \Drupal\lscache_purger\Resolver\UrlResolverResult> $resolver_results
   *   Map of tag expression => resolver result.
   */
  private function makePurger(string $strategy, string $unmappable_fallback, array &$history, int|bool|null $tag_purge_effective = NULL, array $resolver_results = []): LscachePurger {
    $purger_config = $this->createMock(Config::class);
    $purger_config->method('get')->willReturnMap([
      ['purge_host', 'http://localhost/'],
      ['purge_host_header', 'www.example.com'],
      ['timeout', 4],
      ['purge_strategy', $strategy],
      ['unmappable_fallback', $unmappable_fallback],
    ]);

    $tag_header_config = $this->createMock(Config::class);
    $tag_header_config->method('get')->willReturnMap([
      ['tag_prefix', 'p:'],
      ['tag_filter', FALSE],
    ]);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnCallback(
      fn(string $name) => match ($name) {
        'lscache_purger.settings' => $purger_config,
        'lscache.settings' => $tag_header_config,
      },
    );

    $container = new ContainerBuilder();
    $container->set('messenger', $this->createMock(MessengerInterface::class));
    $container->set('string_translation', $this->createMock(TranslationInterface::class));
    \Drupal::setContainer($container);

    $mock_handler = new MockHandler(array_fill(0, 10, new Response(200)));
    $stack = HandlerStack::create($mock_handler);
    $stack->push(Middleware::history($history));
    $http_client = new Client(['handler' => $stack]);

    $resolver = $this->createMock(InvalidationUrlResolver::class);
    $resolver->method('resolve')->willReturnCallback(
      static fn(string $expr) => $resolver_results[$expr] ?? new UrlResolverResult([], TRUE, 'no stub'),
    );

    $state = $this->createMock(StateInterface::class);
    $state->method('get')->with('lscache_purger.tag_purge_effective')->willReturn($tag_purge_effective);

    $GLOBALS['base_url'] = '';

    return new LscachePurger(
      configuration: ['id' => 'lscache_test_instance'],
      plugin_id: 'lscache',
      plugin_definition: ['id' => 'lscache'],
      httpClient: $http_client,
      configFactory: $factory,
      tagHeaderBuilder: new TagHeaderBuilder($factory),
      requestStack: new RequestStack(),
      channelLogger: new NullLogger(),
      urlResolver: $resolver,
      state: $state,
    );
  }

  /**
   * Builds an Invalidation mock that records a single setState call.
   */
  private function makeInvalidation(string $expression): InvalidationInterface {
    $invalidation = $this->createMock(InvalidationInterface::class);
    $invalidation->method('getExpression')->willReturn($expression);
    return $invalidation;
  }

  /**
   * @covers ::invalidate
   */
  public function testTagStrategySendsLegacyTagHeader(): void {
    // Regression-safe path: identical shape to pre-strategy beta6.
    $history = [];
    $purger = $this->makePurger('tag', 'none', $history);

    $invalidation = $this->makeInvalidation('node:38');
    $invalidation->expects($this->once())
      ->method('setState')
      ->with(InvalidationInterface::SUCCEEDED);

    $purger->invalidate([$invalidation]);

    $this->assertCount(1, $history);
    $request = $history[0]['request'];
    $this->assertSame('p:node:38', $request->getHeaderLine('X-LiteSpeed-Purge'));
  }

  /**
   * @covers ::invalidate
   */
  public function testUrlStrategySendsOnePurgePerResolvedUrl(): void {
    // 1.3.x field test on LSWS Enterprise 6.3.4: the header-based
    // url= directive is silently ignored alongside tag=. The purger
    // emits one PURGE per URL with the URL as the request path; no
    // X-LiteSpeed-Purge header on any of them.
    $history = [];
    $purger = $this->makePurger(
      strategy: 'url',
      unmappable_fallback: 'none',
      history: $history,
      resolver_results: [
        'node:38' => new UrlResolverResult(['/node/38', '/about-us'], FALSE, 'ok'),
      ],
    );

    $invalidation = $this->makeInvalidation('node:38');
    $invalidation->expects($this->once())
      ->method('setState')
      ->with(InvalidationInterface::SUCCEEDED);

    $purger->invalidate([$invalidation]);

    $this->assertCount(2, $history);
    $this->assertSame('http://localhost/node/38', (string) $history[0]['request']->getUri());
    $this->assertSame('http://localhost/about-us', (string) $history[1]['request']->getUri());
    foreach ($history as $entry) {
      $this->assertSame('', $entry['request']->getHeaderLine('X-LiteSpeed-Purge'));
      $this->assertSame('www.example.com', $entry['request']->getHeaderLine('Host'));
    }
  }

  /**
   * @covers ::invalidate
   */
  public function testUrlStrategyDeduplicatesUrlsAcrossBatch(): void {
    // Two invalidations resolving to overlapping URL sets collapse to
    // unique URLs; the shared alias /about-us emits one PURGE, not
    // two. With per-URL PURGE we expect three requests total: the two
    // distinct canonical paths plus the one shared alias.
    $history = [];
    $purger = $this->makePurger(
      strategy: 'url',
      unmappable_fallback: 'none',
      history: $history,
      resolver_results: [
        'node:38' => new UrlResolverResult(['/node/38', '/about-us'], FALSE, 'ok'),
        'node:39' => new UrlResolverResult(['/node/39', '/about-us'], FALSE, 'ok'),
      ],
    );

    $purger->invalidate([
      $this->makeInvalidation('node:38'),
      $this->makeInvalidation('node:39'),
    ]);

    $this->assertCount(3, $history);
    $uris = array_map(static fn(array $e): string => (string) $e['request']->getUri(), $history);
    $this->assertSame([
      'http://localhost/node/38',
      'http://localhost/about-us',
      'http://localhost/node/39',
    ], $uris);
  }

  /**
   * @covers ::invalidate
   */
  public function testAutoStrategyPicksUrlWhenProbeFalse(): void {
    $history = [];
    $purger = $this->makePurger(
      strategy: 'auto',
      unmappable_fallback: 'none',
      history: $history,
      tag_purge_effective: FALSE,
      resolver_results: [
        'node:38' => new UrlResolverResult(['/node/38'], FALSE, 'ok'),
      ],
    );

    $purger->invalidate([$this->makeInvalidation('node:38')]);

    $this->assertCount(1, $history);
    $this->assertSame('http://localhost/node/38', (string) $history[0]['request']->getUri());
    $this->assertSame('', $history[0]['request']->getHeaderLine('X-LiteSpeed-Purge'));
  }

  /**
   * @covers ::invalidate
   */
  public function testAutoStrategyPicksTagWhenProbeTrue(): void {
    $history = [];
    $purger = $this->makePurger(
      strategy: 'auto',
      unmappable_fallback: 'none',
      history: $history,
      tag_purge_effective: TRUE,
    );

    $purger->invalidate([$this->makeInvalidation('node:38')]);

    $this->assertCount(1, $history);
    $this->assertSame('p:node:38', $history[0]['request']->getHeaderLine('X-LiteSpeed-Purge'));
  }

  /**
   * @covers ::invalidate
   */
  public function testAutoStrategyDefaultsToUrlWhenProbeNeverRan(): void {
    // NULL state means no probe has run yet. `auto` is safe by default:
    // it uses the URL strategy (which evicts on every LiteSpeed build)
    // until a probe confirms tag-PURGE works, so a fresh install never
    // purges by tag into a build that silently ignores it and serves
    // stale content.
    $history = [];
    $purger = $this->makePurger(
      strategy: 'auto',
      unmappable_fallback: 'none',
      history: $history,
      tag_purge_effective: NULL,
      resolver_results: [
        'node:38' => new UrlResolverResult(['/node/38'], FALSE, 'ok'),
      ],
    );

    $purger->invalidate([$this->makeInvalidation('node:38')]);

    $this->assertCount(1, $history);
    $this->assertSame('http://localhost/node/38', (string) $history[0]['request']->getUri());
    $this->assertSame('', $history[0]['request']->getHeaderLine('X-LiteSpeed-Purge'));
  }

  /**
   * @covers ::invalidate
   */
  public function testUnmappableFallbackNoneDropsTagSilently(): void {
    // Single unmappable invalidation, fallback=none: no HTTP request
    // is sent and the invalidation is marked SUCCEEDED. Operators
    // who want stricter semantics can flip to purge_all.
    $history = [];
    $purger = $this->makePurger(
      strategy: 'url',
      unmappable_fallback: 'none',
      history: $history,
      resolver_results: [
        'config:system.site' => new UrlResolverResult([], TRUE, 'config'),
      ],
    );

    $invalidation = $this->makeInvalidation('config:system.site');
    $invalidation->expects($this->once())
      ->method('setState')
      ->with(InvalidationInterface::SUCCEEDED);

    $purger->invalidate([$invalidation]);

    $this->assertCount(0, $history, 'No PURGE should fire when the only tag is unmappable and fallback=none.');
  }

  /**
   * @covers ::invalidate
   */
  public function testUnmappableFallbackPurgeAllSendsStarPurge(): void {
    $history = [];
    $purger = $this->makePurger(
      strategy: 'url',
      unmappable_fallback: 'purge_all',
      history: $history,
      resolver_results: [
        'node:38' => new UrlResolverResult(['/node/38'], FALSE, 'ok'),
        'config:system.site' => new UrlResolverResult([], TRUE, 'config'),
      ],
    );

    $node = $this->makeInvalidation('node:38');
    $config = $this->makeInvalidation('config:system.site');
    foreach ([$node, $config] as $inv) {
      $inv->expects($this->once())
        ->method('setState')
        ->with(InvalidationInterface::SUCCEEDED);
    }

    $purger->invalidate([$node, $config]);

    $this->assertCount(1, $history, 'purge_all collapses the batch into one PURGE *.');
    $this->assertSame('*', $history[0]['request']->getHeaderLine('X-LiteSpeed-Purge'));
  }

}
