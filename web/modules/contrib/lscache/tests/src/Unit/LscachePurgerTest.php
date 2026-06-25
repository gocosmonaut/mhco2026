<?php

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
use Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\lscache_purger\Plugin\Purge\Purger\LscachePurger
 * @group lscache
 */
class LscachePurgerTest extends UnitTestCase {

  /**
   * Builds a LscachePurger with mock collaborators.
   *
   * Default: empty purge_host, no current request (CLI context), no
   * $base_url. This is the gap state the 1.3.0-rc1 field test
   * surfaced; the test cases below verify that the failure path runs
   * through markAllFailed() with the actionable reason.
   *
   * @param string $purge_host
   *   The purge_host config value to return. Empty string simulates
   *   missing config or unset operator value.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   Optional logger mock injected into the purger. When NULL the
   *   helper installs a NullLogger so tests that don't assert on
   *   log output don't have to wire one explicitly.
   * @param string $purge_host_header
   *   The purge_host_header config value to return. Empty string
   *   simulates the unset state (Guzzle's URL-derived Host header
   *   default carries through).
   * @param \GuzzleHttp\ClientInterface|null $http_client
   *   Optional HTTP client mock injected into the purger. Tests that
   *   need to assert on the outgoing PURGE request pass a real
   *   Guzzle Client with MockHandler + history middleware; tests
   *   that exercise only the early failure path can omit this and
   *   the helper installs a bare mock.
   * @param string $purge_strategy
   *   The purge_strategy config value ('tag', 'url', or 'auto').
   * @param string $unmappable_fallback
   *   The unmappable_fallback config value ('none' or 'purge_all').
   * @param \Drupal\lscache_purger\Resolver\InvalidationUrlResolver|null $url_resolver
   *   Optional resolver mock for the URL strategy. NULL installs a
   *   bare mock.
   * @param \Drupal\Core\State\StateInterface|null $state
   *   Optional state mock for auto-strategy probe lookups. NULL
   *   installs a bare mock.
   */
  private function makePurger(string $purge_host = '', ?LoggerInterface $logger = NULL, string $purge_host_header = '', ?ClientInterface $http_client = NULL, string $purge_strategy = 'tag', string $unmappable_fallback = 'none', ?InvalidationUrlResolver $url_resolver = NULL, ?StateInterface $state = NULL): LscachePurger {
    $purger_config = $this->createMock(Config::class);
    $purger_config->method('get')->willReturnMap([
      ['purge_host', $purge_host],
      ['purge_host_header', $purge_host_header],
      ['timeout', 4],
      ['purge_strategy', $purge_strategy],
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

    // Container is set up minimally because PurgerBase::__construct
    // accesses the static container during base class init. The
    // markAllFailed() failure path calls $this->messenger() (from
    // PluginBase's MessengerTrait), which falls back to
    // \Drupal::messenger() when the property is unset; register a
    // messenger mock in the container so that lookup resolves
    // cleanly. The string_translation service is also looked up
    // when t() is called during error-message construction.
    $container = new ContainerBuilder();
    $container->set('messenger', $this->createMock(MessengerInterface::class));
    $container->set('string_translation', $this->createMock(TranslationInterface::class));
    \Drupal::setContainer($container);

    // Empty request stack simulates CLI/cron context where
    // resolveHost() falls through to $base_url.
    $request_stack = new RequestStack();

    // Ensure $base_url is empty so the final fallback in
    // resolveHost() also returns empty.
    $GLOBALS['base_url'] = '';

    return new LscachePurger(
      // PurgerBase::__construct reads $this->configuration['id'] and
      // throws LogicException without it. The Purge framework
      // injects this at runtime via createInstance($plugin_id,
      // ['id' => $purger_instance_id]); the test mirrors that shape.
      configuration: ['id' => 'lscache_test_instance'],
      plugin_id: 'lscache',
      plugin_definition: ['id' => 'lscache'],
      httpClient: $http_client ?? $this->createMock(ClientInterface::class),
      configFactory: $factory,
      tagHeaderBuilder: new TagHeaderBuilder($factory),
      requestStack: $request_stack,
      channelLogger: $logger ?? $this->createMock(LoggerInterface::class),
      urlResolver: $url_resolver ?? $this->createMock(InvalidationUrlResolver::class),
      state: $state ?? $this->createMock(StateInterface::class),
    );
  }

  /**
   * Returns a fresh InvalidationInterface mock.
   *
   * Each invalidation must record exactly one setState call (FAILED
   * or SUCCEEDED). We use a mock that captures the recorded state so
   * tests can assert against it.
   *
   * @param string $expression
   *   The tag string the invalidation carries.
   */
  private function makeInvalidation(string $expression = 'node:1'): InvalidationInterface {
    $invalidation = $this->createMock(InvalidationInterface::class);
    $invalidation->method('getExpression')->willReturn($expression);
    return $invalidation;
  }

  /**
   * @covers ::invalidate
   */
  public function testInvalidateBailsWhenAllHostSourcesEmpty(): void {
    // The 1.3.0-rc1 field-test scenario in unit-test form: missing
    // purge_host config, CLI context (empty RequestStack), no
    // $base_url. Every invalidation should be marked FAILED with an
    // actionable reason logged.
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('LSCache purge aborted'),
        $this->callback(static fn(array $context): bool => str_contains($context['@reason'], 'No purge host available')),
      );
    $purger = $this->makePurger(purge_host: '', logger: $logger);

    $invalidation = $this->makeInvalidation();
    $invalidation->expects($this->once())
      ->method('setState')
      ->with(InvalidationInterface::FAILED);

    $purger->invalidate([$invalidation]);
  }

  /**
   * Builds a real Guzzle Client with mock handler + history middleware.
   *
   * The history container is passed in by reference and gets populated
   * as the purger issues requests. Tests can inspect it afterwards to
   * assert on the outgoing PURGE, specifically the Host header.
   *
   * The by-reference pattern is required because the Guzzle history
   * middleware writes into the array via reference; if the helper
   * returned the container as part of a destructured tuple, the
   * destructuring would copy at the empty moment and updates would
   * not be visible to the caller. See PHP RFC 4.4 array literal
   * reference semantics for the underlying reason.
   *
   * @param int $response_status
   *   HTTP status the mock handler should return for the PURGE.
   * @param array<int, array<string, mixed>> $history
   *   Reference to the history container; populated as side effect.
   *
   * @return \GuzzleHttp\Client
   *   A Guzzle client wired to the mock handler and history middleware.
   */
  private function makeGuzzleWithHistory(int $response_status, array &$history): Client {
    $mock_handler = new MockHandler([new Response($response_status)]);
    $stack = HandlerStack::create($mock_handler);
    $stack->push(Middleware::history($history));
    return new Client(['handler' => $stack]);
  }

  /**
   * @covers ::invalidate
   */
  public function testPurgeRequestOverridesHostHeaderWhenConfigured(): void {
    // The 1.3.0-rc2 portal-master diagnosis in unit-test form: when
    // purge_host_header is configured, the outgoing PURGE request
    // must carry Host: <configured-value> so LSWS evicts entries
    // keyed under the canonical site host instead of the localhost
    // bucket Guzzle would otherwise derive from the URL.
    $history = [];
    $client = $this->makeGuzzleWithHistory(200, $history);
    $purger = $this->makePurger(
      purge_host: 'http://localhost/',
      purge_host_header: 'www.example.com',
      http_client: $client,
    );

    $purger->invalidate([$this->makeInvalidation('node:521')]);

    $this->assertCount(1, $history, 'Exactly one outgoing PURGE request was recorded.');
    $request = $history[0]['request'];
    $this->assertSame('PURGE', $request->getMethod());
    $this->assertSame(
      'www.example.com',
      $request->getHeaderLine('Host'),
      'Host header must reflect purge_host_header config so LSWS evicts the canonical-host cache bucket.',
    );
    $this->assertSame(
      'p:node:521',
      $request->getHeaderLine('X-LiteSpeed-Purge'),
      'X-LiteSpeed-Purge must still carry the prefixed tag list.',
    );
  }

  /**
   * @covers ::invalidate
   */
  public function testPurgeRequestKeepsGuzzleDefaultHostHeaderWhenUnset(): void {
    // Backward compatibility: when purge_host_header is unset, the
    // purger must not inject anything Host-related into the headers
    // array. Guzzle's URL-derived default carries through unchanged.
    // Edge-case operators who genuinely want Host: localhost (some
    // containerised dev setups, where LSWS keys the cache that way)
    // must not be surprised by an opt-in override applying silently.
    $history = [];
    $client = $this->makeGuzzleWithHistory(200, $history);
    $purger = $this->makePurger(
      purge_host: 'http://localhost/',
      purge_host_header: '',
      http_client: $client,
    );

    $purger->invalidate([$this->makeInvalidation('node:1')]);

    $this->assertCount(1, $history);
    $request = $history[0]['request'];
    $this->assertSame(
      'localhost',
      $request->getHeaderLine('Host'),
      'With purge_host_header unset, Guzzle derives Host from the URL (localhost).',
    );
  }

  /**
   * @covers ::invalidate
   */
  public function testPurgeRequestTreatsWhitespaceOnlyHostHeaderAsUnset(): void {
    // Defence against operator config drift: an accidental
    // whitespace-only value in purge_host_header must not produce a
    // PURGE request with Host: "   ", which LSWS would reject. The
    // runtime trim() in invalidate() should treat it as unset.
    $history = [];
    $client = $this->makeGuzzleWithHistory(200, $history);
    $purger = $this->makePurger(
      purge_host: 'http://localhost/',
      purge_host_header: '   ',
      http_client: $client,
    );

    $purger->invalidate([$this->makeInvalidation('node:1')]);

    $this->assertCount(1, $history);
    $request = $history[0]['request'];
    $this->assertSame(
      'localhost',
      $request->getHeaderLine('Host'),
      'Whitespace-only purge_host_header must fall through to Guzzle default, not produce a malformed Host header.',
    );
  }

  /**
   * @covers ::invalidate
   */
  public function testInvalidateBailsForBatchWhenHostMissing(): void {
    // The same gap state with a batch of invalidations: every one
    // should be marked FAILED. Regression guard for "did we forget to
    // mark some?" pitfalls in the failure path.
    $purger = $this->makePurger(purge_host: '');

    $invalidations = [
      $this->makeInvalidation('node:1'),
      $this->makeInvalidation('node:2'),
      $this->makeInvalidation('user:1'),
    ];
    foreach ($invalidations as $invalidation) {
      $invalidation->expects($this->once())
        ->method('setState')
        ->with(InvalidationInterface::FAILED);
    }

    $purger->invalidate($invalidations);
  }

}
