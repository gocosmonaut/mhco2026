<?php

declare(strict_types=1);

namespace Drupal\lscache_purger\Coverage;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lscache_purger\Affinity\TagAffinityStore;

/**
 * Computes list-cache-tag coverage for listing pages.
 *
 * Background: under the URL-purge strategy (LSWS builds where tag-PURGE
 * is ineffective) an aggregate tag like node_list has no canonical URL,
 * so a listing page only evicts if the resolver can map that tag to a
 * URL via one of two side maps - the hand-pinned static_url_map or the
 * traffic-learned affinity table. When a new listing ships with neither,
 * it serves stale until a manual PURGE. The recurring failure mode is
 * that nobody is told a new listing needs pinning (the /help portal
 * incident). This service makes the gap self-surfacing: it enumerates
 * the listing universe, tests each list tag for coverage, and is
 * consumed by three callers:
 *
 *   - lscache_purger_requirements() -> a Status Report WARNING naming
 *     each uncovered / affinity-only listing with a copy-pasteable fix.
 *   - the `lscache:list-tag-coverage` drush command (CI gate).
 *   - lscache_purger_cron() -> auto-seeds Views page listings into the
 *     affinity table as protected rows (the durable floor).
 *
 * Three enumeration sources, mirroring the resolver so the report can
 * never disagree with runtime:
 *
 *   (A) Entity-type list tags: every ContentEntityType's `<id>_list`
 *       crossed with bundle info for `<id>_list:<bundle>`. This is the
 *       universe of *possible* list tags; it is exposed for the drush
 *       command's full table but is intentionally NOT a Status Report
 *       WARNING source on its own - most entity types have no public
 *       listing page and never emit their _list tag on a cacheable
 *       response, so warning on the bare universe would flood the
 *       report with false positives.
 *   (B) Views page displays: the precomputed-and-persisted
 *       cache_metadata.tags on each enabled `page` display, filtered to
 *       list-shaped tags, paired with display_options.path so the
 *       warning can suggest the exact pin target. A Views page IS a
 *       public listing with a URL - high signal.
 *   (C) Observed list tags: the reactive half (TagAffinityStore::
 *       distinctListTags()). Controller-rendered listings attach their
 *       _list tags in render arrays that cannot be enumerated
 *       statically; once cached, the affinity recorder has seen them,
 *       so reading them back surfaces observed-but-unpinned controller
 *       listings (the /help class). High signal - definitely cached.
 *
 * report() returns the actionable B ∪ C set classified against the
 * static map + affinity, so the requirements row and the drush command
 * share one source of truth.
 *
 * Final by design: a stateless read-only computation shim, not an
 * extension point.
 */
