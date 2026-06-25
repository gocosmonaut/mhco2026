<?php

namespace Drupal\Tests\lscache\Unit;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\lscache\EventSubscriber\LscacheResponseSubscriber;
use Drupal\lscache\TagHeaderBuilder;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;

/**
 * @coversDefaultClass \Drupal\lscache\EventSubscriber\LscacheResponseSubscriber
 * @group lscache
 */
class LscacheResponseSubscriberTest extends UnitTestCase {

  /**
   * Builds a subscriber with default config.
   *
   * Defaults: enabled, ttl=3600, prefix='p:', filtering off, debug off,
   * private cache off (matching 1.0.x back-compat).
   *
   * @param bool $enabled
   *   Whether the module's enabled flag is on.
   * @param bool $private_enabled
   *   Whether private-cache mode is on.
   * @param int $private_ttl
   *   TTL for private-mode responses.
   * @param string[] $private_contexts
   *   Cache contexts that trigger private mode.
   * @param string[] $vary_cookies
   *   Cookie names the cache should vary on; empty disables emission.
   * @param bool $is_admin_route
   *   Whether the matched route is an admin route. The subscriber
   *   skips emission entirely for admin routes regardless of mode.
   * @param string $session_cookie_name
   *   The session cookie name SessionConfiguration reports for the
   *   current request. Defaults to 'SSESS_test'. The auto-vary
   *   logic appends this to the emitted vary list when private
   *   mode is on.
   * @param bool $tag_filter
   *   Whether the TagHeaderBuilder tag filter is on. Defaults to FALSE
   *   (matching the other cases); set TRUE to exercise the empty-
   *   filtered-tag path where only aggregate tags are present.
   */
  private function makeSubscriber(
    bool $enabled = TRUE,
    bool $private_enabled = FALSE,
    int $private_ttl = 600,
    array $private_contexts = ['user', 'user.permissions', 'user.roles', 'session'],
    array $vary_cookies = [],
    bool $is_admin_route = FALSE,
    string $session_cookie_name = 'SSESS_test',
    bool $tag_filter = FALSE,
  ): LscacheResponseSubscriber {
    $lscache_config = $this->createMock(Config::class);
    $lscache_config->method('get')->willReturnMap([
      ['enabled', $enabled],
      ['default_ttl', 3600],
      ['debug', FALSE],
      ['tag_prefix', 'p:'],
      ['tag_filter', $tag_filter],
      ['private_cache.enabled', $private_enabled],
      ['private_cache.ttl', $private_ttl],
      ['private_cache.contexts', $private_contexts],
      ['vary_cookies', $vary_cookies],
    ]);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('lscache.settings')->willReturn($lscache_config);

    $admin_context = $this->createMock(AdminContext::class);
    $admin_context->method('isAdminRoute')->willReturn($is_admin_route);

    $session_configuration = $this->createMock(SessionConfigurationInterface::class);
    $session_configuration->method('getOptions')->willReturn(['name' => $session_cookie_name]);

    // RequestStack with one request present so the auto-discovered
    // session cookie path has a request to read options from.
    $request_stack = new RequestStack();
    $request_stack->push(Request::create('/'));

    return new LscacheResponseSubscriber(
      $factory,
      new TagHeaderBuilder($factory),
      $this->createMock(LoggerInterface::class),
      $admin_context,
      $session_configuration,
      $request_stack,
    );
  }

  /**
   * Wraps a CacheableResponse in a main-request ResponseEvent.
   *
   * Attaches a stub Route as the request's _route_object so the
   * subscriber's AdminContext check has something to inspect. The mock
   * AdminContext ignores the actual route content (it returns a fixed
   * value from makeSubscriber), so any Route instance is fine.
   */
  private function event(CacheableResponse $response): ResponseEvent {
    $kernel = $this->createMock(HttpKernelInterface::class);
    $request = Request::create('/');
    $request->attributes->set('_route_object', new Route('/'));
    return new ResponseEvent(
      $kernel,
      $request,
      HttpKernelInterface::MAIN_REQUEST,
      $response,
    );
  }

