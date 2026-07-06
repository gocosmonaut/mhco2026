<?php

namespace Drupal\mhco_form_filler\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\user\Entity\User;

class FormFillerController extends ControllerBase
{
    protected $httpClient;
    protected $database;
    protected $logger;

    public function __construct(ClientInterface $http_client, Connection $database, LoggerChannelFactoryInterface $logger_factory)
    {
        $this->httpClient = $http_client;
        $this->database = $database;
        $this->logger = $logger_factory->get('fillable form');
    }

    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('http_client'),
            $container->get('database'),
            $container->get('logger.factory')
        );
    }

    public function generatePdf(Request $request)
    {
        if (!$request->isMethod('POST')) {
            return new JsonResponse(['error' => 'Invalid request method'], 400);
        }

        $formNID = $request->request->get('formNID');
        $downloadLink = $request->request->get('downloadLink');
        $formName = $request->request->get('formName');
        $formNo = $request->request->get('formNo');
        $target_uid = $request->request->get('target_uid');

      // 1. Authenticate Current User
        $current_uid = $this->currentUser()->id();
        if ($current_uid == 0) {
            return new JsonResponse(['error' => 'You must be logged in to download forms.'], 403);
        }

      // Default to the current logged-in user
        $uid = $current_uid;
        $user = User::load($uid);

      // 2. Admin Override Logic
        if (!empty($target_uid) && $this->currentUser()->hasPermission('administer users')) {
            $override_user = User::load($target_uid);
            if ($override_user) {
                $user = $override_user;
                $uid = $override_user->id(); // Set the ID so DB records apply to the target user
            }
        }

      // 3. Map User Fields
        $park        = $user->hasField('field_profile_park') ? $user->get('field_profile_park')->getString() : '';
        $parkName    = $user->hasField('field_profile_name') ? $user->get('field_profile_name')->getString() : '';
        $parkAddress = $user->hasField('field_profile_address') ? $user->get('field_profile_address')->getString() : '';
        $parkPhone   = $user->hasField('field_profile_phone') ? $user->get('field_profile_phone')->getString() : '';
        $parkZIP     = $user->hasField('field_profile_zip') ? $user->get('field_profile_zip')->getString() : '';

        $parkLocation = '';
        if ($user->hasField('field_profile_location') && !$user->get('field_profile_location')->isEmpty()) {
            $location_term = $user->get('field_profile_location')->entity;
            if ($location_term) {
                $parkLocation = $location_term->label();
            }
        }

        $parkState = '';
        if ($user->hasField('field_profile_state') && !$user->get('field_profile_state')->isEmpty()) {
            $state_term = $user->get('field_profile_state')->entity;
            if ($state_term) {
                $parkState = $state_term->label();
            }
        }

        $parkCombined = $parkLocation . ', ' . $parkState . ' ' . $parkZIP;

      // 4. API Payload
        $data = [
        'async' => false,
        'encrypt' => false,
        'inline' => true,
        'name' => $park . '-' . $formNo . '-' . $formName,
        'url' => $downloadLink,
        'fields' => [
        ['fieldName' => 'profile_park', 'pages' => '*', 'text' => $park],
        ['fieldName' => 'profile_name', 'pages' => '*', 'text' => $parkName],
        ['fieldName' => 'profile_address', 'pages' => '*', 'text' => $parkAddress],
        ['fieldName' => 'profile_state', 'pages' => '*', 'text' => $parkState],
        ['fieldName' => 'profile_zip', 'pages' => '*', 'text' => $parkZIP],
        ['fieldName' => 'profile_location', 'pages' => '*', 'text' => $parkLocation],
        ['fieldName' => 'profile_phone', 'pages' => '*', 'text' => $parkPhone],
        ['fieldName' => 'profile_combined', 'pages' => '*', 'text' => $parkCombined],
        ]
        ];

      // 5. Execute API Call
        try {
            $response = $this->httpClient->request('POST', 'https://api.pdf.co/v1/pdf/edit/add', [
            'headers' => [
            'Content-Type' => 'application/json',
            'x-api-key' => 'daniel@gocosmonaut.com_756db820256962b1740fb343f56713b21353894ca78836244355682bdc96b86b65f4334b',
            ],
            'json' => $data,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $pdf_url = $body['url'] ?? null;

            if (!$pdf_url) {
                throw new \Exception('PDF URL missing from API response.');
            }
        } catch (\Exception $e) {
            $this->logger->error('PDF generation failed: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse(['error' => 'API generation failed'], 500);
        }

      // 6. Logging
        $current_user_name = $this->currentUser()->getDisplayName();
        $user_log_string = $current_user_name . ' (' . $this->currentUser()->id() . ')';

      // Add note if this was an admin generating for someone else
        if ($uid != $current_uid) {
            $user_log_string .= ' on behalf of ' . $user->getDisplayName() . ' (' . $uid . ')';
        }

        $this->logger->notice('Form @form_no downloaded by @park_name (User: @user_info)', [
        '@form_no'   => $formNo,
        '@park_name' => $park,
        '@user_info' => $user_log_string,
        ]);

      // 7. Direct Database Updates
        if ($formNID) {
            $this->database->update('node__field_form_download_count')
            ->expression('field_form_download_count_value', 'field_form_download_count_value + 1')
            ->condition('entity_id', $formNID)
            ->execute();
        }

        $this->database->update('user__field_park_form_downloads')
        ->expression('field_park_form_downloads_value', 'field_park_form_downloads_value + 1')
        ->condition('entity_id', $uid) // Records stat for the target user
        ->execute();

      // 8. Return URL
        return new JsonResponse(['pdf_url' => $pdf_url]);
    }
}
