<?php

/**
 * @file
 * Alters forms to add foundation button classes.
 */

/**
 * Implements hook_form_alter().
 */
function zurb_foundation_form_alter(&$form, &$form_state, $form_id) {
  // Sexy submit buttons.
  if (!empty($form['actions']) && !empty($form['actions']['submit'])) {
    $form['actions']['submit']['#attributes']['class'][] = 'success';
    $form['actions']['submit']['#attributes']['class'][] = 'button';
    $form['actions']['submit']['#attributes']['class'][] = 'radius';
  }
}
