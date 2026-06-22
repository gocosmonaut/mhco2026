<?php

/**
 * @file
 * Hooks and documentation related to antibot.
 */

/**
 * Modify the antibot protection of the form.
 *
 * @param string $form_id
 *   The form ID of the form.
 * @param bool $protection
 *   The protection of the form passed by parameter.
 */
function hook_antibot_form_status_alter(string $form_id, bool &$protection) {
  if ($form_id === 'my_form_id') {
    $protection = TRUE;
  }
}

/**
 * React to the rejection of a form submission.
 *
 * When antibot rejects a form submission, it calls this hook with the form ID,
 * and the user ID (0 if anonymous) of the user that was disallowed from
 * submitting the form.
 *
 * @param string $form_id
 *   Form ID of the form the user was disallowed from submitting.
 * @param int $uid
 *   0 for anonymous users, otherwise the user ID of the user.
 */
function hook_antibot_reject($form_id, $uid) {
  if ($form_id == 'my-module_form') {
    // Do something...
  }
}
