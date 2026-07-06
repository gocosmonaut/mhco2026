<?php

declare(strict_types=1);

namespace Drupal\lscache_purger;

/**
 * Resolves the configured purge strategy to a concrete 'tag' or 'url'.
 *
 * One definition, shared by the purger (runtime) and the status report
 * (operator-facing), so the two can never disagree about which strategy
 * is active.
 *
 * `auto` is safe by default: it selects the URL strategy, which evicts on
 * every LiteSpeed build, until a probe has positively confirmed that
 * tag-based PURGE actually evicts on this build. Only a probe result that
 * reads truthy selects `tag`; an unprobed site (NULL) or a probe that
 * found tag-PURGE ineffective (FALSE, or a drifted falsy value) selects
 * `url`. This prevents the silent no-eviction failure mode where a fresh
 * `auto` install purges by tag into a build that ignores tag-PURGE, so
 * edits return HTTP 200 but nothing ever clears.
 */
final class StrategyResolver {

  /**
   * Resolves a configured strategy plus probe state to 'tag' or 'url'.
   *
   * @param string $configured
   *   The purge_strategy config value: 'auto', 'tag', or 'url'. Any
   *   unrecognised value is treated as 'auto'.
   * @param mixed $tag_purge_effective
   *   The lscache_purger.tag_purge_effective state value: TRUE, FALSE, or
   *   NULL (never probed). Coerced loosely so a drifted value (0, '0',
   *   'false') cannot slip a broken build onto the tag strategy.
   *
   * @return string
   *   Either 'tag' or 'url'.
   */
  public static function resolve(string $configured, mixed $tag_purge_effective): string {
    if ($configured === 'tag' || $configured === 'url') {
      return $configured;
    }
    return filter_var($tag_purge_effective, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === TRUE
      ? 'tag'
      : 'url';
  }

}
