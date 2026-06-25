<?php

declare(strict_types=1);

namespace Drupal\Tests\lscache\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Merge;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\StatementInterface;
use Drupal\lscache_purger\Affinity\TagAffinityStore;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\lscache_purger\Affinity\TagAffinityStore
 * @group lscache
 */
final class TagAffinityStoreTest extends UnitTestCase {

  /**
   * Records one merge() call per tag in the list.
   *
   * Each tag in the array becomes one merge() call keyed by (tag,
   * url_hash). The url field updates on conflict; last_seen
   * refreshes. The store does not deduplicate within a single call;
   * callers pass already-unique tag lists.
   */
  public function testRecordTagsForUrlFiresMergePerTag(): void {
    $merge = $this->createMock(Merge::class);
    $merge->method('keys')->willReturnSelf();
    $merge->method('fields')->willReturnSelf();
    $merge->expects($this->exactly(2))->method('execute');

    $database = $this->createMock(Connection::class);
    $database->method('merge')
      ->with(TagAffinityStore::TABLE)
      ->willReturn($merge);

    $store = new TagAffinityStore($database);
    $store->recordTagsForUrl('/node/38', ['node:38', 'node_list'], 1700000000);
  }

  /**
   * Empty tag array is a no-op.
   *
   * Empty tag array short-circuits; no merge() calls fire. The
   * recorder calls this path when LscacheResponseSubscriber filters
   * the tag set down to nothing emittable.
   */
  public function testRecordTagsForUrlNoopsOnEmptyTags(): void {
    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('merge');

    $store = new TagAffinityStore($database);
    $store->recordTagsForUrl('/node/38', [], 1700000000);
  }

  /**
   * Drops overlong URLs instead of truncating them.
   *
   * Overlong URLs are dropped rather than truncated. A truncated
   * URL is a wrong URL and would PURGE the wrong entry; safer to
   * drop and let TTL expire the stale cache.
   */
  public function testRecordTagsForUrlDropsOverlongUrls(): void {
    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('merge');

    $store = new TagAffinityStore($database);
    $store->recordTagsForUrl(str_repeat('a', 2049), ['node:38'], 1700000000);
  }

  /**
   * Drops overlong tags while keeping valid ones.
   *
   * Tags that exceed the schema VARCHAR(190) cap are dropped, same
   * rationale as the URL cap. Other tags in the same call still
   * record normally.
   */
  public function testRecordTagsForUrlSkipsOverlongTags(): void {
    $merge = $this->createMock(Merge::class);
    $merge->method('keys')->willReturnSelf();
    $merge->method('fields')->willReturnSelf();
    // Only the valid 'node:38' tag becomes a merge; the long one
    // is skipped.
    $merge->expects($this->once())->method('execute');

    $database = $this->createMock(Connection::class);
    $database->method('merge')->willReturn($merge);

    $store = new TagAffinityStore($database);
    $store->recordTagsForUrl('/node/38', [str_repeat('x', 200), 'node:38'], 1700000000);
  }

  /**
   * Returns URLs ordered by last_seen descending.
   *
   * Returns urls in last_seen DESC order so hottest pages evict
   * first. Empty array on tag miss (the cold-start case).
   */
  public function testGetUrlsForTagOrdersByLastSeenDesc(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchCol')->willReturn(['/hot', '/medium', '/cold']);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->expects($this->once())
      ->method('orderBy')
      ->with('last_seen', 'DESC')
      ->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($select);

    $store = new TagAffinityStore($database);
    $this->assertSame(['/hot', '/medium', '/cold'], $store->getUrlsForTag('node_list'));
  }

  /**
   * Empty tag input short-circuits without touching the database.
   */
  public function testGetUrlsForTagEmptyTagReturnsEmpty(): void {
    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('select');

    $store = new TagAffinityStore($database);
    $this->assertSame([], $store->getUrlsForTag(''));
  }

  /**
   * Sets the protected flag only when seeding.
   *
   * The protected flag rides in the merge fields only for seeded writes
   * (protected=1, so prune() never ages the row out). Ordinary traffic
   * observation omits the column entirely so the recorder's hot path is
   * immune to the upgrade window before the column is added, and so it
   * never demotes an already-seeded row.
   */
  public function testRecordTagsForUrlSetsProtectedFlagOnlyWhenSeeding(): void {
    $captured = [];
    $merge = $this->createMock(Merge::class);
    $merge->method('keys')->willReturnSelf();
    $merge->method('fields')->willReturnCallback(function (array $fields) use ($merge, &$captured) {
      $captured[] = $fields;
      return $merge;
    });
    $merge->method('execute')->willReturn(1);

    $database = $this->createMock(Connection::class);
    $database->method('merge')->willReturn($merge);

    $store = new TagAffinityStore($database);
    $store->recordTagsForUrl('/patch-notes', ['node_list'], 1700000000, TRUE);
    $this->assertSame(1, $captured[0]['protected'], 'Seeded rows carry protected=1.');

    $captured = [];
    $store->recordTagsForUrl('/node/38', ['node:38'], 1700000000);
    $this->assertArrayNotHasKey('protected', $captured[0], 'Observed rows omit the protected column.');
  }

  /**
   * Prune excludes protected rows.
   *
   * Prune must exclude protected rows: the bounded (select-then-delete)
   * path filters the candidate select on protected=0 so seeded Views
   * listings are never aged out.
   */
  public function testPruneExcludesProtectedRows(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn([]);

    $conditions = [];
    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('condition')->willReturnCallback(function ($field, $value = NULL, $op = NULL) use ($select, &$conditions) {
      $conditions[] = [$field, $value];
      return $select;
    });
    $select->method('execute')->willReturn($statement);

    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($select);

    $store = new TagAffinityStore($database);
    $this->assertSame(0, $store->prune(1700000000, 100));
    $this->assertContains(['protected', 0], $conditions, 'Prune candidate select filters on protected=0.');
  }

  /**
   * Filters to list-shaped tags.
   *
   * Pre-filters with a broad %list% LIKE at the DB, then narrows in PHP
   * to tags that genuinely end in _list or contain _list: - so a stray
   * tag like "shoppinglist_widget" the LIKE catches is dropped, while
   * node_list and node_list:help_article are kept.
   */
  public function testDistinctListTagsFiltersToListShapedTags(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchCol')->willReturn([
      'node_list',
      'node_list:help_article',
      'taxonomy_term_list:help_category',
      'shoppinglist_widget',
      'config:views.view.help',
    ]);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('distinct')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($select);

    $store = new TagAffinityStore($database);
    $this->assertSame(
      ['node_list', 'node_list:help_article', 'taxonomy_term_list:help_category'],
      $store->distinctListTags(),
    );
  }

}
