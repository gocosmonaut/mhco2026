<?php

declare(strict_types=1);

namespace Drupal\Tests\lscache\Unit;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lscache_purger\Affinity\TagAffinityStore;
use Drupal\lscache_purger\Coverage\ListTagCoverage;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\lscache_purger\Coverage\ListTagCoverage
 * @group lscache
 */
final class ListTagCoverageTest extends UnitTestCase {

  /**
   * Builds a ListTagCoverage with controllable config + affinity.
   *
   * @param array<string, mixed> $views
   *   Map of `views.view.<id>` config name => display array (the value
   *   the config object's get('display') returns).
   * @param array<string, string[]> $static_map
   *   The static_url_map: tag => urls.
   * @param string[] $observed
   *   Tags TagAffinityStore::distinctListTags() reports.
   * @param array<string, string[]> $warm
   *   Tag => affinity URLs for getUrlsForTag().
   */
  private function make(array $views, array $static_map, array $observed, array $warm): ListTagCoverage {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(
      static fn(string $key) => $key === 'static_url_map' ? $static_map : NULL,
    );

    $view_configs = [];
    foreach ($views as $name => $displays) {
      $cfg = $this->createMock(ImmutableConfig::class);
      $cfg->method('get')->willReturnCallback(
        static fn(string $key) => match ($key) {
          'display' => $displays,
          'label' => $name,
          default => NULL,
        },
      );
      $view_configs[$name] = $cfg;
    }

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('listAll')->willReturnCallback(
      static fn(string $prefix = '') => $prefix === 'views.view.' ? array_keys($view_configs) : [],
    );
    $config_factory->method('get')->willReturnCallback(
      static fn(string $name) => $name === 'lscache_purger.settings'
        ? $settings
        : ($view_configs[$name] ?? NULL),
    );

    $store = $this->createMock(TagAffinityStore::class);
    $store->method('distinctListTags')->willReturn($observed);
    $store->method('getUrlsForTag')->willReturnCallback(
      static fn(string $tag): array => $warm[$tag] ?? [],
    );

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getDefinitions')->willReturn([]);
    $bundle_info = $this->createMock(EntityTypeBundleInfoInterface::class);

    return new ListTagCoverage($etm, $bundle_info, $config_factory, $store);
  }

  /**
   * A Views page display contributes its list tags + path.
   */
  public function testViewsPageListingsExtractsListTagsAndPath(): void {
    $coverage = $this->make(
      views: [
        'views.view.patch_notes' => [
          'default' => [
            'display_plugin' => 'default',
            'display_options' => ['cache_metadata' => ['tags' => ['node_list']]],
          ],
          'page_1' => [
            'display_plugin' => 'page',
            'display_options' => [
              'path' => 'patch-notes',
              'cache_metadata' => ['tags' => ['node:1', 'node_list', 'config:views.view.patch_notes', 'http_response']],
            ],
          ],
          // A non-page display is ignored.
          'block_1' => [
            'display_plugin' => 'block',
            'display_options' => ['cache_metadata' => ['tags' => ['node_list']]],
          ],
        ],
      ],
      static_map: [],
      observed: [],
      warm: [],
    );

    $listings = $coverage->viewsPageListings();
    $this->assertCount(1, $listings);
    $this->assertSame('/patch-notes', $listings[0]['path']);
    $this->assertSame(['node_list'], $listings[0]['tags']);
  }

  /**
   * Templated (argument) paths are skipped - no single URL to pin.
   */
  public function testViewsPageListingsSkipsTemplatedPaths(): void {
    $coverage = $this->make(
      views: [
        'views.view.by_author' => [
          'page_1' => [
            'display_plugin' => 'page',
            'display_options' => [
              'path' => 'author/%user',
              'cache_metadata' => ['tags' => ['node_list']],
            ],
          ],
        ],
      ],
      static_map: [],
      observed: [],
      warm: [],
    );

    $this->assertSame([], $coverage->viewsPageListings());
  }

  /**
   * The report() method classifies pinned vs affinity-only vs uncovered.
   *
   * - node_list: a Views listing pinned in the static map -> pinned.
   * - node_list:help_article: observed in affinity, not pinned, warm
   *   -> affinity_only.
   * - taxonomy_term_list: a Views listing, not pinned, not warm
   *   -> uncovered.
   */
  public function testReportClassifiesCoverage(): void {
    $coverage = $this->make(
      views: [
        'views.view.patch_notes' => [
          'page_1' => [
            'display_plugin' => 'page',
            'display_options' => [
              'path' => 'patch-notes',
              'cache_metadata' => ['tags' => ['node_list']],
            ],
          ],
        ],
        'views.view.glossary' => [
          'page_1' => [
            'display_plugin' => 'page',
            'display_options' => [
              'path' => 'glossary',
              'cache_metadata' => ['tags' => ['taxonomy_term_list']],
            ],
          ],
        ],
      ],
      static_map: ['node_list' => ['/patch-notes']],
      observed: ['node_list:help_article'],
      warm: [
        'node_list' => ['/patch-notes'],
        'node_list:help_article' => ['/help'],
      ],
    );

    $by_tag = [];
    foreach ($coverage->report() as $row) {
      $by_tag[$row['tag']] = $row;
    }

    $this->assertSame('pinned', $by_tag['node_list']['status']);
    $this->assertSame('affinity_only', $by_tag['node_list:help_article']['status']);
    $this->assertSame(['/help'], $by_tag['node_list:help_article']['suggested_urls']);
    $this->assertSame('uncovered', $by_tag['taxonomy_term_list']['status']);
    $this->assertSame(['/glossary'], $by_tag['taxonomy_term_list']['suggested_urls']);

    // Problems sort ahead of pinned rows.
    $statuses = array_map(static fn(array $r): string => $r['status'], $coverage->report());
    $this->assertSame('pinned', end($statuses), 'Pinned rows sort last.');
  }

  /**
   * Treats an empty or whitespace list as unpinned.
   */
  public function testIsPinnedTreatsEmptyOrWhitespaceListAsUnpinned(): void {
    $coverage = $this->make(views: [], static_map: [], observed: [], warm: []);

    $this->assertTrue($coverage->isPinned('node_list', ['node_list' => ['/help']]));
    $this->assertFalse($coverage->isPinned('node_list', ['node_list' => []]));
    $this->assertFalse($coverage->isPinned('node_list', ['node_list' => ['']]));
    $this->assertFalse($coverage->isPinned('node_list', []));
  }

  /**
   * Suggested pin command uses YAML input format.
   */
  public function testSuggestedPinCommandUsesYamlInputFormat(): void {
    $cmd = ListTagCoverage::suggestedPinCommand('node_list:help_article', ['/help', '/help/getting-started']);
    $this->assertSame(
      "drush config:set lscache_purger.settings 'static_url_map.node_list:help_article' '[/help, /help/getting-started]' --input-format=yaml -y",
      $cmd,
    );
  }

}
