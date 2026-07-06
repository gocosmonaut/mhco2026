<?php

declare(strict_types=1);

namespace Drupal\lscache_purger\Plugin\Purge\Purger;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\lscache\TagHeaderBuilder;
use Drupal\lscache_purger\PurgeHost;
use Drupal\lscache_purger\Resolver\InvalidationUrlResolver;
use Drupal\lscache_purger\StrategyResolver;
use Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface;
use Drupal\purge\Plugin\Purge\Purger\PurgerBase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * LiteSpeed Cache purger.
 *
 * Invalidates LSCache entries by Drupal cache tag by issuing an HTTP
 * PURGE request. Two strategies are supported, selected by the
 * `purge_strategy` config key:
 *
 *  - `tag` (legacy): emits X-LiteSpeed-Purge: tag=NAME,NAME,...
 *    Matches the original beta6 behaviour. Works on LSWS builds that
 *    honour the tag= directive. Surfaced as the regression-safe path:
 *    nothing about the existing tag flow changes when this strategy
 *    is selected.
 *
 *  - `url`: resolves each invalidation tag to canonical Drupal paths
 *    via InvalidationUrlResolver, then emits
 *    X-LiteSpeed-Purge: public, url=/path, url=/path, ...
 *    Required for LSWS Enterprise 6.3.4 (and likely other builds)
 *    where field probing showed the tag= directive is silently
 *    ignored; PURGE /node/N reliably evicts /node/N regardless of
 *    whatever tag= value rides the header. Surfaced on the 1.3.x
 *    portal-master diagnosis.
 *
 *  - `auto` (the default): reads the probe result from state
 *    (`lscache_purger.tag_purge_effective`) via StrategyResolver and
 *    picks `tag` or `url` accordingly, so the module self-corrects to
 *    whatever actually evicts on the target LSWS build.
 *
 * Auto is safe by default. Until a probe has run the state value is
 * NULL, and StrategyResolver treats NULL (and any ineffective result)
 * as `url`, the strategy that evicts on every LiteSpeed build. Only a
 * probe that positively confirms tag-PURGE works selects `tag`. This
 * means a fresh install evicts correctly out of the box even on builds
 * that silently ignore tag-PURGE, instead of purging by tag into a void
 * and serving stale content until someone runs diag. The probe runs
 * automatically on cron (StrategyProber) or on demand via
 * `drush lscache:diag`; once it confirms tag-PURGE works, auto upgrades
 * to the more efficient tag strategy on its own.
 *
 * Tag format, including the auto-derived per-install prefix and the
 * default filter list, must match what the parent `lscache` module
 * emits on its X-LiteSpeed-Tag response headers; otherwise LSCache
 * will not match purge requests against stored responses. This is
 * enforced by routing the tag transformation through
 * \Drupal\lscache\TagHeaderBuilder.
 *
 * @PurgePurger(
 *   id = "lscache",
 *   label = @Translation("LSCache"),
 *   description = @Translation("Invalidates LiteSpeed Cache entries by Drupal cache tag."),
 *   types = {"tag"},
 *   configform = "",
 * )
 */
class LscachePurger extends PurgerBase {

  /**
   * Builds Guzzle request options, including localhost SSL verify skip.
   *
   * When purge_host is HTTPS to a loopback address (the standard
   * single-host install shape), the LSWS instance presents either a
   * self-signed cert, a Let's Encrypt cert valid for the canonical
   * site host (not 127.0.0.1), or a different per-vhost cert. Guzzle's
   * default `verify` of TRUE rejects all three, so PURGE requests fail
   * with cURL error 60 (SSL certificate problem) and never reach
   * LSWS. The 1.3.x portal-dev field test surfaced this on the
   * first URL-strategy invalidation: the purger correctly resolved the
   * target URL, but every PURGE bounced on cert verification.
   *
   * Skipping verify for loopback purge_host values is safe: the
   * connection never leaves the host, so there is no MITM surface to
   * defend against; the cert is purely an LSWS-side artefact of TLS
   * termination. For non-loopback purge_host values, verify stays
   * on; operators pointing the purger at a remote LSWS need a valid
   * cert chain or must pre-trust the CA at the OS level.
   *
   * @param string $target
   *   The fully-resolved PURGE target URL.
   * @param int $timeout
   *   Request timeout in seconds, applied as the Guzzle timeout option.
   *
   * @return array<string, mixed>
   *   Guzzle request options minus headers (caller adds those).
   */
  private function buildRequestOptions(string $target, int $timeout): array {
    $options = [
      'timeout' => $timeout,
      'http_errors' => FALSE,
    ];

    if (PurgeHost::isLoopback($target)) {
      $options['verify'] = FALSE;
    }

    return $options;
  }

