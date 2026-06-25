<?php

namespace Drupal\lscache\Service;

/**
 * Thrown when an LSCache fragment-URL token fails verification.
 *
 * Distinct from a generic exception so the controller can map it to
 * a clean HTTP 403 rather than a 500.
 */
class InvalidTokenException extends \RuntimeException {
}