  /**
   * Builds a cacheable response with cache tags and a Cache-Control header.
   *
   * @param string[] $tags
   *   Cache tags to attach.
   * @param string $cache_control
   *   Cache-Control header value to simulate Drupal's final decision.
   * @param string[] $contexts
   *   Cache contexts to attach. Defaults to none (matching anonymous
   *   public-cache responses).
   */
  private function response(array $tags, string $cache_control, array $contexts = []): CacheableResponse {
    $response = new CacheableResponse();
    $metadata = (new CacheableMetadata())->setCacheTags($tags);
    if ($contexts !== []) {
      $metadata->setCacheContexts($contexts);
    }
    $response->addCacheableDependency($metadata);
    $response->headers->set('Cache-Control', $cache_control);
    return $response;
  }

  /**
   * @covers ::onResponse
   */
  public function testCacheableResponseGetsLscacheHeaders(): void {
    $subscriber = $this->makeSubscriber();
    $response = $this->response(['node:1'], 'public, max-age=3600');

    $subscriber->onResponse($this->event($response));

    $this->assertSame('p:node:1', $response->headers->get('X-LiteSpeed-Tag'));
    $this->assertSame('public,max-age=3600', $response->headers->get('X-LiteSpeed-Cache-Control'));
  }

  /**
   * Empty filtered tag set still emits public Cache-Control for URL-purge.
   *
   * Finding-1 fix: a public-cacheable response whose only cache tags are
   * aggregate tags the filter strips (a controller-rendered listing page
   * tagged node_list / taxonomy_term_list / config:* and nothing
   * entity-shaped) filters down to an empty X-LiteSpeed-Tag. Pre-fix the
   * subscriber bailed and emitted nothing, so LSWS cached the page off
   * the raw Cache-Control header into an entry that did not honour the
   * per-URL PURGE. Post-fix it still emits X-LiteSpeed-Cache-Control
   * (bringing the entry under LSCache management so URL-PURGE evicts it)
   * but omits the empty X-LiteSpeed-Tag header.
   */
  public function testEmptyFilteredTagSetStillEmitsCacheControlForUrlPurge(): void {
    $subscriber = $this->makeSubscriber(tag_filter: TRUE);
    $response = $this->response(
      ['node_list', 'taxonomy_term_list:help_category', 'config:views.view.help', 'http_response', 'rendered'],
      'public, max-age=3600',
    );

    $subscriber->onResponse($this->event($response));

    $this->assertFalse($response->headers->has('X-LiteSpeed-Tag'), 'No tag header when the filtered set is empty.');
    $this->assertSame('public,max-age=3600', $response->headers->get('X-LiteSpeed-Cache-Control'));
  }

  /**
   * A surviving entity tag still emits both headers.
   *
   * Regression guard for the empty-tag change: when the filter leaves at
   * least one entity-shaped tag, both headers emit exactly as before
   * (the tag header carries only the surviving entity tag, not the
   * stripped aggregates).
   */
  public function testFilterKeepsEntityTagAndEmitsBothHeaders(): void {
    $subscriber = $this->makeSubscriber(tag_filter: TRUE);
    $response = $this->response(
      ['node:1', 'node_list', 'config:views.view.help', 'http_response'],
      'public, max-age=3600',
    );

    $subscriber->onResponse($this->event($response));

    $this->assertSame('p:node:1', $response->headers->get('X-LiteSpeed-Tag'));
    $this->assertSame('public,max-age=3600', $response->headers->get('X-LiteSpeed-Cache-Control'));
  }

  /**
   * Empty filtered tag set still emits private Cache-Control.
   *
   * The empty-filtered-tag emission applies on the private branch too: a
   * per-user listing page that filters to an empty tag set is still
   * brought under private LSCache management for URL-purgeability.
   */
  public function testEmptyFilteredTagSetStillEmitsPrivateCacheControl(): void {
    $subscriber = $this->makeSubscriber(private_enabled: TRUE, tag_filter: TRUE);
    $response = $this->response(
      ['node_list', 'config:views.view.help'],
      'private',
      ['user'],
    );

    $subscriber->onResponse($this->event($response));

    $this->assertFalse($response->headers->has('X-LiteSpeed-Tag'));
    $this->assertSame('private,max-age=600', $response->headers->get('X-LiteSpeed-Cache-Control'));
  }

