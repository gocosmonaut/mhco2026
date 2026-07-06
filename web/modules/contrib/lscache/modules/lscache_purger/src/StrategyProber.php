<?php

declare(strict_types=1);

namespace Drupal\lscache_purger;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\lscache\TagHeaderBuilder;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Detects whether tag-based PURGE actually evicts on this LiteSpeed build.
 *
 * This is the headless counterpart to the lscache:diag drush command: it
 * runs the same prime-then-tag-PURGE probe but returns a result rather
 * than printing one, so cron can call it and make the `auto` strategy
 * self-correct with no operator action. It deliberately probes only
 * tag-PURGE effectiveness, the single signal StrategyResolver needs; the
 * drush command remains the richer interactive tool that also reports
 * URL-PURGE and a recommendation. The low-level prime/purge/GET helpers
 * mirror that command; they are kept separate here so the auto-probe does
 * not disturb the command's (legacy-discovered) service wiring, which is
 * fragile. A future cleanup could unify them behind this service.
 */
class StrategyProber {

  /**
   * Milliseconds to wait after a PURGE before re-reading cache state.
   */
  private const SETTLE_MS = 200;

  private const STATE_EFFECTIVE = 'lscache_purger.tag_purge_effective';
  private const STATE_PROBED_AT = 'lscache_purger.tag_purge_probed_at';
  private const STATE_LAST_ATTEMPT = 'lscache_purger.auto_probe_last_attempt';

  /**
   * Seconds between auto-probe attempts when the probe keeps failing.
   */
  private const ATTEMPT_THROTTLE = 86400;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TagHeaderBuilder $tagHeaderBuilder,
    private readonly StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Auto-probes from cron when `auto` is active and has never been probed.
   *
   * Gated so it costs nothing on the common path: it returns immediately
   * unless the strategy is `auto` and no probe result exists yet, and it
   * throttles attempts so an unreachable purge_host does not re-probe on
   * every cron run.
   */
  public function maybeAutoProbe(): void {
    $configured = (string) ($this->configFactory->get('lscache_purger.settings')->get('purge_strategy') ?? 'auto');
    if ($configured !== 'auto') {
      return;
    }
    // Already probed (TRUE or FALSE): nothing to do.
    if ($this->state->get(self::STATE_EFFECTIVE) !== NULL) {
      return;
    }
    $now = $this->time->getCurrentTime();
    $last = (int) ($this->state->get(self::STATE_LAST_ATTEMPT) ?? 0);
    if ($now - $last < self::ATTEMPT_THROTTLE) {
      return;
    }
    $this->state->set(self::STATE_LAST_ATTEMPT, $now);
    $this->probeTagEffectiveness();
  }

  /**
   * Probes tag-PURGE effectiveness and persists the result.
   *
   * @return bool|null
   *   TRUE if tag-PURGE evicted, FALSE if it did not, NULL if the probe
   *   was inconclusive (no purge_host, no probeable node, could not prime,
   *   or a transport error). On a TRUE/FALSE result the value is written
   *   to state so StrategyResolver picks it up.
   */
  public function probeTagEffectiveness(): ?bool {
    $config = $this->configFactory->get('lscache_purger.settings');

    $purge_host = rtrim((string) ($config->get('purge_host') ?? ''), '/');
    if ($purge_host === '') {
      $this->logger->notice('Auto-probe skipped: no purge_host configured.');
      return NULL;
    }

    $host_header = trim((string) ($config->get('purge_host_header') ?? ''));
    if ($host_header === '') {
      $base_url = $GLOBALS['base_url'] ?? '';
      $host_header = is_string($base_url) ? (string) parse_url($base_url, PHP_URL_HOST) : '';
    }
    if ($host_header === '') {
      $this->logger->notice('Auto-probe skipped: cannot determine the Host header to send.');
      return NULL;
    }

    $target = $this->firstPublishedNode();
    if ($target === NULL) {
      $this->logger->notice('Auto-probe skipped: no published node available to probe.');
      return NULL;
    }
    $target_url = $purge_host . $target['path'];
    $tag = $this->tagHeaderBuilder->getPrefix() . 'node:' . $target['nid'];

    // Prime the target to a known HIT state.
    $this->purge($target_url, $host_header, NULL);
    $this->get($target_url, $host_header);
    if ($this->get($target_url, $host_header) !== 'hit') {
      $this->logger->notice('Auto-probe inconclusive: target @t did not reach HIT after priming.', ['@t' => $target['path']]);
      return NULL;
    }

    // Tag-PURGE the root with the tag the target carries, then re-read.
    $this->purge($purge_host . '/', $host_header, 'public, tag=' . $tag);
    usleep(self::SETTLE_MS * 1000);
    $works = ($this->get($target_url, $host_header) === 'miss');

    // Clean up so the next visitor does not see whatever we left cached.
    $this->purge($target_url, $host_header, NULL);

    $this->state->set(self::STATE_EFFECTIVE, $works);
    $this->state->set(self::STATE_PROBED_AT, $this->time->getCurrentTime());
    $this->logger->notice('Auto-probe: tag-PURGE @result on this LiteSpeed build; `auto` will use the @strategy strategy.', [
      '@result' => $works ? 'works' : 'is ineffective',
      '@strategy' => $works ? 'tag' : 'URL',
    ]);
    return $works;
  }

  /**
   * Sends a PURGE request, swallowing transport errors.
   */
  private function purge(string $url, string $host_header, ?string $x_litespeed_purge): void {
    $headers = ['Host' => $host_header];
    if ($x_litespeed_purge !== NULL) {
      $headers['X-LiteSpeed-Purge'] = $x_litespeed_purge;
    }
    try {
      $this->httpClient->request('PURGE', $url, [
        'headers' => $headers,
        'timeout' => 5,
        'http_errors' => FALSE,
        // Loopback purge hosts cannot carry a publicly trusted certificate;
        // this matches the diag command and the runtime purger.
        'verify' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->warning('Auto-probe PURGE @u failed: @m', ['@u' => $url, '@m' => $e->getMessage()]);
    }
  }

  /**
   * GETs a URL and returns the lowercased X-LiteSpeed-Cache value.
   */
  private function get(string $url, string $host_header): string {
    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => ['Host' => $host_header],
        'timeout' => 10,
        'http_errors' => FALSE,
        'verify' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      return 'unknown';
    }
    $value = strtolower((string) $response->getHeaderLine('X-LiteSpeed-Cache'));
    return $value !== '' ? $value : 'unknown';
  }

  /**
   * Returns the first published node as ['nid' => int, 'path' => string].
   *
   * The canonical /node/NID path is used (not an alias) so the probe tests
   * LSWS tag semantics rather than Drupal's path alias resolution, and the
   * node:NID tag is statically known.
   */
  private function firstPublishedNode(): ?array {
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
    return ['nid' => (int) $nid, 'path' => '/node/' . (int) $nid];
  }

}
