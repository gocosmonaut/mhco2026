<?php

namespace Drupal\park_directory\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ParkDirectoryForm extends FormBase
{  // Rename to match your form

  protected $httpClient;

  public function __construct(ClientInterface $http_client)
  {
    $this->httpClient = $http_client;
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('http_client')
    );
  }

  public function getFormId()
  {
    return 'park_directory_form';  // Match your form ID
  }

  public function buildForm(array $form, FormStateInterface $form_state)
  {
    // Your existing fields (e.g., param1, conditional param2, etc.)

      // Radio button (value submitted directly)
    $form['txtQueryType'] = [
      '#type' => 'radios',
      '#title' => $this->t('Select an Option'),
      '#options' => [
        'City' => $this->t('City'),  // Use meaningful values here
        'Name' => $this->t('Park Name'),
        'Address' => $this->t('Address'),  // Use meaningful values here
       'County' => $this->t('County'),
      ],
      '#default_value' => 'Name',  // Optional default
    ];

    $form['txtName'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name.'),
    ];

        $form['txtCity'] = [
      '#type' => 'textfield',
      '#title' => $this->t('City.'),
    ];

    $form['txtAddress'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Community Address'),
    ];

     $form['mnuCounty'] = [
    '#type' => 'select',
    '#title' => $this->t('Select an Option'),
    '#options' => [
      '00Select' => $this->t('- Select -'),
      '01Baker' => $this->t('Baker'),
      '02Benton' => $this->t('Benton'),
      '03Clackamas' => $this->t('Clackamas'),
      // Add more key => label pairs as needed
    ],
   '#default_value' => '00Select',  // Optional: Pre-select a value
    '#required' => FALSE,  // Optional: Make it required if needed
   // '#empty_value' => '',  // Optional: Add a blank "-- Select --" option
  //  '#empty_option' => $this->t('-- Select --'),
  ];



  
    $form['radiobuttonDisplay'] = [
      '#type' => 'hidden',
      '#value' => 'Open',
    ];
        $form['txtStatusQuery'] = [
      '#type' => 'hidden',
      '#value' => 'Open',
    ];


    $form['conditional_param'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Conditional Parameter'),
      '#states' => [
        'visible' => [
          ':input[name="param1"]' => ['filled' => TRUE],
        ],
      ],
    ];

    // Add more fields as needed...

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
      '#ajax' => [
        'callback' => '::ajaxSubmitCallback',
        'wrapper' => 'response-container',  // ID of the div to replace with response
      ],
    ];

    $form['actions']['reset'] = [
      '#type' => 'button',
      '#value' => $this->t('Clear Form'),
      '#attributes' => [
        'onclick' => 'this.form.reset(); return false;',
      ],
    ];

    // Response container
    $form['response'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'response-container'],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    // Add validation logic if needed (e.g., for required fields)
  }

  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    // Optional: Fallback for non-AJAX submits (rarely used here)
  }

  /**
   * AJAX callback to handle the submission server-side with Guzzle.
   */
  public function ajaxSubmitCallback(array &$form, FormStateInterface $form_state)
  {
    $response = new AjaxResponse();

    // Collect form values (automatically handles conditionals via visibility/validation)
    $values = $form_state->getValues();
    // Optionally filter out non-parameter keys (e.g., unset($values['op']);)

    try {
      // Make the POST request with Guzzle
      $httpResponse = $this->httpClient->post('https://appsprod.hcs.oregon.gov/MDPCRParks/ParkDirQuery.jsp', [
        'form_params' => $values,  // Sends as application/x-www-form-urlencoded
        // Or use 'json' => $values for JSON if the .jsp expects it
        // Add headers if needed: 'headers' => ['Content-Type' => 'application/json']
        // Timeout or other options: 'timeout' => 10
      ]);

      // Get the HTML response body
      $html = (string) $httpResponse->getBody();

      // Inject the HTML into the response container
      $response->addCommand(new HtmlCommand('#response-container', $html));
    } catch (RequestException $e) {
      // Handle errors (e.g., network issues, bad response)
      $errorMessage = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();
      $response->addCommand(new HtmlCommand('#response-container', '<p>Error: ' . $errorMessage . '</p>'));
    }

    return $response;
  }
}