  /**
   * Private mode is suppressed when the response embeds drupalSettings.
   *
   * The portal /help regression: an authenticated full-page response
   * carrying a drupalSettings JSON block holds per-request ajaxTrustedUrl
   * and BigPipe ids that are unsafe to replay per-user. The subscriber
   * must emit no-cache, not a private store.
   */
  public function testPrivateModeSuppressedWhenResponseEmbedsDrupalSettings(): void {
    $subscriber = $this->makeSubscriber(private_enabled: TRUE);
    $response = $this->response(['node:1'], 'private, must-revalidate', ['user']);
    $response->setContent('<html><body><script type="application/json" data-drupal-selector="drupal-settings-json">{"ajaxTrustedUrl":[]}</script></body></html>');

    $subscriber->onResponse($this->event($response));

    $this->assertSame('no-cache', $response->headers->get('X-LiteSpeed-Cache-Control'));
    $this->assertFalse($response->headers->has('X-LiteSpeed-Tag'), 'No tag header on a suppressed private response.');
  }

  /**
   * Private mode still emits when the body carries no drupalSettings.
   *
   * Negative case for the per-session JS-state guard: a per-user response
   * with no embedded drupalSettings block is safe to hold, so private
   * emission proceeds as before.
   */
  public function testPrivateModeEmitsWhenNoPerSessionJsState(): void {
    $subscriber = $this->makeSubscriber(private_enabled: TRUE);
    $response = $this->response(['node:1'], 'private, must-revalidate', ['user']);
    $response->setContent('<html><body>No client-side settings here.</body></html>');

    $subscriber->onResponse($this->event($response));

    $this->assertSame('private,max-age=600', $response->headers->get('X-LiteSpeed-Cache-Control'));
  }

  /**
   * Uncacheable response with an empty tag set emits nothing.
   *
   * A response with a genuinely empty tag set that is NOT HTTP-cacheable
   * (no public directive, private mode off) is still left untouched -
   * the empty-tag change only affects responses that reach a caching
   * branch.
   */
  public function testEmptyFilteredTagSetOnUncacheableResponseEmitsNothing(): void {
    $subscriber = $this->makeSubscriber(tag_filter: TRUE);
    $response = $this->response(['node_list', 'http_response'], 'private, no-cache');

    $subscriber->onResponse($this->event($response));

    $this->assertFalse($response->headers->has('X-LiteSpeed-Tag'));
    $this->assertFalse($response->headers->has('X-LiteSpeed-Cache-Control'));
  }

  /**
   * @covers ::onResponse
   */
  public function testPrivateResponseIsSkippedWhenPrivateModeOff(): void {
    $subscriber = $this->makeSubscriber();
    $response = $this->response(['node:1', 'user:1'], 'must-revalidate, no-cache, private', ['user']);

    $subscriber->onResponse($this->event($response));

    $this->assertFalse($response->headers->has('X-LiteSpeed-Tag'));
    $this->assertFalse($response->headers->has('X-LiteSpeed-Cache-Control'));
  }

  /**
   * @covers ::onResponse
   */
  public function testNoCacheResponseIsSkipped(): void {
    $subscriber = $this->makeSubscriber();
    $response = $this->response(['node:1'], 'no-cache, public');

    $subscriber->onResponse($this->event($response));

    $this->assertFalse($response->headers->has('X-LiteSpeed-Tag'));
    $this->assertFalse($response->headers->has('X-LiteSpeed-Cache-Control'));
  }

  /**
   * @covers ::onResponse
   */
  public function testNoStoreResponseIsSkipped(): void {
    $subscriber = $this->makeSubscriber();
    $response = $this->response(['node:1'], 'no-store, public');

    $subscriber->onResponse($this->event($response));

    $this->assertFalse($response->headers->has('X-LiteSpeed-Tag'));
    $this->assertFalse($response->headers->has('X-LiteSpeed-Cache-Control'));
  }

  /**
   * @covers ::onResponse
   */
  public function testDisabledModuleEmitsNothingEvenOnCacheableResponse(): void {
    $subscriber = $this->makeSubscriber(enabled: FALSE);
    $response = $this->response(['node:1'], 'public, max-age=3600');

    $subscriber->onResponse($this->event($response));

    $this->assertFalse($response->headers->has('X-LiteSpeed-Tag'));
    $this->assertFalse($response->headers->has('X-LiteSpeed-Cache-Control'));
  }

