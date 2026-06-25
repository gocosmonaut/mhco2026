<?php

namespace Drupal\lscache\Service;

use Drupal\Core\Site\Settings;

/**
 * Signs and verifies fragment-URL tokens for the ESI controller.
 *
 * The ESI fragment route (`/lscache-fragment/{token}`) is reachable
 * without a session or permission check, so the token must encode both
 * the callback and arguments to render *and* prove that the caller
 * holds the site's hash salt. Otherwise an attacker who can guess the
 * route shape could enumerate fragments by calling arbitrary lazy
 * builders with arbitrary args.
 *
 * Token format: `<base64url(payload-json)>.<hex(hmac-sha256)>` where the
 * payload is `{"cb": "<service:method or Class::method>", "args": [...]}`.
 * The HMAC is over the base64url-encoded payload, keyed on the site's
 * hash salt.
 *
 * Drupal's built-in `Drupal\Core\Site\Settings::getHashSalt()` returns
 * a per-install secret already used for CSRF tokens, password hashes,
 * and similar purposes. Reusing it here means no new secret needs to
 * be managed and the trust boundary is the same as for those existing
 * mechanisms.
 */
class LscacheTokenSigner {

  /**
   * Constructs the signer with an explicit hash salt.
   *
   * The salt is injected (rather than read from Settings inside this
   * class) so unit tests can substitute a fixed value.
   */
  public function __construct(
    private readonly string $hashSalt,
  ) {}

  /**
   * Factory using the live site's hash salt.
   */
  public static function fromSiteSettings(): self {
    return new self(Settings::getHashSalt());
  }

  /**
   * Signs a callback + args pair into an opaque URL-safe token.
   *
   * @param string $callback
   *   The callable in the same form `#lazy_builder` accepts:
   *   `service.name:methodName` for a service method, or
   *   `Class\\Name::staticMethod` for a static method.
   * @param array $args
   *   Arguments to pass when invoking the callback. Restricted to the
   *   same primitive types `#lazy_builder` allows (string, int, float,
   *   bool, NULL) so JSON round-trips are loss-free.
   *
   * @return string
   *   The signed token. URL-safe, no padding, no slashes.
   */
  public function sign(string $callback, array $args): string {
    $payload = json_encode(['cb' => $callback, 'args' => $args], JSON_THROW_ON_ERROR);
    $payload_b64 = $this->base64UrlEncode($payload);
    $signature = hash_hmac('sha256', $payload_b64, $this->hashSalt);
    return $payload_b64 . '.' . $signature;
  }

  /**
   * Verifies a token and returns the decoded payload.
   *
   * @param string $token
   *   The opaque token from the fragment URL.
   *
   * @return array{cb: string, args: array}
   *   The decoded payload.
   *
   * @throws \Drupal\lscache\Service\InvalidTokenException
   *   If the token is malformed, the signature does not match, or the
   *   payload fails decoding / shape validation.
   */
  public function verify(string $token): array {
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
      throw new InvalidTokenException('Token does not have the expected payload.signature shape.');
    }
    [$payload_b64, $signature] = $parts;

    $expected = hash_hmac('sha256', $payload_b64, $this->hashSalt);
    if (!hash_equals($expected, $signature)) {
      throw new InvalidTokenException('Token signature does not match.');
    }

    $payload_json = $this->base64UrlDecode($payload_b64);
    if ($payload_json === FALSE) {
      throw new InvalidTokenException('Token payload is not valid base64url.');
    }

    try {
      $decoded = json_decode($payload_json, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new InvalidTokenException('Token payload is not valid JSON: ' . $e->getMessage(), 0, $e);
    }

    if (!is_array($decoded) || !isset($decoded['cb']) || !is_string($decoded['cb']) || !isset($decoded['args']) || !is_array($decoded['args'])) {
      throw new InvalidTokenException('Token payload missing or has wrong shape.');
    }

    return ['cb' => $decoded['cb'], 'args' => $decoded['args']];
  }

  /**
   * URL-safe base64 encoding (RFC 4648 §5), without padding.
   */
  private function base64UrlEncode(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
  }

  /**
   * URL-safe base64 decoding.
   *
   * @param string $encoded
   *   The base64url-encoded input.
   *
   * @return string|false
   *   The decoded raw bytes, or FALSE if the input contains characters
   *   outside the base64url alphabet.
   */
  private function base64UrlDecode(string $encoded): string|false {
    // Pad to a multiple of 4 because PHP's base64_decode is fussy when
    // padding is missing; URL-safe form drops padding by convention.
    $remainder = strlen($encoded) % 4;
    if ($remainder !== 0) {
      $encoded .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($encoded, '-_', '+/'), TRUE);
  }

}
