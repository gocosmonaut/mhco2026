<?php

namespace Drupal\content_copier\Commands;

use Drush\Commands\DrushCommands;
use Drupal\node\Entity\Node;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\path_alias\Entity\PathAlias;

/**
 * Drush commands for copying content.
 */
class ContentCopierCommands extends DrushCommands
{

  /**
   * Copies nodes from two source content types to a new content type.
   *
   * @param string $type1
   *   Machine name of the first source content type.
   * @param string $type2
   *   Machine name of the second source content type.
   * @param string $new_type
   *   Machine name of the target content type.
   *
   * @command content:copy
   * @aliases ccop
   * @usage drush content:copy old_type1 old_type2 new_type
   *   Copies all nodes from old_type1 and old_type2 to new_type.
   *
   **/


  public function copyContent($type1, $type2, $new_type)
  {
    $source_types = [$type1, $type2];
    $total_copied = 0;

    foreach ($source_types as $source_type) {
      $nids = \Drupal::entityQuery('node')
        ->condition('type', $source_type)
        ->accessCheck(FALSE)
        ->execute();

      foreach ($nids as $nid) {
        $old_node = Node::load($nid);
        if (!$old_node) {
          continue;
        }
        $terms_id = [];
        foreach ($old_node->field_ct as $item) {
          $terms_id[] = $item->target_id;
        }
        $author_ids = [];
        foreach ($old_node->field_author as $author) {
          $author_ids = $author->target_id;
        }

        if (!$author_ids) {
          $author_ids = "934";
        }

        $publication_date = $old_node->field_publication_date->value;
        if (!$publication_date) {
          $this->logger()->notice('No publication date');
          $publication_date_unix = $old_node->getCreatedTime();
          $publication_date = date('Y-m-d', $publication_date_unix);
          $this->logger()->notice('Used instead ' . $publication_date);
          $this->logger()->notice('Old nid ' . $publication_date);
        }

        $qt = $old_node->field_question_or_teaser->value;
        $body_value = $old_node->body->value;
        $body_summary = $old_node->body->summary;
        if (!$qt) {
          $qt = $body_summary;
          if (!$body_summary) {
            $qt = $body_value;
          }
        }

        $article_image = $old_node->field_what_image_should_accompan->target_id;
        $author_company = $old_node->field_what_is_the_author_s_compa->target_id;
        $author_title = $old_node->field_what_is_the_author_s_job_t->target_id;
        $column_category = $old_node->field_column_category->target_id;
        $column_icon = $old_node->field_column_icon->target_id;

        if (!$article_image) {
          $article_image = "1506";
        }

        if (!$author_company) {
          $author_company = "1713";
        }

        if (!$author_title) {
          $author_title = "985";
        }

        if (!$column_category) {
          $column_category = "2167";
        }

        if (!$column_icon) {
          $column_icon = "2168";
        }

        // Create a new node of the target type, copying base properties.
        $new_node = Node::create([
          'type' => $new_type,
          'title' => $old_node->getTitle(),
          'body' => [
            'value' => $old_node->body->value,
            'format' => $old_node->body->format,
            'summary' => $qt,
          ],
          'field_original_nid' => $nid,
          'field_author' => $author_ids,
          'field_column_icon' => $column_icon,
          'field_ct' => $terms_id,
          'field_legacy_content_type' => $old_node->getType(),
          'field_publication_date' => $publication_date,
          'field_question_or_teaser' => [
            'value' => $qt,
            'format' => "basic_html",
          ],
          'field_what_image_should_accompan' => $article_image,
          'field_what_is_the_author_s_compa' => $author_company,
          'field_what_is_the_author_s_job_t' => $author_title,
          'field_column_category' => $column_category,
          'uid' => $old_node->getOwnerId(),
          'created' => $old_node->getCreatedTime(),
          'changed' => $old_node->getChangedTime(),
          'status' => $old_node->isPublished() ? 1 : 0,
          'langcode' => $old_node->language()->getId(),
        ]);

        $new_node->save();
        $total_copied++;

        $this->logger()->success(dt('Copied node @nid from @source to @target.', [
          '@nid' => $nid,
          '@source' => $source_type,
          '@target' => $new_type,
        ]));
      }
    }

    $this->logger()->success(dt('Copy completed. @count nodes copied.', ['@count' => $total_copied]));
  }
}