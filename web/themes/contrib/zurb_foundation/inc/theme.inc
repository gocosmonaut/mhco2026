<?php

/**
 * @file
 * Hook theme alters to customize theme suggestions.
 */

/**
 * Implements hook_theme_suggestions_alter().
 */
function zurb_foundation_theme_suggestions_alter(&$suggestions, $variables, $hook) {
  if ($hook == 'menu' || $hook == 'block') {
    // Add cached for getVisibleBlocksPerRegion because this doesn't happen in
    // core this can be removed once
    // https://www.drupal.org/project/drupal/issues/2479459 is resolved.
    $all_blocks = &drupal_static(__FUNCTION__, []);
    if (empty($all_blocks)) {
      $all_blocks = \Drupal::service('block.repository')->getVisibleBlocksPerRegion();
    }
    // For menu blocks, check to see if the menu block is placed in an
    // off-canvas region, and if so use our custom template.
    $off_canvas_blocks = [];
    if (!empty($all_blocks['left_off_canvas'])) {
      $off_canvas_blocks += $all_blocks['left_off_canvas'];
    }
    if (!empty($all_blocks['right_off_canvas'])) {
      $off_canvas_blocks += $all_blocks['right_off_canvas'];
    }
    foreach ($off_canvas_blocks as $block) {
      $plugin = $block->getPlugin();
      if ($plugin->getBaseId() == 'system_menu_block') {
        $menu_name = str_replace('-', '_', $plugin->getDerivativeId());
        if ($hook == 'menu' && $variables['theme_hook_original'] == 'menu__' . $menu_name) {
          $suggestions[] = 'menu__off_canvas';
          break;
        }
        elseif ($hook == 'block' && isset($variables['elements']['#id'])) {
          if ($variables['elements']['#id'] == $block->id()) {
            $suggestions[] = 'block__off_canvas';
            break;
          }
        }
      }
    }
  }
}
