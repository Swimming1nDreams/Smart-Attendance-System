<?php
$en = require 'lang/en.php';
$keys = [
    $en['attendance']['clock_in'] ?? '',
    $en['attendance']['clock_out'] ?? '',
    $en['attendance']['missing_check_out'] ?? '',
    $en['flash']['attendance_clock_in_success'] ?? '',
    $en['flash']['attendance_clock_out_success'] ?? '',
    $en['flash']['attendance_clock_out_required'] ?? '',
    $en['dashboard']['employee']['clock_blocked_holiday'] ?? '',
    $en['dashboard']['employee']['clock_blocked_non_working_day'] ?? '',
    $en['attendance']['no_clock_actions_profile'] ?? '',
    $en['validation']['attendance_holiday_check_in_blocked'] ?? '',
    $en['validation']['attendance_non_working_day_check_in_blocked'] ?? '',
    $en['validation']['attendance_employee_profile_missing'] ?? '',
];
foreach ($keys as $k) {
    if (strpos($k, 'Check-in') !== false) echo 'Found Check-in in: ' . $k . PHP_EOL;
    if (strpos($k, 'check-in') !== false) echo 'Found check-in in: ' . $k . PHP_EOL;
    if (strpos($k, 'Check-out') !== false) echo 'Found Check-out in: ' . $k . PHP_EOL;
    if (strpos($k, 'check-out') !== false) echo 'Found check-out in: ' . $k . PHP_EOL;
}
