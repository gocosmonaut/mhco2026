<?php

namespace Drupal\lscache\Element;

use Drupal\Core\Render\Attribute\RenderElement;
use Drupal\Core\Render\Element\RenderElementBase;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;

/**
 * Render element that emits an ESI include tag for LiteSpeed.
 *
 * Behaves like Drupal's `#lazy_builder` placeholder, but instead of
 * rendering the inner content inline (or via BigPipe streaming), it
 * emits an `<esi:include src="..." />` tag pointing at the LSCache
 * fragment route. LiteSpeed Web Server fetches the fragment, holds it
 * in its private cache keyed by user, and stitches it into the
 * surrounding response. The surrounding response can stay in shared
 * public cache because the per-user variation has been pushed out to
 * the fragment.
 *
 * Usage in a render array:
 * @code
 * $build['cart_count'] = [
 *   '#type' => 'lscache_esi',
 *   '#callback' => 'my_module.cart_service:renderCount',
 *   '#args' => [$user_id],
 * ];
 * @endcode
 *
 * The callback must be in the same form `#lazy_builder` accepts:
 * `service.name:methodName` or `Fully\\Qualified\\ClassName::staticMethod`.
 * Arguments are restricted to JSON-primitive types (string, int, float,
 * bool, NULL) so the token round-trip is loss-free.
 *
 * The target class must implement
 * `\Drupal\Core\Security\TrustedCallbackInterface`. The fragment route
 * rejects any token whose decoded callback resolves to a class that
 * does not declare itself trusted, which mirrors Drupal core's
 * `#lazy_builder` policy and provides defence in depth: even if the
 * site's hash salt were compromised (allowing token forgery), only
 * explicitly-trusted callables are reachable through this route.
 */
#[RenderElement('lscache_esi')]
class Esi extends RenderElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo(): array {
    return [
      '#callback' => NULL,
      '#args' => [],
      '#pre_render' => [
        [static::class, 'preRenderEsi'],
      ],
    ];
  }

  /**
   * Replaces the element with the LSCache fragment include tag.
   *
   * Runs at pre-render time so the rendered markup goes into the
   * outer response body verbatim. Adds a `lscache_esi` cache tag to
   * the response so future cache invalidation can target every page
   * carrying ESI fragments without operators having to enumerate them.
   *
   * The emitted markup is wrapped in `\Drupal\Core\Render\Markup` so
   * Drupal's renderer treats it as already-trusted output. Without the
   * wrap, Drupal pipes `#markup` strings through `Xss::filterAdmin()`,
   * which only allows a fixed set of HTML tags; `<esi:include>` is
   * not on that list, so the entire string was being filtered to empty.
   * The bug surfaced in the 1.3.0-alpha2 field test on portal-dev,
   * where ESI fragments produced no output at all. The src URL is
   * separately escaped via `htmlspecialchars` before the wrap, so
   * marking the resulting string as safe is correct.
   */
  public static function preRenderEsi(array $element): array {
    if (empty($element['#callback']) || !is_string($element['#callback'])) {
      // Without a callback there is nothing to render. Emit a marker
      // comment so the placeholder is visible in dev-tools but does
      // not affect layout. Wrapped in Markup for the same reason as
      // the include tag below: comment markup is not on Xss::filterAdmin's
      // allowed set either, so an unwrapped string would be stripped.
      $element['#markup'] = Markup::create('<!-- lscache_esi: missing #callback -->');
      return $element;
    }

    $signer = \Drupal::service('lscache.token_signer');
    $token = $signer->sign($element['#callback'], $element['#args'] ?? []);
    $url = Url::fromRoute('lscache.fragment', ['token' => $token])->toString();

    // ESI tags are processed by LiteSpeed before the response leaves the
    // server, so they never reach the browser as-is. The src URL is a
    // server-internal path; htmlspecialchars guards against tag-attribute
    // injection from a pathological URL builder, even though Url::toString
    // already URL-encodes path segments. Markup::create wraps the result
    // so Drupal's renderer treats it as trusted-safe output (see method
    // docblock for the field-bug context).
    $element['#markup'] = Markup::create(sprintf(
      '<esi:include src="%s" />',
      htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
    ));
    $element['#cache']['tags'][] = 'lscache_esi';

    return $element;
  }

}
