<?php

declare(strict_types=1);

namespace Drupal\lscache_purger\Resolver;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lscache_purger\Affinity\TagAffinityStore;
use Drupal\path_alias\AliasManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Maps a cache tag expression to the canonical Drupal paths it covers.
 *
 * Background (surfaced on the 1.3.x LSWS 6.3.4 portal diagnosis):
 * LSWS Enterprise 6.3.4 silently ignores the X-LiteSpeed-Purge tag=
 * header. Direct probing confirmed PURGE / with tag=NAME does not
 * evict tagged responses, while PURGE /node/38 with a wrong tag still
 * evicts /node/38. Tag-based invalidation is therefore unreliable on
 * that LSWS configuration; URL-based invalidation is reliable.
 *
 * This resolver supports the URL strategy in LscachePurger by turning
 * an invalidation tag (the purge framework's expression string, e.g.
 * "node:38") into the set of canonical Drupal paths that surface the
 * tagged entity. The purger then issues one PURGE per unique URL.
 *
 * Caller contract: the resolver receives prefix-stripped tags. The
 * caller (LscachePurger) is responsible for stripping the per-install
 * prefix returned by \Drupal\lscache\TagHeaderBuilder::getPrefix()
 * before calling resolve(). This keeps the resolver agnostic to the
 * prefix convention; only one place in the codebase has to know about
 * the prefix shape.
 *
 * The mapping is deliberately conservative: only entity-shaped tags
 * (node, taxonomy_term, media, user, paragraph) resolve to URLs.
 * Aggregate tags (config:*, *_list, *_view, http_response, rendered,
 * webform:*, *_esi) are flagged unmappable so the purger can apply
 * the operator-configured unmappable_fallback policy (default: drop,
 * trust TTL) rather than guess.
 *
 * Not marked final: the purger's unit suite doubles this resolver to
 * control resolve() output, and the container treats it as an
 * overridable service.
 */
class InvalidationUrlResolver {