  /**
   * @covers ::onResponse
   */
  public function testPrivateModeOnEmitsPrivateHeadersForUserContext(): void {
    $subscriber = $this->makeSubscriber(private_enabled: TRUE);
    $response = $this->response(['node:1', 'user:1'], 'must-revalidate, no-cache, private', ['user']);

    $subscriber->onResponse($this->event($response));

    $this->assertSame('p:node:1,p:user:1', $response->headers->get('X-LiteSpeed-Tag'));
    $this->assertSame('private,max-age=600', $response->headers->get('X-LiteSpeed-Cache-Control'));
  }

  /**
   * @covers ::onResponse
   */
  public function testPrivateModeOnEmitsPrivateHeadersForNamespacedContext(): void {
    // Namespace match: the trigger 'user' should also fire on
    // 'user.permissions' / 'user.roles' contexts.
    $subscriber = $this->makeSubscriber(private_enabled: TRUE);
    $response = $this->response(['node:1'], 'private', ['user.permissions']);

    $subscriber->onResponse($this->event($response));

    $this->assertSame('p:node:1', $response->headers->get('X-LiteSpeed-Tag'));
    $this->assertSame('private,max-age=600', $response->headers->get('X-LiteSpeed-Cache-Control'));
  }

  /**
   * @covers ::onResponse
   */
  public function testPrivateModeOnSkipsResponseWithoutPerUserContext(): void {
    // Public response with no per-user context goes through the public
    // branch, not the private branch.
    $subscriber = $this->makeSubscriber(private_enabled: TRUE);
    $response = $this->response(['node:1'], 'public, max-age=3600', ['url']);

    $subscriber->onResponse($this->event($response));

    $this->assertSame('p:node:1', $response->headers->get('X-LiteSpeed-Tag'));
    $this->assertSame('public,max-age=3600', $response->headers->get('X-LiteSpeed-Cache-Control'));
  }

  /**
   * @covers ::onResponse
   */
  public function testPrivateModeOnSkipsNoStoreResponse(): void {
    // no-store overrides everything: not even private cache should hold
    // a copy.
    $subscriber = $this->makeSubscriber(private_enabled: TRUE);
    $response = $this->response(['node:1'], 'no-store, private', ['user']);

    $subscriber->onResponse($this->event($response));

    $this->assertFalse($response->headers->has('X-LiteSpeed-Tag'));
    $this->assertFalse($response->headers->has('X-LiteSpeed-Cache-Control'));
  }

  /**
   * @covers ::onResponse
   */
  public function testPrivateModeOnSkipsResponseWithNonMatchingContext(): void {
    // A trigger of 'user' must not falsely match 'userdata' (or any
    // other context not in the user.* namespace).
    $subscriber = $this->makeSubscriber(private_enabled: TRUE);
    $response = $this->response(['node:1'], 'private', ['userdata']);

    $subscriber->onResponse($this->event($response));

    $this->assertFalse($response->headers->has('X-LiteSpeed-Tag'));
    $this->assertFalse($response->headers->has('X-LiteSpeed-Cache-Control'));
  }

  /**
   * @covers ::onResponse
   */
  public function testVaryCookieEmittedWhenConfigured(): void {
    // 1.3.0-alpha3 design: emit X-LiteSpeed-Vary for every configured
    // cookie on every cacheable response, regardless of cache contexts.
    // The operator's config is the directive; LSWS handles cookies that
    // are absent from a given request as "no variance" cleanly.
    $subscriber = $this->makeSubscriber(vary_cookies: ['device_class']);
    $response = $this->response(
      ['node:1'],
      'public, max-age=3600',
    );

    $subscriber->onResponse($this->event($response));

    $this->assertSame('cookie=device_class', $response->headers->get('X-LiteSpeed-Vary'));
  }

  /**
   * @covers ::onResponse
   */
  public function testVaryCookieEmittedForMultipleConfiguredCookies(): void {
    $subscriber = $this->makeSubscriber(vary_cookies: ['device_class', 'currency']);
    $response = $this->response(
      ['node:1'],
      'public, max-age=3600',
    );

    $subscriber->onResponse($this->event($response));

    $this->assertSame('cookie=device_class,currency', $response->headers->get('X-LiteSpeed-Vary'));
  }

