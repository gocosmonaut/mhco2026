<?php

namespace Drupal\lscache\EventSubscriber;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\lscache\TagHeaderBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds X-LiteSpeed-* headers to cacheable responses.
 *
 * Runs at a low priority on KernelEvents::RESPONSE so it fires after
 * Drupal's Dynamic Page Cache has finalised the response's cache metadata.
 *
 * Two emission modes:
 *
 *   1. Public cache: response is HTTP-cacheable (status code, Cache-Control,
 *      Vary all consistent with shared caching). Emits X-LiteSpeed-Cache-
 *      Control: public,max-age=N. Same behaviour as 1.0.x.
 *
 *   2. Private cache: response varies on per-user cache contexts (declared
 *      via the cacheable metadata, e.g. 'user', 'user.permissions',
 *      'session'). Drupal sets Cache-Control: private,must-revalidate on
 *      these, so HTTP isCacheable() returns false; we still want LSCache to
 *      hold a per-user copy if private-cache mode is on. Emits X-LiteSpeed-
 *      Cache-Control: private,max-age=N, with cache-tag headers attached
 *      identically to public mode so tag invalidation evicts both layers.
 *      Off by default: private cache doubles cache footprint per user, so
 *      operators opt in.
 *
 * Admin routes are excluded from both modes. Drupal admin pages declare
 * Cache-Control: must-revalidate, no-cache, private the same way most
 * authenticated content does, so the private-cache path would otherwise
 * happily hold them per-user, leading to up-to-TTL staleness on settings
 * forms (operators don't see their own changes immediately) and storage
 * waste from per-admin views of admin listing pages. Field-test signal
 * from 1.1.0-alpha1 surfaced exactly this on /admin/people, /admin/content,
 * /admin/modules, /admin/config/development/performance, and the LSCache
 * settings page itself. Skipping by route attribute (rather than path
 * regex) catches admin routes outside /admin/* declared by contrib via
 * `_admin_route: TRUE` too.
 *
 * Responses carrying unresolved BigPipe placeholders are also excluded
 * from caching, and the exclusion is enforced actively by emitting
 * `X-LiteSpeed-Cache-Control: no-cache` so LSWS does not fall back to
 * the HTTP Cache-Control header (which BigPipe leaves as `public,
 * max-age=N` because the response is HTTP-cacheable in the abstract).
 * BigPipe resolves its placeholders at streaming time inside PHP; a
 * cache layer that returns the cached body without invoking PHP will
 * ship the unresolved placeholders to the client, so any authenticated
 * content rendered via BigPipe (the admin toolbar, user-account
 * widgets, anything emitting #lazy_builder placeholders that BigPipe
 * streams) goes missing from cached responses. The 1.3.0-beta3
 * portal-master retest surfaced this on a Cloudflare-fronted,
 * Jelastic-managed LSWS Enterprise environment.
 *
 * Private-mode responses that embed a drupalSettings JSON block
 * (ajaxTrustedUrl, ajaxPageState, CSRF and form tokens, BigPipe ids)
 * are likewise excluded: that state is per-request and unsafe to
 * replay from a per-user full-page cache, so the subscriber emits
 * X-LiteSpeed-Cache-Control: no-cache for them. In practice this means
 * private mode does not full-page-cache authenticated HTML, which
 * matches plain Drupal's default. Field-attributed to the portal
 * /help diagnosis (2026-06-04).
 */
class LscacheResponseSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TagHeaderBuilder $tagHeaderBuilder,
    private readonly LoggerInterface $logger,
    private readonly AdminContext $adminContext,
    private readonly SessionConfigurationInterface $sessionConfiguration,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Priority -999 runs very late in the RESPONSE event chain, after
    // Drupal's FinishResponseSubscriber has finalised Cache-Control,
    // ETag, Last-Modified, and Vary. This matches the behaviour of the
    // LiteSpeed-published lite_speed_cache module, which LSWS reliably
    // caches in the field. Earlier priorities caused LSWS to return
    // x-litespeed-cache: miss on every request despite well-formed
    // LSCache headers (observed during alpha1 field testing).
    return [
      KernelEvents::RESPONSE => ['onResponse', -999],
    ];
  }

  /**
   * Attaches LiteSpeed cache headers to the outgoing response.
   *
   * Decision tree:
   *   - Skip non-main requests, modules disabled, non-cacheable response
   *     types, admin routes, or responses with no cache tags worth
   *     emitting.
   *   - If the response is HTTP-cacheable, emit public-mode headers.
   *   - Else, if private-cache mode is enabled and the response declares
   *     per-user cache contexts and is not Cache-Control: no-store, emit
   *     private-mode headers.
   *   - Else, leave the response untouched.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $config = $this->configFactory->get('lscache.settings');
    if (!$config->get('enabled')) {
      return;
    }

    $response = $event->getResponse();
    if (!$response instanceof CacheableResponseInterface) {
      return;
    }

    // Admin routes are always uncached: see class docblock for the
    // reasoning. The check uses the matched route on the current request,
    // which is set by Drupal's RouterListener earlier in the kernel
    // event chain. Bypassing here keeps the rest of the decision tree
    // simple: we don't have to think about admin pages in the
    // public/private branches at all.
    $route = $event->getRequest()->attributes->get('_route_object');
    if ($route !== NULL && $this->adminContext->isAdminRoute($route)) {
      return;
    }

    // BigPipe interaction: responses carrying unresolved BigPipe
    // placeholders cannot be safely cached at the LSWS layer, because
    // LSWS serves cached responses without invoking PHP and BigPipe's
    // streaming-time placeholder replacement therefore never runs.
    // Subsequent requests would receive the cached pre-resolution
    // body, leaving authenticated content (admin toolbar, per-user
    // widgets) missing. Surfaced on the 1.3.0-beta3 portal-master
    // retest where admin users saw the anonymous variant of a
    // public-cached page. Active suppression is necessary, not just a
    // soft skip, because the HTTP Cache-Control is still `public,
    // max-age=N` and LSWS would cache via its own header parsing if
    // we did not override.
    if ($this->hasBigPipePlaceholders($response)) {
      $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
      if ($config->get('debug')) {
        $this->logger->debug('LSCache emission suppressed: BigPipe placeholders present in response body.');
      }
      return;
    }

    // Build the filtered, prefixed X-LiteSpeed-Tag value. This can be
    // the empty string for a response whose only cache tags are the
    // aggregate tags TagHeaderBuilder strips (a controller-rendered
    // listing page whose tags are node_list / taxonomy_term_list /
    // config:views.* and nothing entity-shaped). An empty tag set does
    // NOT mean "do not cache": under the URL-purge strategy a page is
    // still evictable by its own URL, and a public, max-age response is
    // one LSWS will cache off the raw HTTP Cache-Control header whether
    // or not this module emits anything. Returning here therefore does
    // not stop LSWS caching the page - it only abandons it to a cache
    // entry created OUTSIDE the X-LiteSpeed-Cache-Control contract,
    // which on LSWS Enterprise 6.3.4 does not reliably honour the
    // per-URL PURGE the purger issues for static-map / affinity /
    // manual invalidations.
    //
    // The 1.3.x portal-stage /help diagnosis traced chronic stale
    // controller-listing pages to exactly this: /help's cache tags
    // (node_list:help_article, taxonomy_term_list:help_category) all
    // filtered to empty, so the module emitted no header, LSWS cached
    // /help via its forced `Cache-Control: public, max-age=86400`, and
    // `PURGE /help` returned 200 but evicted nothing - while module-
    // managed entries that carry an entity tag (/node/38 -> node:38)
    // evicted normally. Emitting X-LiteSpeed-Cache-Control regardless of
    // the tag set brings the listing under the module's contract so the
    // per-URL PURGE evicts it; X-LiteSpeed-Tag is emitted only when the
    // filtered set is non-empty (emitPublic/emitPrivate enforce that).
    $tags = $this->tagHeaderBuilder->build($response);

    // Branch 1: public cache. Drupal's HTTP semantics already say this
    // response can be shared.
    if ($response->isCacheable()) {
      $this->emitPublic($response, $tags, (int) $config->get('default_ttl'));
      $this->emitVaryCookies($response);
      return;
    }

    // Branch 2: private cache. Only emit if private mode is enabled, the
    // response is private-cacheable per Drupal's metadata, and the
    // response is not no-store.
    if ((bool) $config->get('private_cache.enabled')
      && $this->isPrivateCacheable($response, $config->get('private_cache.contexts') ?? [])
    ) {
      // Per-session client-side state guard. An authenticated response
      // that embeds a drupalSettings JSON blob carries per-request and
      // per-session values (ajaxTrustedUrl, ajaxPageState theme token,
      // CSRF and form-build tokens, BigPipe placeholder ids). A per-user
      // full-page cache that stores and replays this body ships another
      // request's (or a stale) drupalSettings, and core JS that reads
      // those keys (ajax.js drupalSettings.ajaxTrustedUrl, escapeAdmin,
      // active-link, big_pipe) throws, silently breaking every use-ajax
      // link, dialog, AJAX View filter, and contextual link for
      // logged-in users. Suppress actively so LSWS does not hold the
      // entry. Field-attributed to the portal /help diagnosis
      // (2026-06-04); same family as the beta3 anonymous-to-admin bug.
      if ($this->hasPerSessionJsState($response)) {
        $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
        if ($config->get('debug')) {
          $this->logger->debug('LSCache private emission suppressed: response embeds per-session drupalSettings/JS state.');
        }
        return;
      }
      $this->emitPrivate($response, $tags, (int) $config->get('private_cache.ttl'));
      $this->emitVaryCookies($response);
      return;
    }

    if ($config->get('debug')) {
      $this->logger->debug(sprintf(
        'LSCache headers skipped: response is not public-cacheable and private-cache mode is not applicable (Cache-Control: %s, contexts: %s).',
        $response->headers->get('Cache-Control', '(unset)'),
        implode(',', $response->getCacheableMetadata()->getCacheContexts()),
      ));
    }
  }

  /**
   * Emits public-mode LSCache headers.
   *
   * X-LiteSpeed-Cache-Control is emitted whenever the TTL is positive,
   * even when $tags is empty: it is the directive that brings the entry
   * under LSCache management so the per-URL PURGE can evict it (see the
   * empty-tag rationale in onResponse). X-LiteSpeed-Tag is emitted only
   * when the filtered set is non-empty - an empty tag header would be
   * noise and, on builds that honour tag-PURGE, an empty tag scope.
   */
  private function emitPublic(CacheableResponseInterface $response, string $tags, int $ttl): void {
    if ($tags !== '') {
      $response->headers->set('X-LiteSpeed-Tag', $tags);
    }
    if ($ttl > 0) {
      $response->headers->set('X-LiteSpeed-Cache-Control', 'public,max-age=' . $ttl);
    }
    if ($this->configFactory->get('lscache.settings')->get('debug')) {
      // Resolve placeholder values inline rather than relying on the
      // logger's deferred substitution: dblog UI substitutes `@x` style
      // placeholders, but `drush watchdog:show` does not, and
      // operators commonly use drush during alpha testing. Inline
      // sprintf works in every consumer.
      $this->logger->debug(sprintf('LSCache public headers attached: tags=%s', $tags !== '' ? $tags : '(none; URL-purge only)'));
    }
  }

  /**
   * Emits private-mode LSCache headers.
   *
   * Same tag/cache-control split as emitPublic(): the cache-control
   * directive is unconditional (modulo TTL), the tag header conditional
   * on a non-empty filtered set.
   */
  private function emitPrivate(CacheableResponseInterface $response, string $tags, int $ttl): void {
    if ($tags !== '') {
      $response->headers->set('X-LiteSpeed-Tag', $tags);
    }
    if ($ttl > 0) {
      $response->headers->set('X-LiteSpeed-Cache-Control', 'private,max-age=' . $ttl);
    }
    if ($this->configFactory->get('lscache.settings')->get('debug')) {
      // Inline-substitute placeholders: see emitPublic() for rationale.
      $this->logger->debug(sprintf('LSCache private headers attached: tags=%s ttl=%d', $tags !== '' ? $tags : '(none; URL-purge only)', $ttl));
    }
  }

  /**
   * Emits an X-LiteSpeed-Vary header for configured cookies on the response.
   *
   * LSCache supports varying its cache key on the value of one or more
   * named cookies. This is the right primitive for variants of a public
   * page that depend on cookie state but not on user identity (mobile
   * vs. desktop, currency, country, A/B test buckets) AND for the
   * cache-key side of authenticated cache differentiation (so that a
   * shared cache entry populated by an anonymous request does not get
   * served back to authenticated requests for the same URL).
   *
   * Two sources contribute to the emitted cookie list:
   *
   *   1. The operator-configured `vary_cookies` config list, on every
   *      cacheable response. LSWS checks each named cookie's presence
   *      at request time and keys the cache entry accordingly; cookies
   *      that aren't on a given request collapse to "no variance"
   *      cleanly.
   *
   *   2. The site's session cookie name (auto-discovered via
   *      SessionConfiguration::getOptions()), but only when
   *      private_cache.enabled is TRUE. The 1.3.0-beta4 portal-master
   *      retest surfaced this gap: with private-cache mode on but no
   *      session cookie in the vary list, an anonymous request's
   *      cached response on a public URL served back to subsequent
   *      authenticated requests for the same URL, because LSWS
   *      hashed both to the same cache key. BigPipe suppression on
   *      the response side (1.3.0-beta4) did not help because the
   *      authenticated request never reached Drupal. Adding the
   *      session cookie to the vary list is the cache-key side of
   *      the same per-user differentiation that private_cache.enabled
   *      enables on the cache-emission side. Operators must also
   *      configure a matching `CacheVary cookie=NAME` directive on
   *      the LSWS server / vhost / .htaccess for the differentiation
   *      to actually engage (the module emits the header; LSWS
   *      honours it only when the matching directive is in place).
   *
   * Stacking with public or private mode is fine: LSCache extends the
   * cache key with the cookie value in either mode.
   *
   * @param \Drupal\Core\Cache\CacheableResponseInterface $response
   *   The response receiving the header.
   */
  private function emitVaryCookies(CacheableResponseInterface $response): void {
    $config = $this->configFactory->get('lscache.settings');
    $cookies = (array) ($config->get('vary_cookies') ?? []);

    if ((bool) $config->get('private_cache.enabled')) {
      $session_name = $this->autoDiscoveredSessionCookieName();
      if ($session_name !== '') {
        $cookies[] = $session_name;
      }
    }

    $cookies = array_values(array_unique(array_filter(
      $cookies,
      static fn(string $c): bool => $c !== '',
    )));
    if ($cookies === []) {
      return;
    }
    $response->headers->set(
      'X-LiteSpeed-Vary',
      'cookie=' . implode(',', $cookies),
    );
  }

  /**
   * Returns the configured session cookie name, or empty string.
   *
   * Reads from Drupal core's SessionConfiguration service, which is the
   * canonical source for the cookie name in use on this install
   * (operators can configure it via settings.php). Falls back to an
   * empty string when no request context is available (deep CLI/cron
   * contexts where RequestStack is empty), in which case the caller
   * skips adding to the vary list and the existing operator-configured
   * vary_cookies entries still emit normally.
   */
  private function autoDiscoveredSessionCookieName(): string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return '';
    }
    return (string) ($this->sessionConfiguration->getOptions($request)['name'] ?? '');
  }

  /**
   * Detects whether the response body carries unresolved BigPipe placeholders.
   *
   * BigPipe's Drupal core implementation injects span tags carrying a
   * `data-big-pipe-placeholder-id` attribute that mark slots for
   * streaming-time replacement. The replacement happens during
   * `Response::send()` via BigPipe's chunked streaming; it does not
   * happen at render time. Therefore the body we observe in the
   * kernel.response event still contains the placeholder markers,
   * and a cache layer that stores this body and replays it on a
   * later request will replay it without invoking PHP, never
   * running BigPipe and never replacing the placeholders. The
   * 1.3.0-beta3 portal-master retest surfaced exactly this: admin
   * users hitting a publicly-cached page saw the anonymous-shape
   * body because BigPipe placeholders were cached unresolved.
   *
   * Detection is a substring scan over `Response::getContent()`.
   * StreamedResponse and similar return `FALSE` from getContent;
   * those cases also are not appropriate for shared caching, so
   * we return `FALSE` (no markers detected) and let the rest of
   * the decision tree skip emission on its own merits.
   */
  private function hasBigPipePlaceholders(CacheableResponseInterface $response): bool {
    if (!$response instanceof Response) {
      return FALSE;
    }
    $content = $response->getContent();
    if (!is_string($content) || $content === '') {
      return FALSE;
    }
    return str_contains($content, 'data-big-pipe-placeholder-id');
  }

  /**
   * Detects per-session client-side state in an authenticated response.
   *
   * Drupal embeds client-side configuration in a
   * `<script type="application/json"
   * data-drupal-selector="drupal-settings-json">` block (drupalSettings).
   * On an authenticated response that block carries per-request and
   * per-session values: ajaxTrustedUrl, ajaxPageState (theme token),
   * CSRF tokens, form build ids, and BigPipe placeholder ids. A per-user
   * full-page cache that stores and replays the body serves one
   * request's state back to a different request, and core JS that
   * dereferences those keys (ajax.js reading
   * drupalSettings.ajaxTrustedUrl, escapeAdmin.js, active-link.js,
   * big_pipe.js) throws, breaking Drupal AJAX, dialogs, AJAX-exposed
   * View filters, and contextual links for logged-in users. The
   * presence of the drupalSettings JSON block is therefore a
   * disqualifier for private full-page caching.
   *
   * Because effectively every authenticated HTML page carries this
   * block, the practical effect is that private mode no longer
   * full-page-caches authenticated HTML, which is the safe default
   * (plain Drupal does not cache authenticated pages either). Private
   * mode still applies to per-user responses with no embedded JS
   * state. Detection is a substring scan over the response body,
   * consistent with hasBigPipePlaceholders(); non-string bodies
   * (StreamedResponse) return FALSE.
   *
   * Field-attributed to the portal /help diagnosis (2026-06-04):
   * `CacheLookup ... private on` served stale drupalSettings to
   * logged-in users sitewide.
   */
  private function hasPerSessionJsState(CacheableResponseInterface $response): bool {
    if (!$response instanceof Response) {
      return FALSE;
    }
    $content = $response->getContent();
    if (!is_string($content) || $content === '') {
      return FALSE;
    }
    return str_contains($content, 'data-drupal-selector="drupal-settings-json"');
  }

  /**
   * Checks whether the response is a candidate for LSCache private-mode.
   *
   * The response must (a) carry per-user cache contexts that match the
   * configured trigger list, and (b) not declare Cache-Control: no-store.
   *
   * `must-revalidate` and `no-cache` are *not* disqualifying: Drupal sets
   * these on authenticated responses to keep browsers honest, but LSCache
   * private mode is server-side and doesn't conflict with revalidation
   * semantics.
   *
   * @param \Drupal\Core\Cache\CacheableResponseInterface $response
   *   The response under inspection. CacheableResponseInterface always
   *   implies Symfony Response, but we type-hint at the call site so the
   *   header bag is reachable.
   * @param string[] $trigger_contexts
   *   Configured cache contexts that flag the response as per-user.
   *
   * @return bool
   *   TRUE when private-mode emission is appropriate.
   */
  private function isPrivateCacheable(CacheableResponseInterface $response, array $trigger_contexts): bool {
    if (!$response instanceof Response) {
      return FALSE;
    }

    $cache_control = strtolower((string) $response->headers->get('Cache-Control', ''));
    if (str_contains($cache_control, 'no-store')) {
      return FALSE;
    }

    $contexts = $response->getCacheableMetadata()->getCacheContexts();
    if (empty($contexts) || empty($trigger_contexts)) {
      return FALSE;
    }

    // Match exact context names or namespace-prefix matches. A trigger
    // of `user` matches the `user` context as well as
    // `user.permissions`, `user.roles`, etc., because they all imply
    // per-user variation. We use the `.` namespace separator strictly,
    // so `user` does not falsely match a hypothetical `userdata`
    // context from contrib. To match a specific cookie, operators can
    // configure the exact context name (e.g. `cookies:foo`).
    foreach ($contexts as $context) {
      foreach ($trigger_contexts as $trigger) {
        if ($context === $trigger || str_starts_with($context, $trigger . '.')) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

}