  /**
   * Joins purge_host + a canonical Drupal path into a PURGE target URL.
   *
   * The purge_host config carries the scheme and authority LSWS expects
   * for the PURGE request (e.g. http://127.0.0.1/), and the resolver
   * hands the purger paths-only (e.g. /node/38). This helper splices
   * them defensively: it preserves any base path on purge_host (for
   * installs that route LSCache through a sub-path), and avoids
   * doubled slashes between authority and path.
   *
   * Surfaced on the 1.3.x portal diagnosis: LSWS Enterprise 6.3.4
   * was found to silently ignore the X-LiteSpeed-Purge: url=PATH
   * directive (and the tag= directive too), honouring only the PURGE
   * request URL as the eviction scope. Each cache tag invalidation
   * therefore resolves to N URLs, and the purger sends N PURGE
   * requests, one per URL. This helper builds each target URL from
   * the configured host and a resolved path.
   */
  private function joinPurgeTarget(string $host, string $path): string {
    $host_trimmed = rtrim($host, '/');
    $path_normalised = '/' . ltrim($path, '/');
    return $host_trimmed . $path_normalised;
  }

  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TagHeaderBuilder $tagHeaderBuilder,
    private readonly RequestStack $requestStack,
    private readonly LoggerInterface $channelLogger,
    private readonly InvalidationUrlResolver $urlResolver,
    private readonly StateInterface $state,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('config.factory'),
      $container->get('lscache.tag_header_builder'),
      $container->get('request_stack'),
      $container->get('logger.channel.lscache_purger'),
      $container->get('lscache_purger.url_resolver'),
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate(array $invalidations): void {
    $purger_config = $this->configFactory->get('lscache_purger.settings');
    $timeout = (int) ($purger_config->get('timeout') ?? 4);
    $host = $this->resolveHost((string) $purger_config->get('purge_host'));
    $host_header = trim((string) ($purger_config->get('purge_host_header') ?? ''));
    if ($host === '') {
      $this->markAllFailed(
        $invalidations,
        'No purge host available. CLI/cron-driven purges (the default when using the cron processor) require lscache_purger.settings:purge_host to be set explicitly. Use the local LSWS origin URL, not the public CDN-fronted URL; on most single-server installs that is http://localhost/. Run: drush config:set lscache_purger.settings purge_host "http://localhost/" -y'
      );
      return;
    }

    $strategy = $this->resolveStrategy((string) ($purger_config->get('purge_strategy') ?? 'auto'));

    if ($strategy === 'url') {
      $this->invalidateByUrl(
        $invalidations,
        $host,
        $host_header,
        $timeout,
        (string) ($purger_config->get('unmappable_fallback') ?? 'none'),
      );
      return;
    }

    // Fall through: legacy tag-based PURGE. Intentionally byte-for-
    // byte identical to the pre-strategy code path so installs where
    // tag-PURGE works see no behaviour change.
    $this->invalidateByTag($invalidations, $host, $host_header, $timeout);
  }

