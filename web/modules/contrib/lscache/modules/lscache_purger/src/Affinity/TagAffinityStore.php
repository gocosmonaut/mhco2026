<?php

declare(strict_types=1);

namespace Drupal\lscache_purger\Affinity;

use Drupal\Core\Database\Connection;

/**
 * Persistent (tag, url) affinity table for view/listing eviction.
 *
 * Background: the 1.3.x portal diagnosis exposed that URL-strategy
 * mode correctly evicts canonical entity URLs (node:N -> /node/N +
 * alias) but cannot resolve aggregate tags like node_list,
 * config:views.view.X, or http_response to any single URL. The result
 * on the field: a Views-backed /patch-notes listing stayed cached
 * with a stale node list after three new patch_note nodes were
 * published via the REST endpoint.
 *
 * This store records which URLs emitted which cache tags at LSWS-
 * store time, so InvalidationUrlResolver can answer "what URLs need
 * evicting when tag T is invalidated" without operator-maintained
 * static maps. The pattern is the same one Varnish's xkey vmod and
 * Cloudflare's tag-cache use: write affinity on the response side,
 * query on the invalidation side, prune by last-seen.
 *
 * Operator UX is zero in steady state. The table self-populates from
 * real traffic; after a few hours of normal browsing the resolver
 * knows that node_list invalidations should evict /patch-notes,
 * /blog, /news, etc. without anyone enumerating them. The trade-off
 * is cold-start: until a URL has been visited and cached at least
 * once, the resolver does not know about it. Low-traffic sites where
 * critical listings rarely get hit can supplement with the optional
 * static_url_map config; the affinity store handles everything else.
 *
 * Storage cost: one row per (cacheable URL × distinct tag). A site
 * with 5k cacheable URLs averaging 20 distinct tags is ~100k indexed
 * rows; lookups are sub-millisecond. The prune-by-age cron job
 * bounds growth by the longest configured TTL.
 *
 * Not marked final: the unit suite doubles this store, and as a flat
 * persistence shim it is a reasonable service-override seam for sites
 * that want a different affinity backend.
 */
class TagAffinityStore {