  /**
   * @covers ::onResponse
   */
  public function testVaryCookieNotEmittedWhenConfigListIsEmpty(): void {
    // Empty config = no header. The default is empty, so installs that
    // don't configure vary cookies see no behaviour change.
    $subscriber = $this->makeSubscriber(vary_cookies: []);
    $response = $this->response(
      ['node:1'],
      'public, max-age=3600',
    );

    $subscriber->onResponse($this->event($response));

    $this->assertFalse($response->headers->has('X-LiteSpeed-Vary'));
  }

  /**
   * @covers ::onResponse
   */
  public function testVaryCookieEmittedAlongsidePrivateMode(): void {
    // Vary cookies layer on top of either public or private mode. With
    // private_cache.enabled = TRUE the auto-discovered session cookie
    // name also gets added to the vary list, so the emitted header
    // includes both the operator-configured cookie and the session
    // cookie (deduplicated by emitVaryCookies()).
    $subscriber = $this->makeSubscriber(
      private_enabled: TRUE,
      vary_cookies: ['device_class'],
      session_cookie_name: 'SSESS_test',
    );
    $response = $this->response(
      ['node:1'],
      'private',
      ['user'],
    );

    $subscriber->onResponse($this->event($response));

    $this->assertSame('private,max-age=600', $response->headers->get('X-LiteSpeed-Cache-Control'));
    $this->assertSame('cookie=device_class,SSESS_test', $response->headers->get('X-LiteSpeed-Vary'));
  }

  /**
   * @covers ::onResponse
   */
  public function testAdminRoutePublicResponseIsSkipped(): void {
    // Admin routes are excluded from both modes. Even publicly
    // cacheable admin responses must not be held by LSCache, because
    // they leak admin-only data via tag invalidation tracking and
    // serve stale settings forms after operator changes.
    $subscriber = $this->makeSubscriber(is_admin_route: TRUE);
    $response = $this->response(['node:1'], 'public, max-age=3600');

    $subscriber->onResponse($this->event($response));

    $this->assertFalse($response->headers->has('X-LiteSpeed-Tag'));
    $this->assertFalse($response->headers->has('X-LiteSpeed-Cache-Control'));
  }

  /**
   * @covers ::onResponse
   */
  public function testAdminRoutePrivateResponseIsSkipped(): void {
    // The 1.1.0-alpha1 field-test report surfaced this: with private
    // mode on, /admin/people, /admin/content, /admin/modules, and the
    // LSCache settings page itself were getting X-LiteSpeed-Cache-
    // Control: private,max-age=600. AdminContext-based skip catches
    // them all.
    $subscriber = $this->makeSubscriber(
      private_enabled: TRUE,
      is_admin_route: TRUE,
    );
    $response = $this->response(
      ['node:1'],
      'must-revalidate, no-cache, private',
      ['user'],
    );

    $subscriber->onResponse($this->event($response));

    $this->assertFalse($response->headers->has('X-LiteSpeed-Tag'));
    $this->assertFalse($response->headers->has('X-LiteSpeed-Cache-Control'));
  }

  /**
   * @covers ::emitVaryCookies
   * @covers ::autoDiscoveredSessionCookieName
   */
  public function testPrivateModeAutoAddsSessionCookieToVaryList(): void {
    // The 1.3.0-beta4 portal-master retest surfaced this: anon-cached
    // public responses got served back to authenticated requests on
    // the same URL because LSWS hashed both to the same cache key.
    // The fix: with private_cache.enabled = TRUE, the response
    // subscriber appends the auto-discovered session cookie name to
    // the vary list so LSWS keys by session cookie too.
    $subscriber = $this->makeSubscriber(
      private_enabled: TRUE,
      session_cookie_name: 'SSESS_abc123',
    );
    $response = $this->response(['node:1'], 'public, max-age=3600');

    $subscriber->onResponse($this->event($response));

    $this->assertSame('cookie=SSESS_abc123', $response->headers->get('X-LiteSpeed-Vary'));
  }

  /**
   * @covers ::emitVaryCookies
   * @covers ::autoDiscoveredSessionCookieName
   */
  public function testPublicOnlyModeDoesNotAutoAddSessionCookie(): void {
    // Regression guard: sites running public-cache-only (no private
    // mode) must see no behaviour change from the auto-vary patch.
    // The vary header is emitted only when the operator configured
    // vary_cookies entries are present, with no auto-added session
    // cookie.
    $subscriber = $this->makeSubscriber(
      private_enabled: FALSE,
      session_cookie_name: 'SSESS_abc123',
    );
    $response = $this->response(['node:1'], 'public, max-age=3600');

    $subscriber->onResponse($this->event($response));

    $this->assertFalse($response->headers->has('X-LiteSpeed-Vary'));
  }

