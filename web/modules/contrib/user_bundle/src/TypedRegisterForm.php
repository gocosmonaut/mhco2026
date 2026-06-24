<?php

namespace Drupal\user_bundle;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\user\RegisterForm;

/**
 * A bundle-aware form handler for user register forms.
 */
class TypedRegisterForm extends RegisterForm {

  /**
   * {@inheritdoc}
   */
  public function getEntityFromRouteMatch(RouteMatchInterface $route_match, $entity_type_id) {
    // The user.register.type route names the account type to register. The
    // plain user.register route does not, so fall back to the configured
    // default account type.
    $user_type = $route_match->getParameter('user_type');
    $type = $user_type instanceof UserTypeInterface
      ? $user_type->id()
      : $this->config('user_bundle.settings')->get('registration_user_type');
    return $this->entityTypeManager->getStorage($entity_type_id)->create([
      'type' => $type,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    // Our module's settings can change the content of the registration form.
    // Add cache tags so this form is rebuilt any time our settings change.
    $form = parent::form($form, $form_state);
    $form['#cache']['tags'] += $this->config('user_bundle.settings')->getCacheTags();
    return $form;
  }

}
