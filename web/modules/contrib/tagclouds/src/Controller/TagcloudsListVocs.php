<?php

namespace Drupal\tagclouds\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\tagclouds\CloudBuilder;
use Drupal\tagclouds\TagService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller routines for user routes.
 */
class TagcloudsListVocs extends ControllerBase {

  use CsvToArrayTrait;

  /**
   * The tag service.
   *
   * @var \Drupal\tagclouds\TagService
   */
  protected $tagService;

  /**
   * The cloud builder service.
   *
   * @var \Drupal\tagclouds\CloudBuilder
   */
  protected $cloudBuilder;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new TagcloudsTermsBlock instance.
   *
   * @param \Drupal\tagclouds\TagService $tagService
   *   The tag service.
   * @param \Drupal\tagclouds\CloudBuilder $cloudBuilder
   *   The cloud builder service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(TagService $tagService, CloudBuilder $cloudBuilder, EntityTypeManagerInterface $entity_type_manager) {
    $this->tagService = $tagService;
    $this->cloudBuilder = $cloudBuilder;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tagclouds.tag'),
      $container->get('tagclouds.cloud_builder'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Renders a list of vocabularies.
   *
   * Vocabularys are wrapped in a series of boxes, labeled by name
   * description.
   *
   * @param string $tagclouds_vocs_str
   *   A comma separated list of vocabulary ids.
   *
   * @return array
   *   A render array.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when any vocabulary in the list cannot be found.
   */
  public function listVocs($tagclouds_vocs_str = NULL) {
    $vocs = $this->csvToArray($tagclouds_vocs_str);
    if (empty($vocs)) {
      throw new NotFoundHttpException();
    }

    $boxes = [];
    foreach ($vocs as $vid) {
      $vocabulary = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($vid);

      if ($vocabulary == FALSE) {
        throw new NotFoundHttpException();
      }

      $config = $this->config('tagclouds.settings');
      $tags = $this->tagService->getTags([$vid], $config->get('levels'), $config->get('page_amount'));
      $sorted_tags = $this->tagService->sortTags($tags);

      $cloud = $this->cloudBuilder->build($sorted_tags);

      if (!$cloud) {
        throw new NotFoundHttpException();
      }

      $boxes[] = [
        '#theme' => 'tagclouds_list_box',
        '#vocabulary' => $vocabulary,
        '#children' => $cloud,
      ];

    }

    // Wrap boxes in a div.
    $output = [
      '#attached' => ['library' => 'tagclouds/clouds'],
      '#type' => 'container',
      '#children' => $boxes,
      '#attributes' => ['class' => 'wrapper tagclouds'],
    ];

    return $output;
  }

}
