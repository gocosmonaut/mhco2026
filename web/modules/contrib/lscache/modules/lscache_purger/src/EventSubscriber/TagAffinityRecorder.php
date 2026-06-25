<?php

declare(strict_types=1);

namespace Drupal\lscache_purger\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\lscache\TagHeaderBuilder;
use Drupal\lscache_purger\Affinity\TagAffinityStore;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records X-LiteSpeed-Tag emissions into the affinity store.
 *
 * Runs at priority -1000 on kernel.response, intentionally one step
 * below LscacheResponseSubscriber's -999. Sequencing matters: the
 * parent subscriber decides whether the response is cacheable, runs
 * the tag filter, applies the per-install prefix, and writes the
 * X-LiteSpeed-Tag header. This subscriber reads that finalised
 * header value, so the affinity store reflects exactly what LSWS
 * will key the cached entry by; we cannot recompute the tag set
 * here because re-running the build would double-prefix or
 * incorrectly re-filter.
 *
 * Skip conditions mirror the parent subscriber's skip conditions
 * (non-main request, non-cacheable response, admin route, BigPipe-
 * placeholder body, no tags emitted), expressed here as a single
 * "no X-LiteSpeed-Tag header on the response" check. That delegates
 * the decision to the parent's logic without re-implementing it.
 *
 * The per-install prefix is stripped before writing, because the
 * resolver queries by bare expressions (`node:38`) that the Purge
 * framework hands it directly. Storing prefixed tags would force
 * every query to re-prefix, which is fragile and surprising.
 *
 * Background: the 1.3.x portal-master diagnosis showed URL-
 * strategy mode cannot evict view/listing pages because aggregate
 * tags like node_list have no canonical URL. This recorder is the
 * write half of the auto-affinity workaround; the resolver's
 * affinity consult is the read half. See TagAffinityStore docblock
 * for the design rationale.
 */
final class TagAffinityRecorder implements EventSubscriberInterface {

