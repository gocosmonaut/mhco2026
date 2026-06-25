<?php

namespace Drupal\lscache\Controller;

use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\lscache\Service\InvalidTokenException;
use Drupal\lscache\Service\LscacheTokenSigner;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Renders an ESI fragment named by a signed URL token.
 *
 * Reached via the route `/lscache-fragment/{token}`. The token encodes
 * the callback to invoke and its arguments; signature verification
 * gates access. Successful responses carry private-cache headers so
 * LSCache holds them per-user without further configuration.
 *
 * Two layers of access control on the callback resolution:
 *
 *   1. HMAC signature on the token. An attacker would need the site's
 *      hash salt to forge a token, which would already let them attack
 *      far more security-sensitive primitives (CSRF tokens, password
 *      reset tokens, session keys).
 *   2. TrustedCallbackInterface enforcement on the resolved class.
 *      Even if the salt were compromised, only callable classes that
 *      explicitly declare themselves trusted via Drupal's standard
 *      TrustedCallbackInterface marker are reachable. This mirrors
 *      Drupal core's `#lazy_builder` policy and provides defence in
 *      depth so a salt leak does not become arbitrary code execution.
 *
 * The actual private-cache emission is delegated to
 * `LscacheResponseSubscriber` (1.1.x), which detects per-user cache
 * contexts on the response and adds the `X-LiteSpeed-Cache-Control:
 * private,max-age=N` header. This controller simply ensures the
 * response carries those contexts.
 */
class LscacheFragmentController extends ControllerBase {

  public function __construct(
    private readonly LscacheTokenSigner $tokenSigner,
    private readonly RendererInterface $renderer,
    private readonly ContainerInterface $serviceContainer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('lscache.token_signer'),
      $container->get('renderer'),
      $container,
    );
  }

  /**
   * Resolves a fragment token, invokes the callback, returns the markup.
   *
   * @param string $token
   *   The opaque token from the URL.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A private-cacheable response carrying the rendered fragment.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If the token signature fails. Mapped to a 403 response.
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   If the token resolves but the callback shape is invalid.
   */
  public function render(string $token): Response {
    try {
      $payload = $this->tokenSigner->verify($token);
    }
    catch (InvalidTokenException $e) {
      // 403 rather than 404: the route exists, but the caller is not
      // authorised. Drupal's exception handler turns this into the
      // configured 403 page; LSWS will not cache 4xx unless the
      // operator opted in.
      throw new AccessDeniedHttpException('Invalid LSCache fragment token.');
    }

    $callable = $this->resolveCallable($payload['cb']);
    if ($callable === NULL) {
      throw new BadRequestHttpException('Fragment callback is not resolvable.');
    }

    $build = $callable(...$payload['args']);
    if (!is_array($build)) {
      throw new BadRequestHttpException('Fragment callback did not return a render array.');
    }

    // Render with renderRoot to apply post-render placeholders inside the
    // fragment as well (a fragment may contain its own lazy_builder
    // placeholders, which Drupal will replace before we serialise).
    $rendered = $this->renderer->renderRoot($build);

    $response = new CacheableResponse((string) $rendered);
    // Forward bubbleable metadata so LscacheResponseSubscriber sees the
    // fragment's cache tags and contexts. The fragment route itself adds
    // a `lscache_esi` cache tag so a single invalidation can purge every
    // ESI fragment on the site.
    $metadata = BubbleableMetadata::createFromRenderArray($build);
    $metadata->addCacheTags(['lscache_esi']);
    $response->addCacheableDependency($metadata);

    // Mark Cache-Control: private so browsers and shared public caches
    // do not store the fragment. LSCache private-mode emission is added
    // by LscacheResponseSubscriber when it sees the per-user cache
    // contexts on this response.
    $response->headers->set('Cache-Control', 'must-revalidate, no-cache, private');

    return $response;
  }

  /**
   * Resolves a `service:method` or `Class::staticMethod` string to a callable.
   *
   * Returns NULL when the form is unrecognised, the service is missing,
   * the method does not exist, or the resolved class does not implement
   * `\Drupal\Core\Security\TrustedCallbackInterface`. Mirrors the rules
   * `#lazy_builder` applies to its callback strings.
   *
   * The TrustedCallbackInterface check is the second of two access
   * gates (see class docblock); it provides defence in depth so a
   * compromise of the site's hash salt does not extend to arbitrary
   * method invocation through this controller.
   */
  private function resolveCallable(string $callback): ?array {
    if (str_contains($callback, '::')) {
      [$class, $method] = explode('::', $callback, 2);
      if (!class_exists($class) || !method_exists($class, $method)) {
        return NULL;
      }
      $callable = [$class, $method];
    }
    elseif (str_contains($callback, ':')) {
      [$service_id, $method] = explode(':', $callback, 2);
      if (!$this->serviceContainer->has($service_id)) {
        return NULL;
      }
      $service = $this->serviceContainer->get($service_id);
      if (!method_exists($service, $method)) {
        return NULL;
      }
      $callable = [$service, $method];
    }
    else {
      return NULL;
    }

    return $this->isTrustedCallable($callable) ? $callable : NULL;
  }

  /**
   * Checks whether the resolved callable is on the trusted-callback list.
   *
   * Equivalent of Drupal core's
   * `\Drupal\Core\Security\TrustedCallbackResolver::isTrusted()` for the
   * narrow subset of callable shapes this controller produces (always a
   * two-element array of object-or-class plus method name). Centralised
   * here so the policy is applied at the single point where untrusted
   * input reaches a method invocation.
   *
   * @param array{0: object|class-string, 1: string} $callable
   *   The two-element callable produced by `resolveCallable()`.
   */
  private function isTrustedCallable(array $callable): bool {
    $object_or_class = $callable[0];
    if (is_object($object_or_class)) {
      return $object_or_class instanceof TrustedCallbackInterface;
    }
    if (is_string($object_or_class)) {
      // is_subclass_of accepts an interface name as the second argument
      // and reports whether the class implements that interface, which
      // is exactly the check we need without instantiating the class.
      return is_subclass_of($object_or_class, TrustedCallbackInterface::class);
    }
    return FALSE;
  }

}