final class ListTagCoverage {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TagAffinityStore $affinityStore,
  ) {}

  /**
   * Returns Views `page`-display listings with their list tags + path.
   *
   * Pure config walk (no view execution): reads each `views.view.*`
   * config object's display array, keeps `page` displays with a
   * concrete (non-templated) path, and reads the list-shaped tags from
   * the display's persisted cache_metadata (falling back to the default
   * display's when a child display does not override it).
   *
   * @return array<int, array{id: string, label: string, path: string, tags: string[]}>
   *   One entry per qualifying Views page display: its config id, view
   *   label, listing path, and the display's list-shaped cache tags.
   */
  public function viewsPageListings(): array {
    $listings = [];
    foreach ($this->configFactory->listAll('views.view.') as $name) {
      $view = $this->configFactory->get($name);
      $displays = $view->get('display');
      if (!is_array($displays)) {
        continue;
      }
      $default_tags = $this->listTagsFromDisplay($displays['default'] ?? []);
      foreach ($displays as $display_id => $display) {
        if (!is_array($display) || ($display['display_plugin'] ?? '') !== 'page') {
          continue;
        }
        $options = $display['display_options'] ?? [];
        $path = is_array($options) ? ($options['path'] ?? NULL) : NULL;
        if (!is_string($path) || $path === '') {
          continue;
        }
        // Skip templated paths carrying contextual arguments (%, {arg}):
        // they have no single canonical URL to pin or seed.
        if (str_contains($path, '%') || str_contains($path, '{')) {
          continue;
        }
        $tags = $this->listTagsFromDisplay($display);
        if ($tags === []) {
          $tags = $default_tags;
        }
        if ($tags === []) {
          continue;
        }
        $listings[] = [
          'id' => $name . ':' . $display_id,
          'label' => (string) ($view->get('label') ?? $name),
          'path' => '/' . ltrim($path, '/'),
          'tags' => $tags,
        ];
      }
    }
    return $listings;
  }

  /**
   * Extracts list-shaped tags from a single Views display's config.
   *
   * @param array<string, mixed> $display
   *   A display array from a view config object.
   *
   * @return string[]
   *   The display's list-shaped cache tags, or an empty array.
   */
  private function listTagsFromDisplay(array $display): array {
    $tags = $display['display_options']['cache_metadata']['tags'] ?? [];
    if (!is_array($tags)) {
      return [];
    }
    return array_values(array_filter(
      $tags,
      static fn($t): bool => is_string($t) && (str_ends_with($t, '_list') || str_contains($t, '_list:')),
    ));
  }

  /**
   * Returns the entity-type list-tag universe (source A).
   *
   * @return string[]
   *   Sorted, de-duplicated `<id>_list` and `<id>_list:<bundle>` tags
   *   for every content entity type.
   */
  public function entityListTags(): array {
    $tags = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if (!$definition instanceof ContentEntityTypeInterface) {
        continue;
      }
      $tags[$id . '_list'] = TRUE;
      foreach (array_keys($this->bundleInfo->getBundleInfo($id)) as $bundle) {
        $tags[$id . '_list:' . $bundle] = TRUE;
      }
    }
    $keys = array_keys($tags);
    sort($keys);
    return $keys;
  }

  /**
   * Returns the list-shaped tags observed in the affinity table (C).
   *
   * @return string[]
   *   Distinct list-shaped tags the affinity recorder has seen.
   */
  public function observedListTags(): array {
    return $this->affinityStore->distinctListTags();
  }

  /**
   * Whether $tag has at least one non-empty URL pinned in static_url_map.
   *
   * Reads the map exactly as InvalidationUrlResolver::staticUrlsForTag()
   * does, so this check can never disagree with the runtime resolver.
   *
   * @param string $tag
   *   The list cache tag to test for a pin.
   * @param array<string, mixed>|null $map
   *   The static_url_map, or NULL to read it from active config.
   *
   * @return bool
   *   TRUE when the tag has at least one non-empty pinned URL.
   */
  public function isPinned(string $tag, ?array $map = NULL): bool {
    if ($map === NULL) {
      $map = $this->configFactory->get('lscache_purger.settings')->get('static_url_map');
    }
    if (!is_array($map) || !isset($map[$tag]) || !is_array($map[$tag])) {
      return FALSE;
    }
    return array_filter($map[$tag], static fn($u): bool => is_string($u) && $u !== '') !== [];
  }

  /**
   * The URLs the affinity table has recorded for $tag (warmth signal).
   *
   * @param string $tag
   *   The list cache tag to look up.
   *
   * @return string[]
   *   The recorded URLs, last-seen first; empty when the tag is cold.
   */
  public function warmUrls(string $tag): array {
    return $this->affinityStore->getUrlsForTag($tag);
  }

  /**
   * Classifies the actionable listing universe (B ∪ C) against coverage.
   *
   * @return array<int, array{tag: string, status: string, pinned: bool, warm: bool, sources: string[], suggested_urls: string[]}>
   *   Each row's `status` is one of 'pinned' (durable), 'affinity_only'
   *   (warm now, lapses at 2×TTL) or 'uncovered' (neither). Sorted
   *   problems-first so callers can slice the head.
   */
  public function report(): array {
    $map = $this->configFactory->get('lscache_purger.settings')->get('static_url_map');
    $map = is_array($map) ? $map : [];

    // Gather candidate tags from the two actionable sources, unioning
    // their suggested pin targets: Views contributes the deterministic
    // display path; observed contributes the actually-cached URLs.
    $candidates = [];
    foreach ($this->viewsPageListings() as $listing) {
      foreach ($listing['tags'] as $tag) {
        $candidates[$tag]['sources']['views'] = TRUE;
        $candidates[$tag]['urls'][$listing['path']] = TRUE;
      }
    }
    foreach ($this->observedListTags() as $tag) {
      $candidates[$tag]['sources']['observed'] = TRUE;
      foreach ($this->warmUrls($tag) as $url) {
        $candidates[$tag]['urls'][$url] = TRUE;
      }
    }

    $rows = [];
    foreach ($candidates as $tag => $info) {
      $tag = (string) $tag;
      $pinned = $this->isPinned($tag, $map);
      $warm = isset($info['sources']['observed']) || $this->warmUrls($tag) !== [];
      $rows[] = [
        'tag' => $tag,
        'status' => $pinned ? 'pinned' : ($warm ? 'affinity_only' : 'uncovered'),
        'pinned' => $pinned,
        'warm' => $warm,
        'sources' => array_keys($info['sources'] ?? []),
        'suggested_urls' => array_values(array_keys($info['urls'] ?? [])),
      ];
    }

    $rank = ['uncovered' => 0, 'affinity_only' => 1, 'pinned' => 2];
    usort($rows, static function (array $a, array $b) use ($rank): int {
      return [$rank[$a['status']], $a['tag']] <=> [$rank[$b['status']], $b['tag']];
    });
    return $rows;
  }

  /**
   * Formats the copy-pasteable drush pin command for a coverage row.
   *
   * Uses --input-format=yaml so the bracketed value is stored as a
   * sequence, not the literal string "[…]" (a plain `config:set`
   * value is a scalar). The tag key is single-quoted because it
   * contains a colon in the bundle-specific form.
   *
   * @param string $tag
   *   The list cache tag to pin.
   * @param string[] $urls
   *   Suggested URL pin targets.
   */
  public static function suggestedPinCommand(string $tag, array $urls): string {
    $urls = array_values(array_filter($urls, static fn($u): bool => is_string($u) && $u !== ''));
    $list = '[' . implode(', ', $urls) . ']';
    return sprintf(
      "drush config:set lscache_purger.settings 'static_url_map.%s' '%s' --input-format=yaml -y",
      $tag,
      $list,
    );
  }

}
