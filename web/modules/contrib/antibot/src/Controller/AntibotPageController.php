<?php

namespace Drupal\antibot\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Implement Class AntibotPageController.
 *
 * @package Drupal\antibot\Controller
 */
class AntibotPageController extends ControllerBase {

  /**
   * The Antibot page where robotic form submissions end up.
   *
   * @return array
   *   Return message.
   */
  public function page(): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['antibot-message', 'antibot-message-error'],
      ],
      '#value' => $this->t('The Antibot form protection system has detected bot-like behavior and blocked your form submission. This protection is in place to attempt to prevent automated submissions made on forms by bots. Please return to the page that you came from and try to submit again. Also, make sure you have JavaScript enabled on your browser before attempting to submit the form again.'),
      '#attached' => [
        'library' => ['antibot/antibot.form'],
      ],
    ];
  }

}