  /**
   * Legacy tag-based invalidation. Unchanged from beta6 semantics.
   *
   * Kept as its own method so the strategy branch in invalidate()
   * reads as a clean dispatch; the body below is the original
   * implementation lifted verbatim with the host/timeout/headers
   * already resolved by the caller.
   */
  private function invalidateByTag(array $invalidations, string $host, string $host_header, int $timeout): void {
    // Build a single purge request covering all tags in this batch.
    // LSCache accepts a comma-delimited tag list in a single
    // X-LiteSpeed-Purge header, so batching invalidations cuts the
    // per-purge HTTP overhead.
    $tags = [];
    foreach ($invalidations as $invalidation) {
      $tags[] = $this->prefixTag((string) $invalidation->getExpression());
    }

    $purge_header = implode(',', array_unique(array_filter($tags, static fn(string $t): bool => $t !== '')));
    if ($purge_header === '') {
      $this->markAllFailed($invalidations, 'No tags to purge after prefix application.');
      return;
    }

    $headers = ['X-LiteSpeed-Purge' => $purge_header];
    // Override the Host header explicitly when configured. Required when
    // purge_host targets localhost on a single-host install: without
    // this, LSWS keys the PURGE under Host: localhost while real traffic
    // is cached under the canonical site host, so nothing gets evicted.
    // Field-attributed to the 1.3.0-rc2 portal-master diagnosis that
    // surfaced chronic "cache doesn't clear after node edits" reports.
    if ($host_header !== '') {
      $headers['Host'] = $host_header;
    }

    try {
      $response = $this->httpClient->request('PURGE', $host, [
        'headers' => $headers,
      ] + $this->buildRequestOptions($host, $timeout));
      $status = $response->getStatusCode();
    }
    catch (GuzzleException $e) {
      $reason = 'HTTP transport error: ' . $e->getMessage();
      $this->channelLogger->warning(
        'LSCache purge transport error to @url (Host: @host_header): @msg',
        [
          '@url' => $host,
          '@host_header' => $host_header !== '' ? $host_header : '(Guzzle default)',
          '@msg' => $e->getMessage(),
        ],
      );
      $this->markAllFailed($invalidations, $reason);
      return;
    }

    // LSCache accepts 200 on a successful purge; some deployments return
    // 204. Anything else is a failure worth logging. Note: HTTP 200 from
    // LSWS confirms the PURGE request was accepted, not that the entry
    // was actually evicted; on some LSWS-managed environments the
    // server-side configuration may require PURGE to land on the admin
    // port for eviction to occur. See docs/htaccess-gotchas.md.
    if ($status === 200 || $status === 204) {
      foreach ($invalidations as $invalidation) {
        $invalidation->setState(InvalidationInterface::SUCCEEDED);
      }
      return;
    }

    $reason = $this->formatNon2xxReason($status, $response, $host, $purge_header);
    $this->channelLogger->warning(
      'LSCache purge to @url (Host: @host_header) returned HTTP @status for tags: @tags. @reason',
      [
        '@url' => $host,
        '@host_header' => $host_header !== '' ? $host_header : '(Guzzle default)',
        '@status' => $status,
        '@tags' => $purge_header,
        '@reason' => $reason,
      ],
    );
    $this->markAllFailed($invalidations, $reason);
  }