  public function __construct(
    private readonly TagAffinityStore $store,
    private readonly TagHeaderBuilder $tagHeaderBuilder,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Priority -1000: one step after LscacheResponseSubscriber's
      // -999. Ensures the parent has finalised X-LiteSpeed-Tag
      // before we read it.
      KernelEvents::RESPONSE => ['onResponse', -1000],
    ];
  }

  /**
   * Records the response's tags against its URL in the affinity store.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $response = $event->getResponse();

    // Gate on the X-LiteSpeed-Cache-Control header: its presence is the
    // signal that LscacheResponseSubscriber brought this response under
    // LSCache management (it passed the uncacheable / admin-route /
    // BigPipe checks and reached the public or private branch). We only
    // want affinity rows for URLs LSWS actually caches.
    //
    // Earlier betas gated on X-LiteSpeed-Tag instead. That missed the
    // controller-rendered listing pages this affinity table exists to
    // cover: their cache tags are aggregate (node_list, *_list,
    // config:views.*) and all filter out of the emitted tag header, so
    // they carry X-LiteSpeed-Cache-Control (LSWS manages + URL-purges
    // them) but NO X-LiteSpeed-Tag - yet their UNFILTERED metadata is
    // precisely the *_list affinity the resolver needs to map node_list
    // back to /help. A `no-cache` value is the active BigPipe /
    // uncacheable suppression signal; skip those.
    $cache_control = (string) $response->headers->get('X-LiteSpeed-Cache-Control', '');
    if ($cache_control === '' || str_contains($cache_control, 'no-cache')) {
      return;
    }

    $request = $event->getRequest();
    $url = $request->getPathInfo();
    // Drop URLs that look like query-bearing URLs from the affinity
    // map: LSWS caches them, but PURGE on the bare path will not
    // evict every variant. Better to leave them out than emit
    // half-correct PURGE plans.
    if ($url === '' || str_contains($url, '?')) {
      return;
    }

    // Record from the response's UNFILTERED cacheable metadata, not
    // the X-LiteSpeed-Tag header. The header has already been through
    // the parent module's tag_filter, which strips the aggregate tags
    // (node_list, config:views.view.*, etc.) the affinity table needs
    // most: those are precisely the tags that fire when NEW content is
    // published into a listing. The 1.3.x portal-stage review
    // caught this: /patch-notes recorded the individual node:N tags of
    // listed articles, so editing a listed node evicted the page, but
    // publishing a NEW node (which fires node_list, never node:N for
    // the not-yet-listed node) left the listing stale. Reading the
    // pre-filter metadata captures node_list so new publishes evict
    // the listing too.
    $tags = $this->cacheableTags($response);
    if ($tags === []) {
      // Fall back to the header (already prefix-stripped) when the
      // response is not a CacheableResponseInterface, e.g. a plain
      // Response that some controller built by hand but that still
      // carries an X-LiteSpeed-Tag set upstream.
      $tags = $this->stripPrefixFromAll((string) $response->headers->get('X-LiteSpeed-Tag', ''));
    }

    $tags = array_values(array_filter($tags, [$this, 'shouldRecord']));
    if ($tags === []) {
      return;
    }

    $this->store->recordTagsForUrl(
      $url,
      $tags,
      $this->time->getCurrentTime(),
    );
  }

  /**
   * Returns the response's unfiltered Drupal cache tags, or [].
   *
   * These are bare Drupal cache tags (no per-install prefix) straight
   * off the response's cacheability metadata, so no prefix-stripping
   * is needed; the affinity store and the resolver both key on bare
   * expressions. Returns [] for responses that do not carry cacheable
   * metadata (caller then falls back to the emitted header).
   *
   * @return string[]
   *   Bare Drupal cache tags from the response's cacheability
   *   metadata, or an empty array for non-cacheable responses.
   */
  private function cacheableTags(Response $response): array {
    if (!$response instanceof CacheableResponseInterface) {
      return [];
    }
    return $response->getCacheableMetadata()->getCacheTags();
  }

  /**
   * Decides whether a tag is worth an affinity row.
   *
   * Records only the tags the resolver can actually act on, and skips
   * the high-cardinality universal tags that would otherwise put a row
   * on EVERY cached URL (http_response, rendered) and bloat the table
   * without buying any useful eviction precision.
   *
   * Recorded:
   *   - entity tags `type:id` (node:38, taxonomy_term:5, media:7,
   *     block_content:10) - the resolver maps these to canonical URLs
   *     directly, but recording them also lets a block/media that has
   *     no canonical URL resolve to the pages it appears on.
   *   - list tags `*_list` (node_list, taxonomy_term_list) AND their
   *     bundle-specific form `*_list:bundle` (node_list:help_article) -
   *     the aggregates that fire when content is added to or removed
   *     from a listing; the reason this whole pass reads pre-filter
   *     tags. Recording the bundle-specific form lets affinity self-
   *     cover a bundle listing without an operator static_url_map pin,
   *     since that map keys on exactly the bundle-specific tag.
   *   - view config tags `config:views.view.*` - fire when a Views
   *     listing's configuration changes.
   *
   * Skipped: http_response, rendered, lscache_esi, the
   * CACHE_MISS_IF_* pseudo-tag, webform:*, and broad config:* keys
   * like config:system.site (invalidating those legitimately means
   * "almost everything", which the operator handles with a full
   * purge, not per-URL affinity).
   */
  private function shouldRecord(string $tag): bool {
    // entity:id - lowercase entity type, colon, all-digit id.
    if (preg_match('/^[a-z][a-z0-9_]*:\d+$/', $tag) === 1) {
      return TRUE;
    }
    // Bare list aggregate (node_list) or its bundle-specific form
    // (node_list:help_article). str_contains('_list:') catches the
    // latter without also matching unrelated colon-bearing tags.
    if (str_ends_with($tag, '_list') || str_contains($tag, '_list:')) {
      return TRUE;
    }
    if (str_starts_with($tag, 'config:views.view.')) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Splits the X-LiteSpeed-Tag header and strips the per-install prefix.
   *
   * The parent subscriber emits a comma-separated list of prefixed
   * tag expressions (e.g. "p:node:38,p:node_list"). The Purge
   * framework hands the resolver bare expressions, so the affinity
   * store keys on bare expressions; this method does the splitting
   * and stripping in one pass for the write side to match.
   *
   * @return string[]
   *   Bare tag expressions, deduplicated and trimmed. Empty array
   *   if every entry was empty or shorter than the prefix.
   */
  private function stripPrefixFromAll(string $tag_header): array {
    $prefix = $this->tagHeaderBuilder->getPrefix();
    $prefix_len = strlen($prefix);
    $entries = array_map('trim', explode(',', $tag_header));
    $bare = [];
    foreach ($entries as $entry) {
      if ($entry === '') {
        continue;
      }
      if ($prefix !== '' && str_starts_with($entry, $prefix)) {
        $entry = substr($entry, $prefix_len);
      }
      if ($entry !== '') {
        $bare[$entry] = TRUE;
      }
    }
    return array_keys($bare);
  }

}
