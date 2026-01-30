<?php

namespace Drupal\tagclouds;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The Class TagService.
 *
 * @package Drupal\tagclouds
 */
class TagService implements TagServiceInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The cache store.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheStore;

  /**
   * Request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The connection to database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_store
   *   The cache store.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LanguageManagerInterface $language_manager, CacheBackendInterface $cache_store, RequestStack $request_stack, Connection $connection) {
    $this->configFactory = $config_factory;
    $this->languageManager = $language_manager;
    $this->cacheStore = $cache_store;
    $this->requestStack = $request_stack->getCurrentRequest();
    $this->connection = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public function sortTags(array $tags, $sort_order = NULL) {
    if ($sort_order == NULL) {
      $config = $this->configFactory->get('tagclouds.settings');
      $sort_order = $config->get('sort_order');
    }

    [$sort, $order] = explode(',', $sort_order);

    switch ($sort) {
      case 'title':
        usort($tags, [$this, 'sortByTitle']);
        break;

      case 'count':
        usort($tags, [$this, 'sortByCount']);
        break;

      case 'random':
        shuffle($tags);
        break;
    }
    if ($order == 'desc') {
      $tags = array_reverse($tags);
    }

    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function getTags(array $vids, $steps = 6, $size = 60, $display = NULL) {
    // Build the options so we can cache multiple versions.
    $language = $this->languageManager->getCurrentLanguage();
    $cache_name = implode(':', [
      'tagclouds',
      implode('_', $vids),
      $language->getId(),
      $this->requestStack->getRequestUri(),
      $steps,
      $size,
      $display,
    ]);
    // Check if the cache exists.
    $cache = $this->cacheStore->get($cache_name);
    $tags = [];
    // Make sure cache has data.
    if (!empty($cache->data)) {
      $tags = $cache->data;
    }
    else {

      if (count($vids) == 0) {
        return [];
      }
      $config = $this->configFactory->get('tagclouds.settings');

      $query = $this->connection->select('taxonomy_term_data', 'td');
      $query->addExpression('COUNT(td.tid)', 'count');
      $query->fields('tfd', ['name', 'description__value']);
      $query->fields('td', ['tid', 'vid']);
      $query->addExpression('MIN(tn.nid)', 'nid');

      $query->join('taxonomy_index', 'tn', 'td.tid = tn.tid');
      $query->join('node_field_data', 'n', 'tn.nid = n.nid');
      $query->join('taxonomy_term_field_data', 'tfd', 'tfd.tid = tn.tid');

      if ($config->get('language_separation')) {
        $query->condition('n.langcode', $language->getId());
      }

      $query->condition('tfd.langcode', $language->getId());

      $query->condition('td.vid', $vids, 'IN');
      $query->condition('n.status', 1);
      $query->condition('tfd.status', 1);

      $query->groupBy('td.tid')->groupBy('td.vid')->groupBy('tfd.name');
      $query->groupBy('tfd.description__value');

      $query->having('COUNT(td.tid)>0');
      $query->orderBy('count', 'DESC');

      if ($size > 0) {
        $query->range(0, $size);
      }
      $query->addTag('tagclouds_get_tags');
      $result = $query->execute()->fetchAll();

      foreach ($result as $tag) {
        $tags[$tag->tid] = $tag;
      }
      if ($display == NULL) {
        $display = $config->get('display_type');
      }
      $tags = $this->buildWeightedTags($tags, $steps);

      $this->cacheStore->set($cache_name, $tags, CacheBackendInterface::CACHE_PERMANENT, [
        'node_list',
        'taxonomy_term_list',
        'config:tagclouds.settings',
      ]);
    }

    return $tags;
  }

  /**
   * Returns an array with weighted tags.
   *
   * This is the hard part. People with better ideas are very very welcome to
   * send these to ber@webschuur.com. Distribution is one thing that needs
   * attention.
   *
   * @param array $tags
   *   A list of <em>objects</em> with the following attributes: $tag->count,
   *   $tag->tid, $tag->name and $tag->vid. Each Tag will be calculated and
   *   turned into a tag. Refer to tagclouds_get__tags() for an example.
   * @param int $steps
   *   (optional) The amount of tag-sizes you will be using. If you give "12"
   *   you still get six different "weights". Defaults to 6.
   *
   * @return array
   *   An <em>unordered</em> array with tags-objects, containing the attribute
   *   $tag->weight.
   */
  private function buildWeightedTags(array $tags, $steps = 6) {
    // Find minimum and maximum log-count. By our MatheMagician Steven Wittens
    // aka UnConeD.
    $tags_tmp = [];
    $min = 1e9;
    $max = -1e9;
    foreach ($tags as $id => $tag) {
      $tag->number_of_posts = $tag->count;
      $tag->weightcount = log($tag->count);
      $min = min($min, $tag->weightcount);
      $max = max($max, $tag->weightcount);
      $tags_tmp[$id] = $tag;
    }
    // Note: we need to ensure the range is slightly too large to make sure even
    // the largest element is rounded down.
    $range = max(.01, $max - $min) * 1.0001;
    foreach ($tags_tmp as $key => $value) {
      $tags[$key]->weight = 1 + floor($steps * ($value->weightcount - $min) / $range);
    }
    return $tags;
  }

  /**
   * Callback for usort, sort by title.
   *
   * @param object $a
   *   The A sample.
   * @param object $b
   *   The B Sample.
   *
   * @see :sortTags()
   *
   * @return int
   *   comparison result.
   */
  private static function sortByTitle($a, $b) {
    return strnatcasecmp($a->name, $b->name);
  }

  /**
   * Callback for usort, sort by count.
   *
   * @param object $a
   *   The A sample.
   * @param object $b
   *   The B sample.
   *
   * @see :sortTags()
   *
   * @return int
   *   comparision result.
   */
  private static function sortByCount($a, $b) {
    return $a->count <=> $b->count;
  }

}
