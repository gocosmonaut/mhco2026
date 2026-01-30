<?php

namespace Drupal\tagclouds;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Constructs a Drupal\tagclouds\CloudBuilder.
 *
 * @package Drupal\tagclouds
 */
class CloudBuilder implements CloudBuilderInterface {

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
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity storage.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LanguageManagerInterface $language_manager, EntityTypeManagerInterface $entity_type_manager) {
    $this->configFactory = $config_factory;
    $this->languageManager = $language_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('language_manager'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $terms) {
    $output = [];
    $config = $this->configFactory->getEditable('tagclouds.settings');
    $display = $config->get('display_type');
    if (empty($display)) {
      $display = 'style';
    }

    if ($display == 'style') {
      foreach ($terms as $term) {
        $term_name = $term->name;
        $term_desc = $term->description__value;

        if ($term->count == 1 && $config->get("display_node_link")) {
          $output[$term->tid] = $this->displayNodeLinkWeight($term_name, $term->tid, $term->nid, $term->weight, $term_desc);
        }
        else {
          $output[$term->tid] = $this->displayTermLinkWeight($term_name, $term->tid, $term->weight, $term_desc);
        }
      }
    }
    else {
      foreach ($terms as $term) {
        $term_name = $term->name;
        $term_desc = $term->description__value;
        if ($term->count == 1 && $config->get("display_node_link")) {
          $output[$term->tid] = $this->displayNodeLinkCount($term_name, $term->tid, $term->nid, $term->count, $term_desc);
        }
        else {
          $output[$term->tid] = $this->displayTermLinkCount($term_name, $term->tid, $term->count, $term_desc);
        }
      }
    }
    return $output;
  }

  /**
   * Display Single Tag with Style.
   */
  private function displayTermLinkWeight($name, $tid, $weight, $description) {
    if ($term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid)) {
      $uri = $term->toUrl();
      $options = $uri->getOptions();
      $options['attributes']['class'][] = 'tagclouds';
      $options['attributes']['class'][] = 'level' . $weight;
      $options['language'] = $this->languageManager->getCurrentLanguage();
      $uri->setOptions($options);

      $build = [
        '#type' => 'link',
        '#prefix' => '<span class="tagclouds-term">',
        '#title' => $name,
        '#url' => $uri,
        '#suffix' => '</span>',
      ];

      return $build;
    }
  }

  /**
   * Display node link weight.
   */
  private function displayNodeLinkWeight($name, $tid, $nid, $weight, $description) {
    if (($this->entityTypeManager->getStorage('taxonomy_term')->load($tid)) && ($node = $this->entityTypeManager->getStorage('node')->load($nid))) {
      $uri = $node->toUrl();
      $options = $uri->getOptions();
      $options['attributes']['class'][] = 'tagclouds';
      $options['attributes']['class'][] = 'level' . $weight;
      $options['language'] = $this->languageManager->getCurrentLanguage();
      $uri->setOptions($options);

      $build = [
        '#type' => 'link',
        '#prefix' => '<span class="tagclouds-term">',
        '#title' => $name,
        '#url' => $uri,
        '#suffix' => '</span>',
      ];

      return $build;
    }
  }

  /**
   * Display Single Tag with Style.
   */
  private function displayNodeLinkCount($name, $tid, $nid, $count, $description) {
    if (($this->entityTypeManager->getStorage('taxonomy_term')->load($tid)) && ($node = $this->entityTypeManager->getStorage('node')->load($nid))) {
      $uri = $node->toUrl();
      $options = $uri->getOptions();
      $options['attributes']['class'][] = 'tagclouds';
      $options['language'] = $this->languageManager->getCurrentLanguage();
      $uri->setOptions($options);

      $build = [
        '#type' => 'link',
        '#prefix' => '<span class="tagclouds-term">',
        '#title' => $name,
        '#url' => $uri,
        '#suffix' => " ($count)</span>",
      ];

      return $build;
    }
  }

  /**
   * Display term link count.
   */
  private function displayTermLinkCount($name, $tid, $count, $description) {
    if ($term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid)) {
      $uri = $term->toUrl();
      $options = $uri->getOptions();
      $options['options']['attributes']['class'][] = 'tagclouds';
      $options['language'] = $this->languageManager->getCurrentLanguage();
      $uri->setOptions($options);

      $build = [
        '#type' => 'link',
        '#prefix' => '<span class="tagclouds-term">',
        '#title' => $name,
        '#url' => $uri,
        '#suffix' => "($count) </span>",
      ];

      return $build;
    }
  }

}