  /**
   * URL-based invalidation strategy.
   *
   * Resolves each invalidation's tag to canonical Drupal paths via
   * InvalidationUrlResolver, then sends one or more PURGE requests
   * with `X-LiteSpeed-Purge: public, url=PATH, url=PATH, ...` per
   * the LiteSpeed dev guide. URLs are deduplicated across the batch
   * and chunked to stay below the header size limit.
   *
   * Unmappable tags (config:*, *_list, *_view, block_content:*, etc.)
   * are handled per the operator-configured unmappable_fallback:
   *   - `none` (default): silently drop, mark SUCCEEDED. The TTL on
   *     those entries is short by design (they are aggregate tags
   *     covering pages that update on every cron-pruned content
   *     change anyway), so dropping is safe.
   *   - `purge_all`: emit one `X-LiteSpeed-Purge: *` to evict the
   *     entire LSCache. Reserved for installs where operators want
   *     correctness over efficiency at any cost; turns any
   *     config:save or *_list invalidation into a full cache wipe.
   *
   * The whole batch's invalidations are marked SUCCEEDED on any
   * successful PURGE; per-URL granularity does not flow back to the
   * Purge framework because the framework's invalidation objects are
   * keyed by tag, not URL. If even one PURGE request in the batch
   * fails, the entire batch is marked FAILED so Purge re-queues them
   * for retry; we err on the side of duplicate PURGE work over silent
   * partial eviction.
   */
  private function invalidateByUrl(array $invalidations, string $host, string $host_header, int $timeout, string $unmappable_fallback): void {
    $prefix = $this->tagHeaderBuilder->getPrefix();

    $urls = [];
    $unmappable_seen = FALSE;

    foreach ($invalidations as $invalidation) {
      $expression = (string) $invalidation->getExpression();
      // Strip the per-install prefix before handing to the resolver;
      // the resolver works on bare entity-shaped tags. Mirrors the
      // contract documented on InvalidationUrlResolver::resolve().
      $stripped = $this->stripPrefix($expression, $prefix);
      $result = $this->urlResolver->resolve($stripped);

      if ($result->isUnmappable) {
        $unmappable_seen = TRUE;
        continue;
      }
      foreach ($result->urls as $url) {
        $urls[] = $url;
      }
    }

    if ($unmappable_seen && $unmappable_fallback === 'purge_all') {
      // Operator opted into the heavy-hand fallback. One PURGE *
      // wipes the LSCache; mark every invalidation SUCCEEDED so
      // Purge does not retry. We do this regardless of whether any
      // URL-targeted PURGEs would also have fired, because the
      // global PURGE supersedes them.
      $this->sendPurgeAll($invalidations, $host, $host_header, $timeout);
      return;
    }

    $urls = array_values(array_unique($urls));
    if ($urls === []) {
      // No mappable URLs in this batch and the fallback is `none`.
      // Mark SUCCEEDED so the queue drains; the TTL on the
      // unmapped/empty-resolved tags will evict naturally.
      foreach ($invalidations as $invalidation) {
        $invalidation->setState(InvalidationInterface::SUCCEEDED);
      }
      return;
    }

    // Per-URL PURGE: LSWS Enterprise 6.3.4 field probing (1.3.x
    // portal diagnosis) showed the X-LiteSpeed-Purge header's
    // `url=PATH` directive is silently ignored, just like `tag=NAME`.
    // The ONLY eviction signal LSWS honours on this build is the
    // PURGE request's own request URL. Batching multiple URLs into
    // one header was a non-starter; we issue one PURGE per URL
    // instead. Each request is small (no body, ~120 bytes), and
    // typical invalidation batches resolve to 1-3 URLs per entity
    // (canonical + path alias), so the request count stays
    // manageable in practice.
    foreach ($urls as $url) {
      $target = $this->joinPurgeTarget($host, $url);

      $headers = [];
      if ($host_header !== '') {
        $headers['Host'] = $host_header;
      }

      try {
        $response = $this->httpClient->request('PURGE', $target, [
          'headers' => $headers,
        ] + $this->buildRequestOptions($target, $timeout));
        $status = $response->getStatusCode();
      }
      catch (GuzzleException $e) {
        $reason = 'HTTP transport error during URL PURGE: ' . $e->getMessage();
        $this->channelLogger->warning(
          'LSCache URL-purge transport error to @url (Host: @host_header): @msg',
          [
            '@url' => $target,
            '@host_header' => $host_header !== '' ? $host_header : '(Guzzle default)',
            '@msg' => $e->getMessage(),
          ],
        );
        $this->markAllFailed($invalidations, $reason);
        return;
      }

      if ($status !== 200 && $status !== 204) {
        $reason = $this->formatNon2xxReason($status, $response, $target, $url);
        $this->channelLogger->warning(
          'LSCache URL-purge to @url (Host: @host_header) returned HTTP @status. @reason',
          [
            '@url' => $target,
            '@host_header' => $host_header !== '' ? $host_header : '(Guzzle default)',
            '@status' => $status,
            '@reason' => $reason,
          ],
        );
        $this->markAllFailed($invalidations, $reason);
        return;
      }
    }

    foreach ($invalidations as $invalidation) {
      $invalidation->setState(InvalidationInterface::SUCCEEDED);
    }
  }

