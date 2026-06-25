<?php

declare(strict_types=1);

namespace Drupal\lscache_purger\Commands;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\lscache\TagHeaderBuilder;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command suite for diagnosing LSCache purger behaviour in situ.
 *
 * The headline command is `drush lscache:diag`, which runs a
 * single-URL probe that asks the connected LSWS instance two
 * questions:
 *
 *   1. Does URL-based PURGE work? (PURGE /path)
 *   2. Does tag-based PURGE work? (PURGE / with X-LiteSpeed-Purge:
 *      tag=NAME for a tag actually associated with /path's response.)
 *
 * The result of (2) is persisted in state under
 * `lscache_purger.tag_purge_effective` (bool) plus
 * `lscache_purger.tag_purge_probed_at` (unix ts). The purger's `auto`
 * strategy reads that state value to decide between the tag and URL
 * PURGE implementations on the next invalidation; the status report
 * row uses the same value to surface effectiveness to operators.
 *
 * The diagnostic is intentionally self-contained: it issues HTTP
 * requests directly via Guzzle (the same client the purger uses) and
 * does not depend on the Purge framework's queue/processor pipeline,
 * because the whole point is to characterise LSWS behaviour
 * independently of Drupal's view of what should have been evicted.
 *
 * Background: tag-based PURGE was found to be silently ineffective on
 * LSWS Enterprise 6.3.4 (UKHost4U/Jelastic-hosted portal). HTTP 200
 * comes back but no eviction occurs. The only safe way to tell tag-
 * works from tag-doesn't-work is to probe with a known cached URL
 * and observe whether the post-purge response is HIT or MISS.
 */
class LscacheDiagCommands extends DrushCommands {

  /**
   * Max wait between PURGE and re-fetch, in milliseconds.
   *
   * LSWS evicts synchronously on accept of PURGE; in field testing the
   * follow-up GET sees the new state instantly. The sleep here is
   * defensive padding for LSWS configurations where eviction is
   * actually queued (rare; observed on one OpenLiteSpeed dev install
   * in the alpha cycle). 200ms is invisible in CLI time but enough
   * for any reasonable eviction queue to drain.
   */
  private const POST_PURGE_SETTLE_MS = 200;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TagHeaderBuilder $tagHeaderBuilder,
    private readonly StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {
    parent::__construct();
  }

  /**
   * Instantiates the command from the service container.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('http_client'),
      $container->get('config.factory'),
      $container->get('lscache.tag_header_builder'),
      $container->get('state'),
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Probe LSWS to detect whether tag-based PURGE actually evicts.
   *
   * Picks a known-cacheable URL (defaults to the first published node
   * on the site, or accepts an explicit --url), primes the cache,
   * then issues both URL- and tag-scoped PURGE requests, observing
   * whether each evicts the cached entry. Writes the result to state
   * so the purger's `auto` strategy and the status report row can
   * use it.
   *
   * The cache state machine for this probe:
   *
   *   1. URL-PURGE the target to ensure a known-clean starting state.
   *   2. GET the target. Drupal renders + LSWS caches. Expect MISS.
   *   3. GET again to confirm the entry is now cached. Expect HIT.
   *   4. URL-PURGE the target. GET. Expect MISS. -> url_purge_works.
   *   5. GET twice to re-prime; confirm HIT.
   *   6. Tag-PURGE / with X-LiteSpeed-Purge: tag=PREFIXED_NAME for a
   *      tag the response is known to carry (the node:NID tag for a
   *      /node/NID target). GET the target. If MISS, tag-PURGE
   *      works; if HIT, tag-PURGE is silently a no-op.
   *   7. Clean up: URL-PURGE the target so we do not leave a stale
   *      hit lying around for the next visitor.
   */
  #[CLI\Command(name: 'lscache:diag', aliases: ['lscache-diag'])]
  #[CLI\Option(name: 'url', description: 'Path to probe (defaults to /node/<first published nid>).')]
  #[CLI\Option(name: 'host', description: 'Host header to send (defaults to the canonical site host from purge_host_header config or $base_url).')]
  #[CLI\Option(name: 'purge-host', description: 'PURGE target URL (defaults to lscache_purger.settings:purge_host).')]
  #[CLI\Option(name: 'no-save', description: 'Run the probe but do not persist the result to state.')]
  #[CLI\Usage(name: 'drush lscache:diag', description: 'Probe with defaults from active config and persist the result.')]
  #[CLI\Usage(name: 'drush lscache:diag --url=/about-us --no-save', description: 'Probe a specific alias without writing state.')]
  public function diag(
    array $options = [
      'url' => self::REQ,
      'host' => self::REQ,
      'purge-host' => self::REQ,
      'no-save' => FALSE,
    ],
  ): int {
    $purger_config = $this->configFactory->get('lscache_purger.settings');

    $purge_host = (string) ($options['purge-host'] ?? $purger_config->get('purge_host') ?? '');
    if ($purge_host === '') {
      $this->logger()->error('No purge_host configured and --purge-host not supplied; cannot probe.');
      return self::EXIT_FAILURE;
    }
    $purge_host = rtrim($purge_host, '/') . '/';

    $host_header = trim((string) ($options['host'] ?? $purger_config->get('purge_host_header') ?? ''));
    if ($host_header === '') {
      // Best-effort fall back to $base_url's host; not fatal if absent.
      $base_url = $GLOBALS['base_url'] ?? '';
      $host_header = is_string($base_url) ? (string) parse_url($base_url, PHP_URL_HOST) : '';
    }
    if ($host_header === '') {
      $this->logger()->error('Cannot determine Host header to send. Set lscache_purger.settings:purge_host_header or pass --host.');
      return self::EXIT_FAILURE;
    }

    $target = $this->resolveTargetUrl($options['url'] ?? NULL);
    if ($target === NULL) {
      $this->logger()->error('Could not pick a target URL. Pass --url=/some/path explicitly, or publish at least one node.');
      return self::EXIT_FAILURE;
    }

    $this->output()->writeln(sprintf(
      'Probing LSCache via PURGE %s with Host: %s',
      $purge_host,
      $host_header,
    ));
    $this->output()->writeln(sprintf('Target URL: %s', $target));

    // Scheme-mismatch advisory: would belong here, but CLI cannot
    // reliably infer real traffic scheme. Drush bootstraps a default
    // Request that synthesises an http URL even on HTTPS-only sites
    // (1.3.x portal-dev validation surfaced exactly this false
    // positive). The status report row in lscache_purger_requirements
    // is the right surface for the warning: it runs in real web
    // context where the scheme is unambiguous.
    // Step 1+2+3: clear, prime, confirm HIT.
    $this->purgeUrl($purge_host, $host_header, $target);
    $this->getCache($purge_host, $host_header, $target);
    $confirm_hit = $this->getCache($purge_host, $host_header, $target);
    if ($confirm_hit !== 'hit') {
      $this->logger()->warning(sprintf(
        'Target URL did not enter HIT state after two consecutive GETs (saw "%s"). Cannot probe PURGE reliably. Pick a URL with stable public caching: drush lscache:diag --url=/known-cacheable-path.',
        $confirm_hit,
      ));
      return self::EXIT_FAILURE;
    }
    $this->output()->writeln('Pre-probe state: HIT (cache primed).');

    // Step 4: URL-PURGE -> expect MISS. The very first PURGE after a
    // cache rebuild (drush cr) can land before LSWS has settled, leaving
    // the primed entry in place and reporting a false INEFFECTIVE on the
    // first probe. Retry once: a warm-up artifact resolves to MISS on the
    // second attempt, while a genuinely ineffective build stays HIT.
    $this->purgeUrl($purge_host, $host_header, $target);
    $this->settle();
    $after_url_purge = $this->getCache($purge_host, $host_header, $target);
    if ($after_url_purge !== 'miss') {
      $this->purgeUrl($purge_host, $host_header, $target);
      $this->settle();
      $after_url_purge = $this->getCache($purge_host, $host_header, $target);
    }
    $url_purge_works = ($after_url_purge === 'miss');
    $this->output()->writeln(sprintf(
      'URL-PURGE %s -> next GET: %s -> URL-PURGE %s',
      $target,
      strtoupper($after_url_purge),
      $url_purge_works ? 'WORKS' : 'INEFFECTIVE',
    ));

    // Step 5: re-prime for the tag probe.
    $this->getCache($purge_host, $host_header, $target);
    $reprimed = $this->getCache($purge_host, $host_header, $target);
    if ($reprimed !== 'hit') {
      $this->logger()->warning('Cache did not re-prime after URL-PURGE. Skipping tag-PURGE probe.');
      return self::EXIT_FAILURE;
    }

    // Step 6: tag-PURGE / with a tag known to be on the response.
    $tag_for_target = $this->expectedTagForTarget($target);
    if ($tag_for_target === NULL) {
      $this->logger()->warning(sprintf(
        'Could not derive an expected tag for %s; cannot probe tag-PURGE. Use a /node/NID target.',
        $target,
      ));
      return self::EXIT_FAILURE;
    }
    $this->output()->writeln(sprintf('Tag for target: %s', $tag_for_target));
    $this->purgeTag($purge_host, $host_header, $tag_for_target);
    $this->settle();
    $after_tag_purge = $this->getCache($purge_host, $host_header, $target);
    $tag_purge_works = ($after_tag_purge === 'miss');

    // Step 7: clean up - re-clear the target so the next visitor does
    // not see whatever we left in cache.
    $this->purgeUrl($purge_host, $host_header, $target);

    $this->output()->writeln(sprintf(
      'Tag-PURGE %s -> next GET: %s -> tag-PURGE %s',
      $tag_for_target,
      strtoupper($after_tag_purge),
      $tag_purge_works ? 'WORKS' : 'INEFFECTIVE',
    ));

    if (empty($options['no-save'])) {
      $this->state->set('lscache_purger.tag_purge_effective', $tag_purge_works);
      $this->state->set('lscache_purger.url_purge_effective', $url_purge_works);
      $this->state->set('lscache_purger.tag_purge_probed_at', $this->time->getCurrentTime());
      $this->output()->writeln('Probe result persisted to state.');
    }
    else {
      $this->output()->writeln('--no-save: probe result NOT persisted.');
    }

    $configured_strategy = (string) ($purger_config->get('purge_strategy') ?? 'auto');
    $this->output()->writeln('');
    $this->output()->writeln(sprintf(
      'Configured strategy: %s. Recommendation: %s.',
      $configured_strategy,
      $this->recommendation($url_purge_works, $tag_purge_works, $configured_strategy),
    ));

    return self::EXIT_SUCCESS;
  }

  /**
   * Issues a PURGE for a single URL. Returns the HTTP status (or 0).
   *
   * URL-scoped PURGE is the LSWS-universal path: every LSWS build we
   * have probed honours PURGE /some/path regardless of tag-feature
   * support. This is also what the URL strategy in LscachePurger
   * leans on.
   */
  private function purgeUrl(string $purge_host, string $host_header, string $url): int {
    return $this->sendPurge(
      rtrim($purge_host, '/') . $url,
      $host_header,
      // Empty X-LiteSpeed-Purge header on a URL-scoped PURGE is the
      // shape LiteSpeed's own docs describe; the request URL alone
      // identifies what to evict.
      NULL,
    );
  }

  /**
   * Issues a tag-scoped PURGE / with X-LiteSpeed-Purge: tag=NAME.
   *
   * The PURGE URL is the site root; the tag header is the scope. This
   * is the shape lscache_purger's `tag` strategy emits (modulo single-
   * vs-batch tag count), so the probe matches the production code
   * path being evaluated.
   */
  private function purgeTag(string $purge_host, string $host_header, string $tag): int {
    return $this->sendPurge(
      $purge_host,
      $host_header,
      'public, tag=' . $tag,
    );
  }

  /**
   * Sends a PURGE request and returns the HTTP status (0 on transport error).
   */
  private function sendPurge(string $url, string $host_header, ?string $x_litespeed_purge): int {
    $headers = ['Host' => $host_header];
    if ($x_litespeed_purge !== NULL) {
      $headers['X-LiteSpeed-Purge'] = $x_litespeed_purge;
    }
    try {
      $response = $this->httpClient->request('PURGE', $url, [
        'headers' => $headers,
        'timeout' => 5,
        'http_errors' => FALSE,
        'verify' => FALSE,
      ]);
      return $response->getStatusCode();
    }
    catch (GuzzleException $e) {
      $this->logger()->warning(sprintf('PURGE %s transport error: %s', $url, $e->getMessage()));
      return 0;
    }
  }

  /**
   * GETs a URL via the PURGE host and returns the X-LiteSpeed-Cache value.
   *
   * Returns lowercase status string ("hit", "miss", "unknown"). Uses
   * the same Host header override as the PURGE path, so the GET and
   * PURGE hit the same LSWS cache bucket.
   */
  private function getCache(string $purge_host, string $host_header, string $url): string {
    try {
      $response = $this->httpClient->request('GET', rtrim($purge_host, '/') . $url, [
        'headers' => ['Host' => $host_header],
        'timeout' => 10,
        'http_errors' => FALSE,
        'verify' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger()->warning(sprintf('GET %s transport error: %s', $url, $e->getMessage()));
      return 'unknown';
    }
    $header_value = strtolower((string) $response->getHeaderLine('X-LiteSpeed-Cache'));
    return $header_value !== '' ? $header_value : 'unknown';
  }

  /**
   * Sleeps POST_PURGE_SETTLE_MS without blocking the user perceptibly.
   */
  private function settle(): void {
    usleep(self::POST_PURGE_SETTLE_MS * 1000);
  }

  /**
   * Chooses a target URL when --url is not passed.
   *
   * Picks the first published node id and returns "/node/{nid}". A
   * canonical path (not the alias) is intentional: it removes the
   * alias-resolution variable from the probe so we are testing LSWS
   * tag/URL semantics, not Drupal's path alias module.
   */
  private function resolveTargetUrl(?string $explicit): ?string {
    if (is_string($explicit) && $explicit !== '') {
      return '/' . ltrim($explicit, '/');
    }
    try {
      $nids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->range(0, 1)
        ->execute();
    }
    catch (\Throwable $e) {
      return NULL;
    }
    $nid = reset($nids);
    if ($nid === FALSE) {
      return NULL;
    }
    return '/node/' . (int) $nid;
  }

  /**
   * Computes the prefixed tag we expect on a /node/NID response.
   *
   * Uses TagHeaderBuilder::getPrefix() so the probe sends exactly the
   * tag the response subscriber emitted, including the per-install
   * prefix. Returns NULL for non-node URLs because the tag set on a
   * non-canonical alias path is harder to predict statically; the
   * operator can re-run with --url=/node/NID for those cases.
   */
  private function expectedTagForTarget(string $target): ?string {
    if (!preg_match('#^/node/(\d+)$#', $target, $m)) {
      return NULL;
    }
    return $this->tagHeaderBuilder->getPrefix() . 'node:' . $m[1];
  }

  /**
   * Recommends a strategy based on probe results + configured value.
   */
  private function recommendation(bool $url_works, bool $tag_works, string $configured): string {
    if (!$url_works && !$tag_works) {
      return 'Neither URL- nor tag-PURGE evicted in this probe. PURGE may be blocked at the network layer (firewall, CDN, intermediary). Verify PURGE traffic reaches LSWS at all.';
    }
    if ($tag_works) {
      return sprintf(
        'Tag-PURGE works; `tag` or `auto` are both fine (active config: %s).',
        $configured,
      );
    }
    // Tag-PURGE doesn't work; URL does.
    if ($configured === 'tag') {
      return 'Tag-PURGE is ineffective on this LSWS build but the strategy is hard-set to `tag`. Switch to `auto` (recommended) or `url`: drush config:set lscache_purger.settings purge_strategy auto -y.';
    }
    return sprintf(
      'Tag-PURGE is ineffective on this LSWS build. Active strategy `%s` is already a safe choice; the auto path resolves to `url` going forward.',
      $configured,
    );
  }

}
