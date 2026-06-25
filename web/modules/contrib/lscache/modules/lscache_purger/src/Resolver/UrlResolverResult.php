<?php

declare(strict_types=1);

namespace Drupal\lscache_purger\Resolver;

/**
 * Value object returned by InvalidationUrlResolver::resolve().
 *
 * Carries the resolved canonical Drupal paths for an invalidation
 * tag, plus a structured signal for the "unmappable" case (config:*,
 * *_list, *_view, etc.) so the purger can decide whether to silently
 * drop or fall back to a global PURGE without each call-site having
 * to re-parse the tag expression.
 *
 * Final by design; the resolver returns these objects as a flat data
 * payload, not as an extension point.
 */
final class UrlResolverResult {

  /**
   * Constructs a UrlResolverResult.
   *
   * @param string[] $urls
   *   Canonical Drupal paths (leading slash, no scheme/host) that
   *   surface the invalidated entity's content. May be empty when
   *   $isUnmappable is TRUE, or when the entity legitimately has no
   *   canonical URL (e.g. file:*).
   * @param bool $isUnmappable
   *   TRUE when the tag pattern cannot be resolved to URLs in
   *   principle (config:*, *_list, *_view, http_response, rendered,
   *   block_content:*, etc.). The purger uses this to drive the
   *   unmappable_fallback config branch. Distinct from "mappable but
   *   the entity does not exist": a stale node:999 reference returns
   *   isUnmappable=FALSE with urls=[] because the tag pattern itself
   *   is mappable; the entity is just gone.
   * @param string $reason
   *   Human-readable explanation suitable for the lscache:diag
   *   command output and watchdog log context. Always populated, even
   *   on the success path, so dblog rows for individual invalidations
   *   carry their own audit trail when the purger logs in verbose
   *   mode.
   */
  public function __construct(
    public readonly array $urls,
    public readonly bool $isUnmappable,
    public readonly string $reason,
  ) {}

}
