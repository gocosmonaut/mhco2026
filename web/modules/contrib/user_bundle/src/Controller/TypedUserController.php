<?php

namespace Drupal\user_bundle\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\user\Controller\UserController;
use Drupal\user_bundle\UserTypeInterface;

/**
 * Controller routines for typed user routes.
 */
class TypedUserController extends UserController {

  /**
   * Displays add user links for available user types.
   *
   * Redirects to admin/people/create/[type] if only one user type is available.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   A render array for a list of the user types that can be added; however,
   *   if there is only one user type defined for the site, the function
   *   will return a RedirectResponse to the user add page for that one user
   *   type.
   */
  public function adminCreatePage() {
    $build = [
      '#theme' => 'user_bundle_user_add_list',
      '#cache' => [
        'tags' => $this->entityTypeManager()->getDefinition('user_type')->getListCacheTags(),
      ],
    ];

    $content = $this->entityTypeManager()->getStorage('user_type')->loadMultiple();

    // Bypass the types listing if only one user type is available.
    if (count($content) == 1) {
      $type = array_shift($content);
      return $this->redirect('user.admin_create_form', ['user_type' => $type->id()]);
    }

    $build['#content'] = $content;

    return $build;
  }

  /**
   * Provides the admin user creation form.
   *
   * @param \Drupal\user_bundle\UserTypeInterface $user_type
   *   The user type entity for the user.
   *
   * @return array
   *   A user creation form.
   */
  public function adminCreateForm(UserTypeInterface $user_type) {
    $user = $this->entityTypeManager()->getStorage('user')->create([
      'type' => $user_type->id(),
    ]);

    $form = $this->entityFormBuilder()->getForm($user, 'register');

    return $form;
  }

  /**
   * The _title_callback for the user.admin_create_form route.
   *
   * @param \Drupal\user_bundle\UserTypeInterface $user_type
   *   The user type entity for the user.
   *
   * @return string
   *   The page title.
   */
  public function adminCreateFormPageTitle(UserTypeInterface $user_type) {
    return $this->t('Add @name', ['@name' => $user_type->label()]);
  }

  /**
   * Checks access to the registration form for a given account type.
   *
   * Anonymous registration is only allowed for the account types the site
   * administrator opened to registration.
   *
   * @param \Drupal\user_bundle\UserTypeInterface $user_type
   *   The account type being registered.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function registerAccess(UserTypeInterface $user_type): AccessResultInterface {
    $settings = $this->config('user_bundle.settings');
    $allowed = $settings->get('self_registration_types') ?: [];
    // Account types that aren't open to registration are treated as if they do
    // not exist, so this route can't be used to enumerate the site's account
    // types. The default "user" bundle registers through the normal
    // /user/register form, so it is never reachable here either.
    if ($user_type->id() === 'user' || !in_array($user_type->id(), $allowed, TRUE)) {
      $cacheability = (new CacheableMetadata())
        ->addCacheableDependency($settings)
        ->addCacheableDependency($user_type);
      throw new CacheableNotFoundHttpException($cacheability);
    }
    return AccessResult::allowed()
      ->addCacheableDependency($settings)
      ->addCacheableDependency($user_type);
  }

  /**
   * The _title_callback for the user.register.type route.
   *
   * @param \Drupal\user_bundle\UserTypeInterface $user_type
   *   The account type being registered.
   *
   * @return string
   *   The page title.
   */
  public function registerPageTitle(UserTypeInterface $user_type) {
    return $this->t('Create new @name account', ['@name' => $user_type->label()]);
  }

}
