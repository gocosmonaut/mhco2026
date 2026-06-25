<?php

declare(strict_types=1);

namespace Drupal\lscache_purger\Commands;

use Drupal\lscache_purger\Coverage\ListTagCoverage;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command surfacing listing-page (list-cache-tag) coverage.
 *
 * The same computation the Status Report row uses, in a form a CI
 * pipeline can gate on: enumerate Views page listings + observed list
 * tags, classify each against static_url_map + affinity, print the
 * gaps with a copy-pasteable pin command, and exit non-zero when a
 * listing is genuinely uncovered.
 *
 * Exit semantics (so the command is useful in CI):
 *   - non-zero (EXIT_FAILURE) when at least one listing is UNCOVERED
 *     (no static pin and no affinity row) - it will serve stale until
 *     a manual PURGE, which is a release blocker.
 *   - zero otherwise. Affinity-only listings (warm now, lapse at
 *     2×TTL) are printed as advisories but do not fail the gate, since
 *     they currently evict; pin them to remove the lapse risk.
 */
class LscacheCoverageCommands extends DrushCommands {

  public function __construct(
    private readonly ListTagCoverage $coverage,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('lscache_purger.list_tag_coverage'),
    );
  }

  /**
   * Report static_url_map / affinity coverage for listing pages.
   */
  #[CLI\Command(name: 'lscache:list-tag-coverage', aliases: ['lscache-coverage'])]
  #[CLI\Option(name: 'all', description: 'Include pinned (already-covered) listings in the output.')]
  #[CLI\Usage(name: 'drush lscache:list-tag-coverage', description: 'List uncovered / affinity-only listings; exit non-zero if any are uncovered.')]
  #[CLI\Usage(name: 'drush lscache:list-tag-coverage --all', description: 'Also show listings that are already pinned.')]
  public function coverage(array $options = ['all' => FALSE]): int {
    $report = $this->coverage->report();
    if ($report === []) {
      $this->output()->writeln('No Views page listings or observed list-cache-tags found; nothing to cover.');
      return self::EXIT_SUCCESS;
    }

    $uncovered = 0;
    $affinity_only = 0;
    $pinned = 0;
    foreach ($report as $row) {
      switch ($row['status']) {
        case 'uncovered':
          $uncovered++;
          break;

        case 'affinity_only':
          $affinity_only++;
          break;

        default:
          $pinned++;
      }

      if (!$options['all'] && $row['status'] === 'pinned') {
        continue;
      }

      $this->output()->writeln(sprintf(
        '[%s] %s  (sources: %s)',
        strtoupper($row['status']),
        $row['tag'],
        implode(',', $row['sources']) ?: 'none',
      ));
      if ($row['suggested_urls'] !== []) {
        $this->output()->writeln('    urls: ' . implode(', ', $row['suggested_urls']));
      }
      if ($row['status'] !== 'pinned') {
        $this->output()->writeln('    fix:  ' . ListTagCoverage::suggestedPinCommand($row['tag'], $row['suggested_urls']));
      }
    }

    $this->output()->writeln('');
    $this->output()->writeln(sprintf(
      '%d uncovered, %d affinity-only, %d pinned.',
      $uncovered,
      $affinity_only,
      $pinned,
    ));

    return $uncovered > 0 ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

}
