<?php

namespace Drupal\mhco_form_filler\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class AdminFormGenerationForm extends FormBase
{
    public function getFormId()
    {
        return 'admin_form_generation_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state)
    {

      // 1. Add the custom CSS class to the form wrapper
        $form['#attributes']['class'][] = 'mhco-admin-form-selector';

      // 2. Attach the library specifically to this form (Optional, but best practice)
        $form['#attached']['library'][] = 'mhco_form_filler/form-filler-js';

        $form['target_user'] = [
        '#type' => 'entity_autocomplete',
        '#target_type' => 'user',
        '#title' => $this->t('Generate as Member (Admin Only):'),
        '#description' => $this->t('Leave blank to download for yourself. Otherwise, search a member by username, select them, and click a form below.'),
        '#attributes' => ['id' => 'admin-target-user'],
        ];

        return $form;
    }

    public function submitForm(array &$form, FormStateInterface $form_state)
    {
      // Intentionally left blank.
    }
}