  /**
   * @covers ::emitVaryCookies
   * @covers ::autoDiscoveredSessionCookieName
   */
  public function testPrivateModeDoesNotDuplicateSessionCookieAlreadyInVaryList(): void {
    // Operators may have already added the session cookie name to
    // their operator-configured vary_cookies list manually (a
    // sensible pre-beta5 workaround). The auto-vary path must not
    // duplicate that entry; emitVaryCookies() deduplicates with
    // array_unique.
    $subscriber = $this->makeSubscriber(
      private_enabled: TRUE,
      vary_cookies: ['SSESS_abc123', 'device_class'],
      session_cookie_name: 'SSESS_abc123',
    );
    $response = $this->response(['node:1'], 'public, max-age=3600');

    $subscriber->onResponse($this->event($response));

    $this->assertSame(
      'cookie=SSESS_abc123,device_class',
      $response->headers->get('X-LiteSpeed-Vary'),
    );
  }

  /**
   * @covers ::onResponse
   * @covers ::hasBigPipePlaceholders
   */
  public function testBigPipePlaceholderTriggersActiveNoCacheSuppression(): void {
    // The 1.3.0-beta3 portal-master retest surfaced this: a response
    // with unresolved BigPipe placeholders gets HTTP-cacheability via
    // Cache-Control: public,max-age=N but cannot be served from cache
    // safely, because LSWS skips PHP on cache hits and the BigPipe
    // streaming-time replacement therefore never runs. The fix
    // actively emits X-LiteSpeed-Cache-Control: no-cache rather than
    // a soft skip, because a soft skip would let LSWS fall back to
    // the HTTP Cache-Control header and cache the response anyway.
    $subscriber = $this->makeSubscriber();
    $response = $this->response(['node:1'], 'public, max-age=3600');
    $response->setContent(
      '<html><body><span data-big-pipe-placeholder-id="abc123"></span></body></html>'
    );

    $subscriber->onResponse($this->event($response));

    $this->assertSame('no-cache', $response->headers->get('X-LiteSpeed-Cache-Control'));
    $this->assertFalse($response->headers->has('X-LiteSpeed-Tag'));
  }

  /**
   * @covers ::onResponse
   * @covers ::hasBigPipePlaceholders
   */
  public function testNonBigPipeResponseEmitsPublicHeadersAsBefore(): void {
    // Regression guard for the BigPipe-detection introduction. A
    // response without BigPipe placeholders in the body should
    // continue to emit public-cache headers exactly as it did before
    // beta4. The marker substring is sensitive enough that an
    // unrelated body should not match.
    $subscriber = $this->makeSubscriber();
    $response = $this->response(['node:1'], 'public, max-age=3600');
    $response->setContent('<html><body><p>Plain content with no placeholders.</p></body></html>');

    $subscriber->onResponse($this->event($response));

    $this->assertSame('p:node:1', $response->headers->get('X-LiteSpeed-Tag'));
    $this->assertSame('public,max-age=3600', $response->headers->get('X-LiteSpeed-Cache-Control'));
  }

  /**
   * @covers ::onResponse
   * @covers ::hasBigPipePlaceholders
   */
  public function testBigPipePlaceholderSuppressesEvenInPrivateMode(): void {
    // BigPipe response with user-keyed cache contexts would otherwise
    // qualify for private-cache emission. Suppression must still win,
    // because LSWS holds a private entry per user but still does not
    // invoke PHP on cache hit, so placeholders stay unresolved per
    // user too. Same scenario as the public case, just on the private
    // branch.
    $subscriber = $this->makeSubscriber(private_enabled: TRUE);
    $response = $this->response(
      ['node:1'],
      'must-revalidate, no-cache, private',
      ['user'],
    );
    $response->setContent(
      '<span data-big-pipe-placeholder-id="cart-count"></span>'
    );

    $subscriber->onResponse($this->event($response));

    $this->assertSame('no-cache', $response->headers->get('X-LiteSpeed-Cache-Control'));
    $this->assertFalse($response->headers->has('X-LiteSpeed-Tag'));
  }

}
