<?php

namespace Drupal\mdpcr_sync;

use GuzzleHttp\ClientInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 *
 */
class MdpcrDataSync {

  protected $httpClient;
  protected $fileRepository;
  protected $logger;

  const MAIN_URL = 'https://mdpcr.hcs.oregon.gov/parks';
  const TARGET_URI = 'public://mdpcr_parks.json';

  public function __construct(ClientInterface $http_client, FileRepositoryInterface $file_repository, LoggerChannelFactoryInterface $logger_factory) {
    $this->httpClient = $http_client;
    $this->fileRepository = $file_repository;
    $this->logger = $logger_factory->get('mdpcr_sync');
  }

  /**
   *
   */
  public function runSync(): bool {
    $this->logger->info('Starting remote registry pagination sync.');

    // Keep your current known-good token as a fallback.
    $build_token = '1jhgprt';

    try {
      $response = $this->httpClient->request('GET', self::MAIN_URL, [
        'headers' => ['User-Agent' => 'Mozilla/5.0'],
      ]);
      $html = (string) $response->getBody();

      // Look for the specific SvelteKit pattern: /_app/immutable/entry/start...
      // The token we need is the folder name immediately after /_app/remote/.
      if (preg_match('/\/_app\/remote\/([a-zA-Z0-9]+)\//', $html, $matches)) {
        $build_token = $matches[1];
        $this->logger->info('Successfully scraped new build token: @token', ['@token' => $build_token]);
      }
      else {
        // Fallback to searching the HTML for the SvelteKit start script if remote path isn't explicit.
        if (preg_match('/\/_app\/immutable\/entry\/start\.([a-zA-Z0-9]+)\.js/', $html, $matches)) {
          // Sometimes the build token is embedded in the JS file hashes.
          $this->logger->notice('Scraped via JS hash: @token', ['@token' => $matches[1]]);
          // Logic to update $build_token if needed.
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to scrape token. Falling back to: @token', ['@token' => $build_token]);
    }

    $all_clean_rows = [];
    $current_page = 1;
    $page_size = 50;
    $has_more_pages = TRUE;

    try {
      while ($has_more_pages) {
        // We have uncoupled pageNumber from parkStatusId by giving it a dedicated index (5)
        $payload_data = [
          [
            "searchText" => 1,
        // Points to Index 2 (Value: 1)
            "parkStatusId" => 2,
            "countyCode" => 1,
            "hasVacancies" => 3,
        // NEW: Points to Index 5.
            "pageNumber" => 5,
        // Points to Index 4 (Value: 50)
            "pageSize" => 4,
          ],
          // Index 1.
          "",
          // Index 2 (Strictly for parkStatusId now)
          1,
          // Index 3.
          FALSE,
          // Index 4.
          50,
          // Index 5: Our isolated, dynamic page number!
          $current_page,
        ];

        $base64_payload = base64_encode(json_encode($payload_data));
        $api_url = "https://mdpcr.hcs.oregon.gov/_app/remote/{$build_token}/getParkSearchResults?payload={$base64_payload}";

        $response = $this->httpClient->request('GET', $api_url, [
          'headers' => ['User-Agent' => 'Mozilla/5.0'],
        ]);

        $data = json_decode((string) $response->getBody(), TRUE);

        if (empty($data) || !isset($data['result'])) {
          throw new \Exception("Invalid JSON returned on page $current_page.");
        }

        $compressed_root = json_decode($data['result'], TRUE);
        $clean_rows = $this->normalizeRegistry($compressed_root);

        // 1. MERGE FIRST: Add the newly fetched rows to the main array immediately
        if (!empty($clean_rows)) {
          $all_clean_rows = array_merge($all_clean_rows, $clean_rows);
        }

        // 2. CHECK PAGINATION
        if (count($clean_rows) < $page_size) {
          $has_more_pages = FALSE;
        }
        else {
          $current_page++;
          usleep(500000);
        }
      } // <--- END OF THE WHILE LOOP. IT MUST CLOSE HERE!

      // =================================================================
      // 3. POST-PROCESS ONCE: Process everything after all pages are downloaded
      // =================================================================
      foreach ($all_clean_rows as &$row) {

        // Total Spaces Range.
        $total = (int) ($row['totalNumberOfSpaces'] ?? 0);
        $vacant = (int) ($row['numberOfVacantSpaces'] ?? 0);
        if ($total > 0) {
          $row['vacancyPercentage'] = round(($vacant / $total) * 100, 1);
        }
        else {
          $row['vacancyPercentage'] = 0.0;
        }

        // Park Status.
        $status_id = isset($row['parkStatusId']) ? (int) $row['parkStatusId'] : 1;
        $row['parkStatusText'] = ($status_id === 1) ? 'Open' : 'Closed';

        // Total Spaces Range Label.
        if ($total > 120) {
          $row['totalSpacesRange'] = '121 and up';
        }
        elseif ($total >= 81) {
          $row['totalSpacesRange'] = '81-120';
        }
        elseif ($total >= 41) {
          $row['totalSpacesRange'] = '41-80';
        }
        else {
          $row['totalSpacesRange'] = '1-40';
        }

        // Vacant Spaces Range Labels (Stored as an array so a park can have multiple tags)
        $vacant_tags = [];

        // Vacant Spaces Range Label (Combined tokens for "Contains" matching)
        if ($vacant >= 11) {
          $row['vacantSpacesRange'] = 'Has vacancy 11+';
        }
        elseif ($vacant >= 4) {
          $row['vacantSpacesRange'] = 'Has vacancy 4-10';
        }
        elseif ($vacant >= 1) {
          $row['vacantSpacesRange'] = 'Has vacancy 1-3';
        }
        else {
          $row['vacantSpacesRange'] = 'No vacancy';
        }

        // Assign the array to the row.
        $row['vacantSpacesRange'] = $vacant_tags;

        // =================================================================
        // SEARCH INDEX: Build the combined string for the View filter
        // =================================================================
        $name = $row['name'] ?? '';
        $street = $row['parkAddress']['street'] ?? '';

        // Safely extract the contact name (handles both 'name' or 'firstName'/'lastName' splits)
        $contact_first = $row['contact']['firstName'] ?? '';
        $contact_last = $row['contact']['lastName'] ?? '';
        $contact_full = trim(($row['contact']['name'] ?? '') . ' ' . $contact_first . ' ' . $contact_last);

        // Combine them into one big searchable string.
        $row['searchIndex'] = trim($name . ' ' . $street . ' ' . $contact_full);
      }

      // 4. WRITE FILE: Save the fully processed data to JSON
      $this->fileRepository->writeData(
        json_encode($all_clean_rows, JSON_PRETTY_PRINT),
        self::TARGET_URI,
        FileSystemInterface::EXISTS_REPLACE
      );

      $cities = [];
      $counties = [];
      $types = [];
      $zips = [];

      foreach ($all_clean_rows as $row) {
        if (!empty($row['parkAddress']['city'])) {
          $cities[$row['parkAddress']['city']] = 1;
        }
        if (!empty($row['countyName'])) {
          $counties[$row['countyName']] = 1;
        }
        if (!empty($row['type'])) {
          $types[$row['type']] = 1;
        }
        if (!empty($row['parkAddress']['zipCode'])) {
          $zips[$row['parkAddress']['zipCode']] = 1;
        }
      }

      ksort($cities);
      ksort($counties);
      ksort($types);
      ksort($zips);
      $metadata = [
        'cities' => array_keys($cities),
        'counties' => array_keys($counties),
        'types' => array_keys($types),
        'zips' => array_keys($zips),
        'totalSpacesRanges' => ['1-40', '41-80', '81-120', '121+'],
        'vacantSpacesRanges' => ['No vacancy', 'Has vacancy', '1-3', '4-10', '11+'],
      ];

      $this->fileRepository->writeData(
        json_encode($metadata, JSON_PRETTY_PRINT),
        'public://mdpcr_metadata.json',
        FileSystemInterface::EXISTS_REPLACE
      );

      $total_count = count($all_clean_rows);
      $this->logger->info("Success! Downloaded $total_count parks and saved to public://mdpcr_parks.json.");
      return TRUE;
    }
    catch (GuzzleException | \Exception $e) {
      $this->logger->error('API Sync breakdown on page @page: @msg', [
        '@page' => $current_page,
        '@msg' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   *
   */
  private function normalizeRegistry(array $root): array {
    if (!isset($root[1]) || !is_array($root[1])) {
      return [];
    }

    $flat_parks = [];
    foreach ($root[1] as $index) {
      if (isset($root[$index]) && is_array($root[$index])) {
        $flat_parks[] = $this->resolveReferences($root[$index], $root);
      }
    }
    return $flat_parks;
  }

  /**
   *
   */
  private function resolveReferences($item, array $root) {
    if (is_array($item)) {
      $resolved = [];
      foreach ($item as $key => $pointer) {
        $value = (is_int($pointer) && isset($root[$pointer])) ? $root[$pointer] : $pointer;

        if (is_string($value)) {
          // 1. Remove double spaces and trim
          $value = trim(preg_replace('/\s+/', ' ', $value));
          $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

          if ($key === 'phone') {
            // Strip any existing dashes, spaces, or parentheses just in case.
            $digits = preg_replace('/[^0-9]/', '', $value);

            if (strlen($digits) === 10) {
              $value = preg_replace('/(\d{3})(\d{3})(\d{4})/', '($1) $2-$3', $digits);
            }
          }

          // 2. Normalize specific typos
          // Existing City and County Normalization
          if ($key === 'city' || $key === 'countyName') {
            $value = ucwords(strtolower($value));

            // Define normalization map.
            $map = [
              'Mc Minnville' => 'McMinnville',
              'Milwalkie' => 'Milwaukie',
              'Milwaukee' => 'Milwaukie',
              'Milwaulkie' => 'Milwaukie',
              'Milton Freewater' => 'Milton-Freewater',
              'Milton-freewater' => 'Milton-Freewater',
              'St Helens' => 'St. Helens',
              'Mount Hood-parkdale' => 'Parkdale',
              'Mt Vernon' => 'Mount Vernon',
            ];

            if (isset($map[$value])) {
              $value = $map[$value];
            }
          }

          // NEW: State Abbreviation Normalization.
          if ($key === 'state') {
            $state_map = [
              'AZ' => 'Arizona',
              'MI' => 'Michigan',
              'TX' => 'Texas',
              'OR' => 'Oregon',
              'WA' => 'Washington',
              'CA' => 'California',
              'ID' => 'Idaho',
              'NV' => 'Nevada',
            ];

            // Clean the incoming value just in case it is lowercase or has spaces.
            $clean_state = strtoupper(trim($value));

            if (isset($state_map[$clean_state])) {
              $value = $state_map[$clean_state];
            }
          }
        }

        $resolved[$key] = is_array($value)
          ? $this->resolveReferences($value, $root)
          : $value;
      }
      return $resolved;
    }
    return $item;
  }

}