  /**
   * Hard cap on paragraph parent traversal depth.
   *
   * Paragraph entities reference their parent by parent_id +
   * parent_type, which may itself be another paragraph. The traversal
   * is finite in well-formed content models, but a cycle introduced
   * by a misconfigured field would otherwise pin a PHP-FPM worker on
   * an infinite loop. Drupal's own form rendering caps paragraph
   * nesting around 10 levels in practice; 16 gives operators
   * headroom while still bounding the worst case.
   */
  private const PARAGRAPH_TRAVERSAL_DEPTH_CAP = 16;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AliasManagerInterface $aliasManager,
    private readonly TagAffinityStore $affinityStore,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $channelLogger,
  ) {}

  /**
   * Resolves one prefix-stripped tag expression to canonical URLs.
   *
   * @param string $tagExpression
   *   The prefix-stripped tag (e.g. "node:38", "taxonomy_term:5",
   *   "config:system.site"). Caller has already removed the per-
   *   install prefix from TagHeaderBuilder::getPrefix().
   *
   * @return \Drupal\lscache_purger\Resolver\UrlResolverResult
   *   Structured result carrying URLs, unmappable signal, and
   *   reason. See the value object's docblock for the meaning of
   *   each field.
   */
  public function resolve(string $tagExpression): UrlResolverResult {
    $expression = trim($tagExpression);
    if ($expression === '') {
      return new UrlResolverResult([], TRUE, 'Empty tag expression.');
    }

    $canonical = $this->resolveCanonical($expression);
    $affinity_urls = $this->affinityStore->getUrlsForTag($expression);
    $static_urls = $this->staticUrlsForTag($expression);

    // Union canonical + static + affinity. The canonical lookup gives
    // the entity's own URLs (deterministic, immediate); the static map
    // is an operator-configured cold-start guarantee for known listing
    // pages (evicts even before affinity has observed them); the
    // affinity store contributes URLs that have recently emitted this
    // tag (Views listings, homepage blocks, related-content widgets).
    // Dedupe preserves canonical-first ordering so PURGEs land on
    // entity-canonical URLs before the wider listing set.
    $merged = $canonical->urls;
    foreach ([$static_urls, $affinity_urls] as $source) {
      foreach ($source as $u) {
        if ($u !== '' && !in_array($u, $merged, TRUE)) {
          $merged[] = $u;
        }
      }
    }

    // isUnmappable carries the "no path forward at all" signal: every
    // source returned empty. The purger uses this to drive
    // unmappable_fallback. If the static map or affinity add URLs this
    // branch never trips; together they are the workaround for the
    // aggregate-tag class of otherwise-unmappable expressions.
    if ($merged === [] && $canonical->isUnmappable) {
      return new UrlResolverResult([], TRUE, $canonical->reason . ' No static-map or affinity URLs recorded for this tag.');
    }

    $reason_parts = [$canonical->reason];
    if ($static_urls !== []) {
      $reason_parts[] = sprintf('Static map added %d URL(s).', count($static_urls));
    }
    if ($affinity_urls !== []) {
      $reason_parts[] = sprintf('Affinity store added %d URL(s).', count($affinity_urls));
    }

    return new UrlResolverResult($merged, FALSE, implode(' ', $reason_parts));
  }

  /**
   * Returns the operator-configured static URLs for a tag, or [].
   *
   * The static map (lscache_purger.settings:static_url_map) is the
   * cold-start guarantee for listing pages: it evicts a known listing
   * the instant its aggregate tag is invalidated, without waiting for
   * the affinity table to have observed that page. Surfaced on the
   * 1.3.x portal-stage review: publishing a new patch_note fires
   * node_list, and operators want /patch-notes evicted immediately and
   * deterministically, not only once affinity has warmed.
   *
   * @return string[]
   *   Operator-configured canonical paths pinned to this tag, or an
   *   empty array when the tag is absent from static_url_map.
   */
  private function staticUrlsForTag(string $tag): array {
    $map = $this->configFactory->get('lscache_purger.settings')->get('static_url_map');
    if (!is_array($map) || !isset($map[$tag]) || !is_array($map[$tag])) {
      return [];
    }
    return array_values(array_filter(
      $map[$tag],
      static fn($u): bool => is_string($u) && $u !== '',
    ));
  }

  /**
   * Returns canonical (entity-derived) URLs for a tag expression.
   *
   * Lifted out of resolve() so the public method can union canonical
   * + affinity results in one place. The canonical path knows about
   * the entity hierarchy and path aliases; the affinity store knows
   * about the wider page graph from observed traffic. Different
   * sources, intentionally orthogonal.
   */
  private function resolveCanonical(string $expression): UrlResolverResult {
    // Aggregate tags first: these never correspond to a single URL
    // by entity-canonical lookup. The affinity store is the answer
    // for these; resolve() unions both.
    if ($this->isUnmappablePattern($expression)) {
      return new UrlResolverResult(
        [],
        TRUE,
        sprintf('Tag "%s" is an aggregate/config tag with no canonical URL.', $expression),
      );
    }

    // Entity-shaped tags: type:id. The Purge framework also accepts
    // tag expressions that are not entity-shaped (e.g. site-wide
    // tags); those fall through the match below and return
    // unmappable. The strpos check guards strstr() callers from
    // string-as-tag pathologies.
    if (!str_contains($expression, ':')) {
      return new UrlResolverResult(
        [],
        TRUE,
        sprintf('Tag "%s" is not entity-shaped (no colon).', $expression),
      );
    }

    [$type, $id] = explode(':', $expression, 2);
    $type = trim($type);
    $id = trim($id);

    if ($type === '' || $id === '' || !ctype_digit($id)) {
      return new UrlResolverResult(
        [],
        TRUE,
        sprintf('Tag "%s" has a non-numeric or empty entity id; cannot resolve.', $expression),
      );
    }

    $entity_id = (int) $id;

    return match ($type) {
      'node' => $this->resolveEntityCanonical('node', $entity_id, '/node/'),
      'taxonomy_term' => $this->resolveEntityCanonical('taxonomy_term', $entity_id, '/taxonomy/term/'),
      'media' => $this->resolveEntityCanonical('media', $entity_id, '/media/'),
      'user' => $this->resolveEntityCanonical('user', $entity_id, '/user/'),
      'paragraph' => $this->resolveParagraph($entity_id, 0),
      'file' => new UrlResolverResult(
        [],
        FALSE,
        sprintf('Tag "%s" maps to a file entity with no canonical Drupal-served URL; LSWS does not cache the binary.', $expression),
      ),
      'block_content' => new UrlResolverResult(
        [],
        TRUE,
        sprintf('Tag "%s" has no canonical URL on its own (block_content surfaces on referencing pages).', $expression),
      ),
      default => new UrlResolverResult(
        [],
        TRUE,
        sprintf('Tag "%s" uses unknown entity type "%s"; no URL mapping registered.', $expression, $type),
      ),
    };
  }

  /**
   * Returns TRUE for tag shapes that never resolve to a single URL.
   *
   * Mirrors (a superset of) the patterns TagHeaderBuilder filters out
   * of the response X-LiteSpeed-Tag header. Aggregate tags like
   * node_list or rendered fire on huge swaths of the cache and cannot
   * be expressed as a finite URL set; surfacing them as unmappable
   * lets the purger fall back to the operator-configured policy
   * rather than silently emit zero PURGEs.
   */
  private function isUnmappablePattern(string $expression): bool {
    if ($expression === 'http_response' || $expression === 'rendered') {
      return TRUE;
    }
    if (str_starts_with($expression, 'config:')) {
      return TRUE;
    }
    if (str_starts_with($expression, 'webform:')) {
      return TRUE;
    }
    if (str_ends_with($expression, '_list')) {
      return TRUE;
    }
    if (str_ends_with($expression, '_view')) {
      return TRUE;
    }
    if (str_ends_with($expression, '_esi')) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Loads an entity and returns canonical path + alias as URLs.
   *
   * The canonical path is the system path Drupal exposes regardless
   * of alias configuration (e.g. /node/38). The alias, when present,
   * is the operator-facing path real visitors hit (e.g. /about-us).
   * LSWS caches under whichever path the visitor's browser fetched,
   * so a reliable URL-based PURGE must hit both.
   *
   * Returns isUnmappable=FALSE with urls=[] when the entity does not
   * exist (stale invalidation queued before a delete). That state is
   * a no-op for invalidation purposes; nothing to evict.
   *
   * @param string $entity_type_id
   *   The entity type machine name (node, taxonomy_term, media, user).
   * @param int $entity_id
   *   The entity id parsed from the tag expression.
   * @param string $canonical_prefix
   *   The system path prefix for this entity type, including the
   *   trailing slash (e.g. "/node/"). Hard-coded rather than derived
   *   from the entity's route to avoid bootstrapping the routing
   *   subsystem just to compute "/node/38".
   */
  private function resolveEntityCanonical(string $entity_type_id, int $entity_id, string $canonical_prefix): UrlResolverResult {
    $canonical = $canonical_prefix . $entity_id;

    try {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
    }
    catch (\Throwable $e) {
      // No storage handler for this type (test environments without
      // entity_test loaded, contrib type uninstalled mid-cycle).
      // Treat as unmappable so the purger can decide; do not throw.
      $this->channelLogger->debug(
        'URL resolver: no storage for @type: @msg',
        ['@type' => $entity_type_id, '@msg' => $e->getMessage()],
      );
      return new UrlResolverResult(
        [],
        TRUE,
        sprintf('Entity type "%s" has no storage handler; cannot resolve.', $entity_type_id),
      );
    }

    $entity = $storage->load($entity_id);
    if ($entity === NULL) {
      // Stale invalidation: entity was deleted between queue write
      // and queue read. URL set is empty (nothing to evict) but the
      // tag itself was mappable, so isUnmappable stays FALSE; the
      // purger marks SUCCEEDED.
      return new UrlResolverResult(
        [],
        FALSE,
        sprintf('Entity %s:%d does not exist; nothing to evict.', $entity_type_id, $entity_id),
      );
    }

    $urls = [$canonical];

    // Path alias lookup. The alias manager returns the canonical
    // path unchanged when no alias is set, so the dedup below avoids
    // emitting two identical URLs in the common no-alias case.
    try {
      $alias = $this->aliasManager->getAliasByPath($canonical);
      if ($alias !== '' && $alias !== $canonical) {
        $urls[] = $alias;
      }
    }
    catch (\Throwable $e) {
      // Alias resolution failures are non-fatal; the canonical URL
      // alone still evicts the canonical-path cache entry.
      $this->channelLogger->debug(
        'URL resolver: alias lookup failed for @path: @msg',
        ['@path' => $canonical, '@msg' => $e->getMessage()],
      );
    }

    return new UrlResolverResult(
      array_values(array_unique($urls)),
      FALSE,
      sprintf('Resolved %s:%d to %d URL(s).', $entity_type_id, $entity_id, count($urls)),
    );
  }

  /**
   * Resolves a paragraph tag by walking up to its hosting entity.
   *
   * Paragraphs do not surface on their own URL; they render inside
   * the host entity (node, term, custom entity) that references
   * them. To evict a page that includes a changed paragraph, the
   * resolver walks parent_id/parent_type recursively until it
   * reaches a URL-bearing entity, then resolves THAT entity's URLs.
   *
   * Bounded by PARAGRAPH_TRAVERSAL_DEPTH_CAP so a circular reference
   * in a misconfigured paragraph field cannot loop forever.
   *
   * @param int $paragraph_id
   *   The paragraph entity id from the tag expression.
   * @param int $depth
   *   Current traversal depth (callers pass 0; recursion increments).
   */
  private function resolveParagraph(int $paragraph_id, int $depth): UrlResolverResult {
    if ($depth >= self::PARAGRAPH_TRAVERSAL_DEPTH_CAP) {
      return new UrlResolverResult(
        [],
        TRUE,
        sprintf('Paragraph %d traversal exceeded depth cap (%d); likely circular parent reference.', $paragraph_id, self::PARAGRAPH_TRAVERSAL_DEPTH_CAP),
      );
    }

    try {
      $storage = $this->entityTypeManager->getStorage('paragraph');
    }
    catch (\Throwable $e) {
      // Paragraphs module not installed; tag arrived from a contrib
      // emitter we cannot satisfy. Unmappable signal so the purger
      // applies its fallback policy.
      return new UrlResolverResult(
        [],
        TRUE,
        sprintf('Paragraphs module not present; cannot resolve paragraph:%d.', $paragraph_id),
      );
    }

    $paragraph = $storage->load($paragraph_id);
    if ($paragraph === NULL) {
      return new UrlResolverResult(
        [],
        FALSE,
        sprintf('Paragraph %d does not exist; nothing to evict.', $paragraph_id),
      );
    }

    // Paragraphs ship parent_type + parent_id methods on the
    // ParagraphInterface. Access via method_exists so the resolver
    // does not hard-import a class that may not be loaded.
    $parent_type = method_exists($paragraph, 'getParentEntity') && $paragraph->getParentEntity()
      ? $paragraph->getParentEntity()->getEntityTypeId()
      : NULL;
    $parent = method_exists($paragraph, 'getParentEntity')
      ? $paragraph->getParentEntity()
      : NULL;

    if ($parent === NULL || $parent_type === NULL) {
      return new UrlResolverResult(
        [],
        TRUE,
        sprintf('Paragraph %d has no resolvable parent entity.', $paragraph_id),
      );
    }

    $parent_id = (int) $parent->id();

    // Recurse for paragraph-of-paragraph; otherwise dispatch through
    // the canonical resolver for whatever URL-bearing type we landed
    // on. The match-default branch covers parent types we have no
    // mapping for (custom entities, etc.) and surfaces as unmappable.
    return match ($parent_type) {
      'paragraph' => $this->resolveParagraph($parent_id, $depth + 1),
      'node' => $this->resolveEntityCanonical('node', $parent_id, '/node/'),
      'taxonomy_term' => $this->resolveEntityCanonical('taxonomy_term', $parent_id, '/taxonomy/term/'),
      'media' => $this->resolveEntityCanonical('media', $parent_id, '/media/'),
      'user' => $this->resolveEntityCanonical('user', $parent_id, '/user/'),
      default => new UrlResolverResult(
        [],
        TRUE,
        sprintf('Paragraph %d parent is a "%s" entity with no URL mapping registered.', $paragraph_id, $parent_type),
      ),
    };
  }

}
