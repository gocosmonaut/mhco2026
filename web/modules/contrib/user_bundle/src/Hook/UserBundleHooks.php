<?php

namespace Drupal\user_bundle\Hook;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Hook implementations for user_bundle.
 */
class UserBundleHooks {

  use StringTranslationTrait;

  public function __construct(
    protected readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help($route_name, RouteMatchInterface $route_match) {
    switch ($route_name) {
      case 'help.page.user_bundle':
        $output = '';
        $output .= '<dt>' . $this->t('Adding account types') . '</dt>';
        $output .= '<dd>' . $this->t('Additional <em>account types</em> can be added on the <a href=":account_types">Account types page</a>.', [':account_types' => Url::fromRoute('entity.user_type.collection')->toString()]) . '</dd>';
        $output .= '<dt>' . $this->t('Managing user account fields') . '</dt>';
        $output .= '<dd>' . $this->t('You can manage the fields, form, and display settings for each user account type on the <a href=":account_types">Account types page</a>. By adding fields for e.g., a picture, a biography, or address, you can a create a custom profile for each type of user on the website. For background information on entities and fields, see the <a href=":field_help">Field module help page</a>.', [
          ':account_types' => Url::fromRoute('entity.user_type.collection')->toString(),
          ':field_help' => $this->moduleHandler->moduleExists('field') ? Url::fromRoute('help.page', ['name' => 'field'])->toString() : '#',
          ':accounts' => Url::fromRoute('entity.user.admin_form')->toString(),
        ]) . '</dd>';
        $output .= '</dl>';
        return $output;

      case 'entity.user_type.collection':
        return '<p>' . $this->t('This page provides a list of all account types on the site and allows you to manage the fields, form, and display settings for each.') . '</p>';

      case 'user.type_add':
        return '<p>' . $this->t('Individual account types can have different fields, configurations, and behaviors assigned to them.') . '</p>';

      case 'user.admin_create_form':
        return '<p>' . $this->t("This form allows administrators to register new users. Users' email addresses and usernames must be unique.") . '</p>';
    }
  }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme() {
    return [
      'user_bundle_user_add_list' => [
        'variables' => ['content' => NULL],
      ],
    ];
  }

  /**
   * Implements hook_entity_type_build().
   */
  #[Hook('entity_type_build')]
  public function entityTypeBuild(array &$entity_types) {
    /** @var \Drupal\Core\Entity\EntityTypeInterface[] $entity_types */

    // Adjust the User entity type such that it supports bundles.
    $keys = $entity_types['user']->getKeys();
    $keys['bundle'] = 'type';
    $entity_types['user']
      ->setClass('Drupal\user_bundle\Entity\TypedUser')
      ->setStorageClass('Drupal\user_bundle\TypedUserStorage')
      ->set('bundle_label', $this->t('Account type'))
      ->set('entity_keys', $keys)
      ->set('bundle_entity_type', 'user_type')
      ->setFormClass('register', 'Drupal\user_bundle\TypedRegisterForm')
      ->set('field_ui_base_route', 'entity.user_type.edit_form');
  }

  /**
   * Implements hook_local_tasks_alter().
   */
  #[Hook('local_tasks_alter')]
  public function localTasksAlter(&$local_tasks) {
    // Now that we have bundles the Field UI local tasks have moved out to those
    // bundles leaving the entity.user.admin_form route bare. Without local
    // tasks here there's no longer any need to call this route out as a local
    // task.
    if (isset($local_tasks['user.account_settings_tab'])) {
      unset($local_tasks['user.account_settings_tab']);
    }
  }

  /**
   * Implements hook_entity_extra_field_info_alter().
   */
  #[Hook('entity_extra_field_info_alter')]
  public function entityExtraFieldInfoAlter(&$info) {
    // Core and contrib only target the "user" bundle when attaching extra
    // fields. Attach the "user" bundle's extra fields to all additional
    // bundles.
    foreach (array_keys(user_bundle_get_type_names()) as $bundle) {
      if ($bundle != 'user') {
        $info['user'][$bundle] = $info['user']['user'];
      }
    }
  }

  /**
   * Implements hook_menu_links_discovered_alter().
   */
  #[Hook('menu_links_discovered_alter')]
  public function menuLinksDiscoveredAlter(&$links) {
    if ($this->moduleHandler->moduleExists('field_ui')) {
      // Maintain backwards-compatibility with links to user Field UI routes
      // that lack the "user_type" route parameter.
      _user_bundle_field_menu_links_add_default_type($links);
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter() for the user_admin_settings form.
   */
  #[Hook('form_user_admin_settings_alter')]
  public function formUserAdminSettingsAlter(&$form, FormStateInterface $form_state, $form_id) {
    // Allow admins to choose the account type (user bundle) that will used to
    // build the user registration form.
    $form['registration_cancellation']['user_registration_user_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Visitor registration account type'),
      '#config_target' => 'user_bundle.settings:registration_user_type',
      '#options' => user_bundle_get_type_names(),
      '#description' => $this->t('This <a href=":types-url">account type</a> will be used to build the user registration form.  New accounts created through the user registration form will be of this account type.', [':types-url' => Url::fromRoute('entity.user_type.collection')->toString()]),
    ];

    // Allow admins to open extra account types to anonymous registration. The
    // default "user" bundle is left out; it registers through the normal
    // /user/register form selected above.
    $self_registration_options = user_bundle_get_type_names();
    unset($self_registration_options['user']);
    $form['registration_cancellation']['self_registration_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Account types open to registration'),
      '#config_target' => new ConfigTarget(
        'user_bundle.settings',
        'self_registration_types',
        toConfig: fn(array $value): array => array_values(array_filter($value)),
      ),
      '#options' => $self_registration_options,
      '#description' => $this->t('Anonymous users can register for each selected account type at <code>/user/register/[account-type]</code>, when visitor registration is allowed above.'),
    ];
  }

}