  /**
   * Emits one PURGE * and marks the batch SUCCEEDED.
   *
   * Used by the `purge_all` unmappable fallback. LSCache treats the
   * literal `*` as a wildcard that evicts every entry the LSWS
   * instance is holding for this site. Heavy hammer; documented as
   * such on the settings form so operators do not surprise
   * themselves.
   */
  private function sendPurgeAll(array $invalidations, string $host, string $host_header, int $timeout): void {
    $headers = ['X-LiteSpeed-Purge' => '*'];
    if ($host_header !== '') {
      $headers['Host'] = $host_header;
    }

    try {
      $response = $this->httpClient->request('PURGE', $host, [
        'headers' => $headers,
      ] + $this->buildRequestOptions($host, $timeout));
      $status = $response->getStatusCode();
    }
    catch (GuzzleException $e) {
      $this->markAllFailed($invalidations, 'HTTP transport error during PURGE *: ' . $e->getMessage());
      return;
    }

    if ($status !== 200 && $status !== 204) {
      $reason = $this->formatNon2xxReason($status, $response, $host, '*');
      $this->markAllFailed($invalidations, $reason);
      return;
    }

    foreach ($invalidations as $invalidation) {
      $invalidation->setState(InvalidationInterface::SUCCEEDED);
    }
  }

  /**
   * Resolves the configured strategy to a concrete 'tag' or 'url'.
   *
   * Delegates to StrategyResolver so the runtime and the status report
   * row can never disagree. `auto` is safe by default: it resolves to
   * the always-works URL strategy until a probe (drush lscache:diag or
   * the automatic cron probe) confirms tag-PURGE evicts on this build.
   */
  private function resolveStrategy(string $configured): string {
    return StrategyResolver::resolve(
      $configured,
      $this->state->get('lscache_purger.tag_purge_effective'),
    );
  }

  /**
   * Strips the configured prefix from a prefixed tag expression.
   *
   * The Purge framework hands the purger raw cache tags (no prefix);
   * the prefix is something we apply on emit. In the URL-strategy
   * branch we still go through this helper because tests pass
   * expression strings as they appear in the framework, which means
   * no prefix; the helper is a no-op in that case. The defensive
   * shape is here for the day someone changes upstream Purge to hand
   * us prefixed tags.
   */
  private function stripPrefix(string $expression, string $prefix): string {
    if ($prefix === '') {
      return $expression;
    }
    if (str_starts_with($expression, $prefix)) {
      return substr($expression, strlen($prefix));
    }
    return $expression;
  }

