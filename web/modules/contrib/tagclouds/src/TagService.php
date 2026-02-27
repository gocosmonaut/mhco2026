<?php

namespace Drupal\tagclouds;

use Drupal\content_translation\ContentTranslationManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The Class TagService.
 *
 * @package Drupal\tagclouds
 */
class TagService implements TagServiceInterface {

  use StringTranslationTrait;

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
   * The content translation manager.
   *
   * @var \Drupal\content_translation\ContentTranslationManagerInterface|null
   */
  protected ?ContentTranslationManagerInterface $contentTranslationManager;

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
   * @param \Drupal\content_translation\ContentTranslationManagerInterface|null $content_translation_manager
   *   The content translation manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LanguageManagerInterface $language_manager,
    CacheBackendInterface $cache_store,
    RequestStack $request_stack,
    Connection $connection,
    ?ContentTranslationManagerInterface $content_translation_manager,
  ) {
    $this->configFactory = $config_factory;
    $this->languageManager = $language_manager;
    $this->cacheStore = $cache_store;
    $this->requestStack = $request_stack->getCurrentRequest();
    $this->connection = $connection;
    $this->contentTranslationManager = $content_translation_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function sortTags(array $tags, string $sort_order = 'default'): array {
    if ($sort_order == 'default') {
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

      $total_count = count($vids);
      if ($total_count == 0) {
        return [];
      }
      $config = $this->configFactory->get('tagclouds.settings');
      $current_langcode = $language->getId();
      $default_langcode = $this->languageManager->getDefaultLanguage()->getId();

      $translatable_count = 0;
      // Check if the translation module even exists before trying to use it.
      if ($this->contentTranslationManager) {
        // Get counts of translatable vs total vocabularies.
        $translatable_vids = array_filter(
          $vids,
          fn($vid) => $this->contentTranslationManager->isEnabled('taxonomy_term', $vid)
        );
        $translatable_count = count($translatable_vids);
      }

      $query = $this->connection->select('taxonomy_term_data', 'td');
      $query->addExpression('COUNT(td.tid)', 'count');
      $query->fields('td', ['tid', 'vid']);
      $query->addExpression('MIN(tn.nid)', 'nid');
      $query->join('taxonomy_index', 'tn', 'td.tid = tn.tid');
      $query->join('node_field_data', 'n', 'tn.nid = n.nid');

      if ($config->get('language_separation')) {
        $query->condition('n.langcode', $current_langcode);
      }
      $query->condition('td.vid', $vids, 'IN');
      $query->condition('n.status', 1);

      // Apply language-specific logic based on our counts.
      if ($translatable_count == $total_count) {
        // All vocabularies are translatable, so we only need the
        // current language.
        $this->applyStrictLanguageQuery($query, $current_langcode);
      }
      elseif ($translatable_count == 0) {
        // All vocabularies are non-translatable, so we only need the
        // default language.
        $this->applyStrictLanguageQuery($query, $default_langcode);
      }
      else {
        // We have a mix. We need the complex COALESCE logic.
        $this->applyFallbackLanguageQuery($query, $current_langcode, $default_langcode);
      }

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
   * Applies a simple query logic for a single language.
   *
   * Used when ALL vocabularies are translatable (use current lang)
   * OR when ALL vocabularies are non-translatable (use default lang).
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query object to modify.
   * @param string $langcode
   *   The specific langcode (current or default) to join on.
   */
  private function applyStrictLanguageQuery(SelectInterface $query, string $langcode): void {
    $query->fields('tfd', ['name', 'description__value']);

    // Join 'tfd' only on the specific langcode we need.
    $query->join('taxonomy_term_field_data', 'tfd', 'tfd.tid = tn.tid');

    $query->condition('tfd.langcode', $langcode);
    $query->condition('tfd.status', 1);

    // Group by the fields we selected.
    $query->groupBy('td.tid')->groupBy('td.vid')->groupBy('tfd.name');
    $query->groupBy('tfd.description__value');
  }

  /**
   * Applies the complex COALESCE query logic for mixed vocabularies.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query object to modify.
   * @param string $current_lang
   *   The current page language.
   * @param string $default_lang
   *   The site's default language.
   */
  private function applyFallbackLanguageQuery(SelectInterface $query, string $current_lang, string $default_lang): void {
    // Try to get the term data for the current language.
    $query->leftJoin('taxonomy_term_field_data', 'tfd',
      'tfd.tid = tn.tid AND tfd.langcode = :current_langcode',
      [':current_langcode' => $current_lang]
    );

    $status_condition = $query->orConditionGroup();

    // If we are not on the main language, also get the term data for the
    // main language as a fallback.
    if ($current_lang != $default_lang) {
      $query->leftJoin('taxonomy_term_field_data', 'tfd_default',
        'tfd_default.tid = tn.tid AND tfd_default.langcode = :default_langcode',
        [':default_langcode' => $default_lang]
      );

      // Get the translated name if it exists, otherwise get the default name.
      $query->addExpression('COALESCE(tfd.name, tfd_default.name)', 'name');
      $query->addExpression('COALESCE(tfd.description__value, tfd_default.description__value)', 'description__value');

      // The term must be published in either language.
      $status_condition
        ->condition('tfd.status', 1)
        ->condition('tfd_default.status', 1);
    }
    else {
      // We are on the main language, no fallback needed.
      $query->addExpression('tfd.name', 'name');
      $query->addExpression('tfd.description__value', 'description__value');
      $status_condition->condition('tfd.status', 1);
    }

    $query->condition($status_condition);
    $query->groupBy('td.tid')->groupBy('td.vid');
    $query->groupBy('name');
    $query->groupBy('description__value');
  }

  /**
   * {@inheritdoc}
   */
  public function getSortingOptions(): array {
    return [
      'title,asc' => $this->t('by title, ascending'),
      'title,desc' => $this->t('by title, descending'),
      'count,asc' => $this->t('by count, ascending'),
      'count,desc' => $this->t('by count, descending'),
      'random,none' => $this->t('random'),
    ];
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
    // Find minimum and maximum log-count. By our "MatheMagician Steven Wittens"
    // aka UnConeD.
    $tags_tmp = [];
    $min = 1e9;
    $max = -1e9;
    foreach ($tags as $id => $tag) {
      $tag->number_of_posts = $tag->count;
      $tag->weight_count = log($tag->count);
      $min = min($min, $tag->weight_count);
      $max = max($max, $tag->weight_count);
      $tags_tmp[$id] = $tag;
    }
    // Note: we need to ensure the range is slightly too large to make sure even
    // the largest element is rounded down.
    $range = max(.01, $max - $min) * 1.0001;
    foreach ($tags_tmp as $key => $value) {
      $tags[$key]->weight = 1 + floor($steps * ($value->weight_count - $min) / $range);
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
   *   Comparison result.
   */
  private static function sortByCount($a, $b) {
    return $a->count <=> $b->count;
  }

}