  /**
   * Database table name.
   *
   * Tied to the install hook's lscache_purger_schema() definition;
   * change in both places together.
   */
  public const TABLE = 'lscache_purger_tag_affinity';

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Records that $url's most recent cacheable response carried $tags.
   *
   * Idempotent insert-or-touch: each (tag, url) pair becomes one row;
   * subsequent calls for the same pair update last_seen rather than
   * inserting a duplicate. Uses Drupal's merge() abstraction to keep
   * the single-statement upsert portable across MySQL/MariaDB,
   * PostgreSQL, and SQLite.
   *
   * Callers are responsible for prefix-stripping tags before writing;
   * the store keys on bare tag expressions so the resolver can query
   * by tag expressions the Purge framework hands it directly.
   *
   * @param string $url
   *   The canonical Drupal path the tags were observed on. Leading
   *   slash, no scheme/host.
   * @param string[] $tags
   *   Bare (prefix-stripped) cache tags. Empty arrays no-op.
   * @param int $now
   *   The unix timestamp to record as last_seen. Caller-supplied so
   *   tests can inject a known value; production callers pass
   *   datetime.time::getCurrentTime().
   * @param bool $protected
   *   When TRUE the row is marked protected (protected=1) so prune()
   *   never ages it out. Used by the cron auto-seeder to give Views
   *   listings a durable affinity floor that does not depend on traffic
   *   re-observing them within the 2×TTL prune window. When FALSE (the
   *   recorder's default, traffic-driven observation) the column is left
   *   untouched: a new row takes the column default (0, prunable), and
   *   an existing seeded row keeps its protected=1 - traffic never
   *   demotes a seed.
   */
  public function recordTagsForUrl(string $url, array $tags, int $now, bool $protected = FALSE): void {
    if ($tags === []) {
      return;
    }
    // Defensive cap: the URL column is VARCHAR(2048) in schema. Drop
    // longer URLs rather than truncating; a truncated URL is a wrong
    // URL and would PURGE the wrong entry. The longest URLs in
    // practice carry session/query data that defeats LSWS caching
    // anyway, so this branch rarely fires.
    if (strlen($url) > 2048) {
      return;
    }
    $url_hash = sha1($url);
    foreach ($tags as $tag) {
      $tag = (string) $tag;
      // Drop overly-long tags rather than truncating. The 190-char
      // cap matches the schema's VARCHAR(190) limit, which is itself
      // the safe utf8mb4 composite-key bound.
      if ($tag === '' || strlen($tag) > 190) {
        continue;
      }
      $fields = [
        'url' => $url,
        'last_seen' => $now,
      ];
      // Only seeded writes touch the protected column. Ordinary
      // observation (the recorder's hot path, fired on every cacheable
      // response) omits it for two reasons: (1) it stays immune to the
      // upgrade window before update_8106 adds the column - a write
      // that never names the column cannot fail on its absence, and the
      // column default (0) applies on insert; (2) it never demotes an
      // already-seeded row, since an update that omits the column
      // leaves protected as-is.
      if ($protected) {
        $fields['protected'] = 1;
      }
      $this->database->merge(self::TABLE)
        ->keys([
          'tag' => $tag,
          'url_hash' => $url_hash,
        ])
        ->fields($fields)
        ->execute();
    }
  }

  /**
   * Returns the URLs that have recently been observed emitting $tag.
   *
   * Pure read; does not touch last_seen. The resolver calls this on
   * invalidation; LSWS-cache emission updates last_seen via
   * recordTagsForUrl().
   *
   * @param string $tag
   *   Bare (prefix-stripped) cache tag expression.
   *
   * @return string[]
   *   Distinct URLs, in last_seen DESC order so the hottest URLs are
   *   evicted first. Empty array when the tag has no recorded
   *   affinity (cold-start case for low-traffic sites).
   */
  public function getUrlsForTag(string $tag): array {
    if ($tag === '') {
      return [];
    }
    return $this->database->select(self::TABLE, 'a')
      ->fields('a', ['url'])
      ->condition('tag', $tag)
      ->orderBy('last_seen', 'DESC')
      ->execute()
      ->fetchCol();
  }

  /**
   * Removes rows whose last_seen is older than the cutoff.
   *
   * Background-callable from hook_cron; returns the number of rows
   * removed so the caller can log proportional progress. Bounded
   * delete batch via $limit so a first-prune on a site with millions
   * of rows does not stall cron.
   *
   * Protected rows (protected=1, written by the cron auto-seeder for
   * deterministic Views listings) are NEVER pruned regardless of age:
   * they are the durable floor that closes the 2×TTL pruning gap for
   * low-traffic listings the affinity table would otherwise age out.
   *
   * @param int $older_than
   *   Unix timestamp; rows with last_seen strictly less than this
   *   are eligible for deletion.
   * @param int $limit
   *   Cap on rows removed in this call. Pass 0 for unbounded.
   */
  public function prune(int $older_than, int $limit = 10000): int {
    // The Drupal database abstraction does not expose LIMIT on
    // delete portably across all backends; on MySQL/MariaDB we use
    // ORDER BY + LIMIT in a subquery via a select-then-delete shape.
    // For SQLite/PostgreSQL the same pattern works via WHERE IN.
    if ($limit > 0) {
      $rows = $this->database->select(self::TABLE, 'a')
        ->fields('a', ['tag', 'url_hash'])
        ->condition('last_seen', $older_than, '<')
        ->condition('protected', 0)
        ->range(0, $limit)
        ->execute()
        ->fetchAll();
      if ($rows === []) {
        return 0;
      }
      $count = 0;
      foreach ($rows as $row) {
        $count += $this->database->delete(self::TABLE)
          ->condition('tag', $row->tag)
          ->condition('url_hash', $row->url_hash)
          ->execute();
      }
      return $count;
    }
    return $this->database->delete(self::TABLE)
      ->condition('last_seen', $older_than, '<')
      ->condition('protected', 0)
      ->execute();
  }

  /**
   * Returns the total row count.
   *
   * Used by hook_requirements() to surface affinity-table size on the
   * status report so operators can spot runaway growth without
   * running ad-hoc queries.
   */
  public function size(): int {
    return (int) $this->database->select(self::TABLE)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Returns the distinct list-shaped tags observed in the table.
   *
   * "List-shaped" = ends in `_list` (node_list) or contains `_list:`
   * (node_list:help_article). This is the reactive half of the
   * status-report coverage check: controller-rendered listing pages
   * attach their `_list` tags in render arrays that cannot be
   * enumerated statically (there is no Drupal-wide cache-tag
   * registry), so the only way to surface an observed-but-unpinned
   * controller listing is to read back what the affinity recorder has
   * actually seen on cacheable responses.
   *
   * A broad `%list%` LIKE pre-filters at the database (keeping the row
   * set small), then an exact str-shape filter in PHP avoids the
   * cross-backend LIKE-escape inconsistencies around the literal
   * underscore in `_list`.
   *
   * @return string[]
   *   Distinct list-shaped tags currently recorded in the table.
   */
  public function distinctListTags(): array {
    $tags = $this->database->select(self::TABLE, 'a')
      ->fields('a', ['tag'])
      ->distinct()
      ->condition('tag', '%list%', 'LIKE')
      ->execute()
      ->fetchCol();
    return array_values(array_filter(
      $tags,
      static fn($t): bool => is_string($t) && (str_ends_with($t, '_list') || str_contains($t, '_list:')),
    ));
  }

}