  /**
   * Builds an operator-actionable reason string for an HTTP non-2xx purge.
   *
   * Specifically detects intermediary CDN rejection patterns (Cloudflare's
   * HTTP 400 + CF-Ray signature is the most common one operators hit when
   * they configure purge_host as the public site URL instead of an
   * origin-direct URL) so the surfaced reason explains the cause rather
   * than just the status code.
   */
  private function formatNon2xxReason(int $status, $response, string $host, string $purge_header): string {
    $cf_ray = $response->getHeaderLine('CF-Ray');
    $server = $response->getHeaderLine('Server');

    if ($status === 400 && $cf_ray !== '') {
      return sprintf(
        'PURGE rejected by Cloudflare (HTTP 400, CF-Ray: %s). The purge_host (%s) routes through a Cloudflare edge, which rejects the PURGE method before it reaches LSWS. Set purge_host to an origin-direct URL such as http://localhost/ or your origin server\'s internal hostname.',
        $cf_ray,
        $host,
      );
    }

    if ($status >= 400 && $status < 500) {
      return sprintf(
        'PURGE rejected with HTTP %d (server: %s). If a CDN sits in front of %s it likely rejected the non-standard PURGE method; switch to an origin-direct purge_host.',
        $status,
        $server !== '' ? $server : 'unknown',
        $host,
      );
    }

    return sprintf(
      'PURGE returned HTTP %d for tags %s (server: %s). Verify the purge_host is reachable from this server and that LSWS is configured to accept PURGE for the configured tags.',
      $status,
      $purge_header,
      $server !== '' ? $server : 'unknown',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getIdealConditionsLimit(): int {
    return 100;
  }

  /**
   * {@inheritdoc}
   */
  public function getTimeHint(): float {
    return 0.4;
  }

  /**
   * {@inheritdoc}
   */
  public function hasRuntimeMeasurement(): bool {
    return FALSE;
  }

  /**
   * Applies the same prefix logic as the response header builder.
   *
   * The purger must emit prefixed tags identical to what the response
   * subscriber stored with LSCache; otherwise LSCache will look up a
   * non-existent tag and no responses will be evicted. Delegates to
   * TagHeaderBuilder::getPrefix() so the two cannot drift.
   */
  private function prefixTag(string $raw_tag): string {
    $raw_tag = trim($raw_tag);
    if ($raw_tag === '') {
      return '';
    }
    return str_replace(',', '', $this->tagHeaderBuilder->getPrefix() . $raw_tag);
  }

  /**
   * Resolves the LSCache host URL to send the PURGE to.
   *
   * Tries three sources in order:
   *   1. The `purge_host` config value if set explicitly.
   *   2. The current Drupal request host via RequestStack. Available in
   *      web context but null in CLI/cron context.
   *   3. The site's `$base_url` from settings.php / Drupal::request()'s
   *      base. Available in many CLI configurations where the operator
   *      has set $base_url.
   *
   * Returns the empty string if none of the three are available, in
   * which case the caller fails with an actionable error pointing the
   * operator at the `purge_host` config key.
   */
  private function resolveHost(string $configured_host): string {
    if ($configured_host !== '') {
      return rtrim($configured_host, '/') . '/';
    }

    // Web context: RequestStack has the current request.
    $request = $this->requestStack->getCurrentRequest();
    if ($request !== NULL) {
      $host = $request->getSchemeAndHttpHost();
      if ($host !== '') {
        return rtrim($host, '/') . '/';
      }
    }

    // CLI/cron context: fall back to the site's configured $base_url.
    // Many operators set this in settings.php for other CLI tools (drush
    // generate-url, mail tokens) so it's frequently available without
    // additional configuration.
    $base_url = $GLOBALS['base_url'] ?? '';
    if (is_string($base_url) && $base_url !== '') {
      return rtrim($base_url, '/') . '/';
    }

    return '';
  }

  /**
   * Sets every invalidation in the batch to FAILED with a logged reason.
   *
   * Logs to watchdog for operators monitoring dblog AND, when a
   * messenger service is present (interactive web or drush contexts),
   * surfaces the reason as an inline error message so the operator
   * sees it without having to check dblog. The 1.3.0-rc1 field test
   * surfaced exactly the gap this addresses: operators running
   * `drush p:invalidate` saw only "FAILED" and missed the watchdog
   * warning that named the actual cause.
   *
   * @param \Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface[] $invalidations
   *   The batch to mark failed.
   * @param string $reason
   *   Reason to log at WARNING severity for operator visibility.
   */
  private function markAllFailed(array $invalidations, string $reason): void {
    $this->channelLogger->warning('LSCache purge aborted: @reason', ['@reason' => $reason]);
    // PluginBase mixes in MessengerTrait, so $this->messenger() is
    // available without explicit injection. Adding the error makes the
    // cause visible to drush and admin UI invocations without requiring
    // a separate dblog check (the 1.3.0-rc1 field-test gap).
    $this->messenger()->addError(t('LSCache purge aborted: @reason', ['@reason' => $reason]));
    foreach ($invalidations as $invalidation) {
      $invalidation->setState(InvalidationInterface::FAILED);
    }
  }

}
