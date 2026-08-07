<?php

declare(strict_types=1);

use App\Autoloader;
use App\Services\AttendanceCalculationService;
use App\Services\AttendanceOperationException;
use App\Services\AttendancePolicyException;
use App\Services\WorkScheduleResolverService;

require __DIR__ . '/../src/Autoloader.php';

Autoloader::register();

$settings = require __DIR__ . '/../config/database.php';
$limit = 100;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--limit=')) {
        $limit = max(1, min(1000, (int) substr($argument, 8)));
    }
}

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $settings['host'], $settings['name'], $settings['charset']),
    $settings['user'],
    $settings['pass'],
    $settings['options']
);

$resolver = new WorkScheduleResolverService($pdo);
$calculator = new AttendanceCalculationService();

$sql = <<<'SQL'
SELECT
    ar.*,
    u.staff_code,
    u.name AS employee_name,
    u.department_id,
    u.expected_daily_work_hours,
    u.overtime_eligible
FROM attendance_records ar
JOIN users u ON u.id = ar.user_id
ORDER BY ar.attendance_date DESC, ar.id DESC
SQL;

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$issues = [];

foreach ($rows as $row) {
    try {
        $resolution = $resolver->resolve((int) $row['user_id'], (string) $row['attendance_date']);
        $checkInAt = normalize_attendance_datetime($row, 'check_in_at', 'clock_in_time');
        $checkOutAt = normalize_attendance_datetime($row, 'check_out_at', 'clock_out_time');
        if ($checkInAt !== null && $checkOutAt !== null && $checkOutAt < $checkInAt && (bool) ($resolution['is_overnight'] ?? false)) {
            $checkOutAt = (new DateTimeImmutable($checkOutAt))->modify('+1 day')->format('Y-m-d H:i:s');
        }
        $calculation = $calculator->calculate($resolution, [
            'id' => (int) $row['user_id'],
            'expected_daily_work_hours' => $row['expected_daily_work_hours'],
            'overtime_eligible' => $row['overtime_eligible'],
        ], $checkInAt, $checkOutAt);

        $hasTimes = $checkInAt !== null || $checkOutAt !== null;
        $expectedStatus = expected_legacy_status((string) $resolution['day_type'], (string) $calculation['attendance_state']);
        $reasons = [];
        if ($hasTimes && (string) $row['status'] !== $expectedStatus) {
            $reasons[] = 'status_expected_' . $expectedStatus;
        }
        if (!$hasTimes && !is_allowed_no_work_marker((string) $resolution['day_type'], (string) $row['status'])) {
            $reasons[] = 'status_expected_absent';
        }
        if ($hasTimes && (int) $row['late_minutes'] !== (int) $calculation['late_minutes']) {
            $reasons[] = 'late_minutes_expected_' . (int) $calculation['late_minutes'];
        }
        if ((string) $row['status'] === 'present' && $checkInAt === null && $checkOutAt === null) {
            $reasons[] = 'present_without_times';
        }
        if ((string) $row['status'] === 'absent' && ($checkInAt !== null || $checkOutAt !== null)) {
            $reasons[] = 'absent_with_times';
        }
        if (in_array((string) $resolution['day_type'], ['holiday', 'non_working_day'], true) && (int) $row['late_minutes'] > 0) {
            $reasons[] = 'special_day_late_minutes';
        }
        if ($reasons !== []) {
            $issues[] = [$row, implode(',', array_unique($reasons)), $calculation, $resolution];
        }
    } catch (AttendanceOperationException|AttendancePolicyException $exception) {
        $issues[] = [$row, 'policy_resolution_failed_' . $exception->getMessage(), [], []];
    }

    if (count($issues) >= $limit) {
        break;
    }
}

echo "SAPAS attendance consistency dry run\n";
echo "Rows requiring manual review: " . count($issues) . "\n";

foreach ($issues as [$row, $issue, $calculation, $resolution]) {
    printf(
        "#%d %s %s (%s) status=%s in=%s out=%s late=%d issue=%s",
        (int) $row['id'],
        (string) $row['attendance_date'],
        (string) $row['employee_name'],
        (string) $row['staff_code'],
        (string) $row['status'],
        (string) ($row['clock_in_time'] ?? '—'),
        (string) ($row['clock_out_time'] ?? '—'),
        (int) $row['late_minutes'],
        (string) $issue
    );
    if ($calculation !== [] && $resolution !== []) {
        printf(
            " expected_status=%s expected_late=%d day_type=%s overtime=%s",
            expected_legacy_status((string) $resolution['day_type'], (string) $calculation['attendance_state']),
            (int) $calculation['late_minutes'],
            (string) $resolution['day_type'],
            $calculation['calculated_overtime_minutes'] === null ? '—' : (string) (int) $calculation['calculated_overtime_minutes']
        );
    }
    echo "\n";
}

function normalize_attendance_datetime(array $row, string $dateTimeField, string $timeField): ?string
{
    if (!empty($row[$dateTimeField])) {
        return (string) $row[$dateTimeField];
    }
    if (empty($row[$timeField])) {
        return null;
    }

    return (string) $row['attendance_date'] . ' ' . substr((string) $row[$timeField], 0, 8);
}

function expected_legacy_status(string $dayType, string $attendanceState): string
{
    if ($attendanceState === 'absent') {
        return 'absent';
    }

    return match ($dayType) {
        'holiday' => 'holiday',
        'non_working_day' => 'weekend',
        default => 'present',
    };
}

function is_allowed_no_work_marker(string $dayType, string $status): bool
{
    if ($dayType === 'holiday') {
        return in_array($status, ['absent', 'holiday'], true);
    }
    if ($dayType === 'non_working_day') {
        return in_array($status, ['absent', 'weekend'], true);
    }

    return $status === 'absent';
}
