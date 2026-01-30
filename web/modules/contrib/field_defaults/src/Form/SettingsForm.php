<?php

namespace Drupal\field_defaults\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\Messenger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for field defaults admin.
 */
class SettingsForm extends FormBase {

  /**
   * Config Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Messenger Service.
   *
   * @var \Drupal\Core\Messenger\Messenger
   */
  protected $messenger;

  /**
   * {@inheritdoc}
   */
  public function __construct(ConfigFactoryInterface $configFactory, Messenger $messenger) {
    $this->configFactory = $configFactory;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Instantiates this form class.
    return new static(
      $container->get('config.factory'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'field_defaults_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->configFactory->getEditable('field_defaults.settings');

    // @todo change to "preserve" to allow compatibility with preserve_changed module.
    $form['retain_changed_date'] = [
      '#title' => $this->t('Retain original entity updated time'),
      '#description' => $this->t('When default values are updated retain the entity original update date.'),
      '#type' => 'checkbox',
      '#default_value' => $config->get('retain_changed_date'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory->getEditable('field_defaults.settings');
    $config->set('retain_changed_date', $form_state->getValue('retain_changed_date'));
    $settings = $config->save();
    if ($settings) {
      $this->messenger()->addMessage($this->t('Settings saved'));
    }
  }

}
