<?php

namespace Drupal\user_bundle\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Token hook implementations for user_bundle.
 */
class UserBundleTokensHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_token_info_alter().
   */
  #[Hook('token_info_alter')]
  public function tokenInfoAlter(&$data) {
    // Add bundle tokens to the user token type.
    $data['tokens']['user']['type'] = [
      'name' => $this->t('Account type'),
    ];
    $data['tokens']['user']['type-name'] = [
      'name' => $this->t('Account type name'),
      'description' => $this->t('The human-readable name of the user account type.'),
    ];
  }

  /**
   * Implements hook_tokens().
   */
  #[Hook('tokens')]
  public function tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
    $replacements = [];

    if ($type == 'user' && !empty($data['user'])) {
      /** @var \Drupal\user\UserInterface $account */
      $account = $data['user'];
      foreach ($tokens as $name => $original) {
        switch ($name) {
          case 'type':
            $replacements[$original] = $account->getType();
            break;

          case 'type-name':
            $replacements[$original] = user_bundle_get_user_type_label($account);
            break;
        }
      }
    }

    return $replacements;
  }

}
