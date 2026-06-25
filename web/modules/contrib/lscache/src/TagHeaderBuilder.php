<?php

namespace Drupal\lscache;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Builds the X-LiteSpeed-Tag header value from a response's cache tags.
 *
 * LSCache identifies cached pages by the tags sent in the X-LiteSpeed-Tag
 * response header. Those same tags can then be used in X-LiteSpeed-Purge
 * headers to invalidate specific pages later. This service translates
 * Drupal's cache tag strings into a comma-delimited header value with an
 * optional per-site prefix and filters out tags that add header size
 * without helping invalidation.
 *
 * The tag filter and prefix defaults mirror the behaviour of the
 * LiteSpeed-published `lite_speed_cache` module, which has been
 * field-validated against real LSWS installations. Drupal emits dozens
 * of tags on a typical page (config:block.*, rendered, http_response,
 * various *_view and *_list tags) that either never correspond to a
 * real invalidation event or always fire together with more specific
 * tags. Including them only bloats the header.
 *
 * @see https://docs.litespeedtech.com/lscache/devguide/overview/
 */
class TagHeaderBuilder {

  /**
   * Default filter patterns for tags that add no invalidation value.
   *
   * Matches lite_speed_cache's behaviour. Each entry is either:
   *   - A substring match (tags containing this are dropped), or
   *   - An exact match (tag equals this is dropped).
   *
   * Operators can override this list via the `tag_filter_patterns`
   * config key once a form is exposed (roadmap item), or at runtime by
   * swapping this service.
   */
  public const DEFAULT_FILTER_SUBSTRINGS = [
    'config:',
    'user:',
    'taxonomy_term',
    '_view',
    '_list',
  ];

  public const DEFAULT_FILTER_EXACT = [
    'http_response',
    'rendered',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Builds the header value for a cacheable response.
   *
   * @param \Drupal\Core\Cache\CacheableResponseInterface $response
   *   The response to inspect for cache tags.
   *
   * @return string
   *   Comma-delimited list of prefixed, filtered tags. Empty string when
   *   the response has no cache tags worth exposing after filtering.
   */
  public function build(CacheableResponseInterface $response): string {
    $tags = $response->getCacheableMetadata()->getCacheTags();
    if (empty($tags)) {
      return '';
    }

    $config = $this->configFactory->get('lscache.settings');
    $filter_enabled = (bool) ($config->get('tag_filter') ?? TRUE);
    if ($filter_enabled) {
      $tags = $this->filter($tags);
      if (empty($tags)) {
        return '';
      }
    }

    $prefix = $this->getPrefix();
    if ($prefix !== '') {
      $tags = array_map(static fn(string $tag): string => $prefix . $tag, $tags);
    }

    // LSCache does not permit commas in tag names; strip them defensively so
    // a pathological tag from a contrib module cannot break header parsing.
    $tags = array_map(static fn(string $tag): string => str_replace(',', '', $tag), $tags);

    return implode(',', $tags);
  }

  /**
   * Drops tags that bloat the header without adding invalidation value.
   *
   * @param string[] $tags
   *   The raw cache tag list from the response.
   *
   * @return string[]
   *   The filtered list, with numeric keys re-indexed.
   */
  private function filter(array $tags): array {
    return array_values(array_filter(
      $tags,
      static function (string $tag): bool {
        if (in_array($tag, self::DEFAULT_FILTER_EXACT, TRUE)) {
          return FALSE;
        }
        foreach (self::DEFAULT_FILTER_SUBSTRINGS as $needle) {
          if (strpos($tag, $needle) !== FALSE) {
            return FALSE;
          }
        }
        return TRUE;
      }
    ));
  }

  /**
   * Returns the effective tag prefix for the current configuration.
   *
   * Exposed as public API so the purger can prefix its purge tags using
   * identical logic; if the two diverge, LSCache will look up the wrong
   * tag and no responses will be evicted.
   *
   * When the operator has set a prefix explicitly, return it verbatim.
   * Otherwise auto-derive a short per-install hash so multiple Drupal
   * sites sharing one LSCache don't collide on each other's tags. This
   * mirrors lite_speed_cache's approach of hashing the install path.
   *
   * @return string
   *   The prefix to prepend to every tag.
   */
  public function getPrefix(): string {
    $configured = (string) $this->configFactory->get('lscache.settings')->get('tag_prefix');
    if ($configured !== '') {
      return $configured;
    }
    // Hash of the module's own directory. Stable per-install, unique
    // across separate Drupal installs on the same server.
    return substr(md5(__DIR__), 0, 4);
  }

}
