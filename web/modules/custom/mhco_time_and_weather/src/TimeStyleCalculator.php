<?php

namespace Drupal\mhco_time_and_weather;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Calculates the time-based style for the home page.
 */
class TimeStyleCalculator
{
  /**
   * The module extension list service.
   *
   * @var ModuleExtensionList
   */
  protected $moduleList;

  /**
   * The time service.
   *
   * @var TimeInterface
   */
  protected $time;

  /**
   * The application root path.
   *
   * @var string
   */
  protected $appRoot;

  /**
   * Constructs a new TimeStyleCalculator object.
   *
   * @param ModuleExtensionList $module_list
   *   The module extension list service.
   * @param TimeInterface $time
   *   The time service.
   * @param string $app_root
   *   The application root path.
   */
  public function __construct(
    ModuleExtensionList $module_list,
    TimeInterface $time,
    string $app_root,
  ) {
    $this->moduleList = $module_list;
    $this->time = $time;
    $this->appRoot = $app_root;
  }

  /**
   * Gets the current style based on the time of day and year.
   *
   * @return string
   *   The CSS class name representing the current time style.
   */
  public function getCurrentStyle(): string
  {
    $module_path = $this->moduleList->getPath('mhco_time_and_weather');
    $json_path = $this->appRoot . '/' . $module_path . '/data/weather.json';

    // Fallback if the file is missing.
    if (!file_exists($json_path)) {
      return 'daytime';
    }

    $data = json_decode(file_get_contents($json_path), TRUE);
    $timestamp = $this->time->getCurrentTime();

    // Map standard PHP 'M' abbreviations to the specific JSON keys.
    $month_map = [
      'Jan' => 'Jan',
      'Feb' => 'Feb',
      'Mar' => 'Mar',
      'Apr' => 'Apr',
      'May' => 'May',
      'Jun' => 'June',
      'Jul' => 'July',
      'Aug' => 'Aug',
      'Sep' => 'Sept',
      'Oct' => 'Oct',
      'Nov' => 'Nov',
      'Dec' => 'Dec',
    ];

    $php_month = date('M', $timestamp);
    $day = date('d', $timestamp);
    $json_month = $month_map[$php_month] ?? 'Jan';

    // Fallback if today's date doesn't exist in the JSON.
    if (!isset($data['data'][$json_month][$day])) {
      return 'daytime';
    }

    $today_data = $data['data'][$json_month][$day];

    // Convert HHMM string to minutes since midnight.
    $rise_hour = intval(substr($today_data['Rise'], 0, 2));
    $rise_min = intval(substr($today_data['Rise'], 2, 2));
    $rise_mins = ($rise_hour * 60) + $rise_min;

    $set_hour = intval(substr($today_data['Set'], 0, 2));
    $set_min = intval(substr($today_data['Set'], 2, 2));
    $set_mins = ($set_hour * 60) + $set_min;

    // Handle JSON metadata note: "Add one hour for daylight time...".
    if (date('I', $timestamp)) {
      $rise_mins += 60;
      $set_mins += 60;
    }

    $current_hour = intval(date('H', $timestamp));
    $current_min = intval(date('i', $timestamp));
    $current_mins = ($current_hour * 60) + $current_min;

    // Apply specific seasonal sunset rules.
    $summer_months = ['May', 'June', 'July', 'Aug'];
    $sunset_margin = in_array($json_month, $summer_months) ? 90 : 60;

    // 1. Sunrise condition: +/- 120 minutes.
    if (
      $current_mins >= ($rise_mins - 120) &&
      $current_mins <= ($rise_mins + 120)
    ) {
      return 'sunrise';
    }

    // 2. Sunset condition: +/- 60 or 90 minutes.
    if (
      $current_mins >= ($set_mins - $sunset_margin) &&
      $current_mins <= ($set_mins + $sunset_margin)
    ) {
      return 'sunset';
    }

    // 3. Daytime condition: Between sunrise window and sunset window.
    if (
      $current_mins > ($rise_mins + 120) &&
      $current_mins < ($set_mins - $sunset_margin)
    ) {
      return 'daytime';
    }

    // 4. Night condition: Everything else.
    return 'night';
  }
}
