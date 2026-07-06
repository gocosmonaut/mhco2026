<?php

declare(strict_types=1);

namespace Drupal\lscache_purger;

/**
 * Helpers for reasoning about the configured PURGE host.
 */
final class PurgeHost {

  /**
   * Determines whether a purge-host URL points at the loopback interface.
   *
   * Loopback purge hosts are the standard single-server setup, where
   * Drupal and LiteSpeed share a box and the PURGE never leaves it. They
   * get special handling in several places, so the definition lives here
   * once rather than being copied across the purger, the settings-form
   * test button, and the status report:
   *
   *   - TLS certificate verification is skipped, because a bare loopback
   *     address cannot carry a publicly trusted TLS certificate.
   *   - The scheme-mismatch status report row is suppressed, because
   *     matching the public traffic scheme would mean pointing at https
   *     on a bare IP, which cannot verify and only sends operators into
   *     a certificate error.
   *
   * @param string $purge_host_url
   *   A purge-host value such as http://127.0.0.1/ or https://localhost.
   *
   * @return bool
   *   TRUE when the URL's host is a recognised loopback address.
   */
  public static function isLoopback(string $purge_host_url): bool {
    $host = (string) parse_url($purge_host_url, PHP_URL_HOST);
    return $host === 'localhost'
      || $host === '127.0.0.1'
      || $host === '::1'
      || $host === '[::1]';
  }

}
