<?php

declare(strict_types=1);

use App\Autoloader;
use App\Config\Database;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/src/Autoloader.php';
Autoloader::register();

$settings = require $root . '/config/database.php';
$config = require $root . '/config/config.php';

$appTimezone = (string) ($config['app']['timezone'] ?? 'Asia/Yangon');
date_default_timezone_set($appTimezone);

$pdo = (new Database($settings))->getConnection();

function getOrCreateDepartment(PDO $pdo): int {
    $stmt = $pdo->query("SELECT id FROM departments LIMIT 1");
    $id = $stmt->fetchColumn();
    if ($id) return (int) $id;
    
    $pdo->exec("INSERT INTO departments (name, description) VALUES ('Demo Department', 'Demo Dept')");
    return (int) $pdo->lastInsertId();
}

function getOrCreateUser(PDO $pdo, string $name, string $email, string $role, int $departmentId, string $staffCode): int {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $id = $stmt->fetchColumn();
    
    $password = password_hash('password123', PASSWORD_DEFAULT);
    if ($id) {
        $stmt = $pdo->prepare("UPDATE users SET name = ?, role = ?, status = 'active', expected_daily_work_hours = 8.00, staff_code = ? WHERE id = ?");
        $stmt->execute([$name, $role, $staffCode, $id]);
        return (int) $id;
    }
    
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, department_id, staff_code, status, expected_daily_work_hours, created_at) VALUES (?, ?, ?, ?, ?, ?, 'active', 8.00, NOW())");
    $stmt->execute([$name, $email, $password, $role, $departmentId, $staffCode]);
    
    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO employee_profiles (user_id) VALUES (?)")->execute([$userId]);
    
    return $userId;
}

function createAttendance(PDO $pdo, int $userId, string $date, ?string $clockIn, ?string $clockOut, string $status = 'present'): void {
    $stmt = $pdo->prepare("SELECT id FROM attendance_records WHERE user_id = ? AND attendance_date = ?");
    $stmt->execute([$userId, $date]);
    if ($stmt->fetchColumn()) {
        $pdo->prepare("DELETE FROM attendance_records WHERE user_id = ? AND attendance_date = ?")->execute([$userId, $date]);
    }

    $resolver = new \App\Services\WorkScheduleResolverService($pdo);
    $calculator = new \App\Services\AttendanceCalculationService();
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    $resolution = $resolver->resolve($userId, $date);
    
    $clockInAt = $clockIn ? "$date $clockIn" : null;
    $clockOutAt = $clockOut ? "$date $clockOut" : null;
    
    $calc = $calculator->calculate($resolution, $employee, $clockInAt, $clockOutAt);

    $stmt = $pdo->prepare("
        INSERT INTO attendance_records (
            user_id, attendance_date, clock_in_time, clock_out_time, check_in_at, check_out_at,
            status, attendance_state, is_late, late_minutes, is_missing_check_out,
            resolved_assignment_id, resolved_policy_version_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId, $date, $clockIn, $clockOut, $clockInAt, $clockOutAt,
        $status, $calc['attendance_state'], $calc['is_late'], $calc['late_minutes'], $calc['is_missing_check_out'],
        $resolution['assignment_id'] ?? null, $resolution['policy_version_id'] ?? null
    ]);
}

function createLeaveRequest(PDO $pdo, int $userId, string $date): void {
    $stmt = $pdo->prepare("SELECT id FROM leave_requests WHERE user_id = ? AND start_date = ?");
    $stmt->execute([$userId, $date]);
    if ($stmt->fetchColumn()) {
        return;
    }
    
    $stmt = $pdo->prepare("INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, duration_days, reason, status) VALUES (?, 'casual', ?, ?, 1, 'Demo Leave', 'approved')");
    $stmt->execute([$userId, $date, $date]);
}

function assignLiveDemoSchedule(PDO $pdo, array $userIds, string $todayDateStr, string $startTime, string $endTime): bool {
    $templateName = "Demo Live Shift";
    $stmt = $pdo->prepare("SELECT id FROM work_schedule_templates WHERE name = ?");
    $stmt->execute([$templateName]);
    $templateId = $stmt->fetchColumn();
    
    if (!$templateId) {
        $stmt = $pdo->prepare("INSERT INTO work_schedule_templates (name, description, effective_from) VALUES (?, 'Demo Live schedule', '2020-01-01')");
        $stmt->execute([$templateName]);
        $templateId = $pdo->lastInsertId();
    } else {
        $pdo->prepare("DELETE FROM work_schedule_days WHERE work_schedule_template_id = ?")->execute([$templateId]);
    }
    
    for ($i = 1; $i <= 7; $i++) {
        $stmt = $pdo->prepare("INSERT INTO work_schedule_days (work_schedule_template_id, day_of_week, is_working_day, start_time, end_time, expected_minutes, grace_minutes) VALUES (?, ?, 1, ?, ?, 480, 15)");
        $stmt->execute([$templateId, $i, $startTime, $endTime]);
    }
    
    foreach ($userIds as $userId) {
        $pdo->prepare("DELETE FROM work_schedule_assignments WHERE scope_type = 'employee' AND scope_id = ? AND effective_from >= ?")->execute([$userId, $todayDateStr]);
        $stmt = $pdo->prepare("INSERT INTO work_schedule_assignments (work_schedule_template_id, scope_type, scope_id, effective_from) VALUES (?, 'employee', ?, ?)");
        $stmt->execute([$templateId, $userId, $todayDateStr]);
    }
    return true;
}

// Ensure base dependencies exist
$departmentId = getOrCreateDepartment($pdo);

// Admin Account
$adminId = getOrCreateUser($pdo, 'Admin Demo', 'admin@example.com', 'admin', $departmentId, 'ADM-001');

// Demo Employees
$empA = getOrCreateUser($pdo, 'A. On-time Employee', 'emp_a_ontime@example.com', 'employee', $departmentId, 'EMP-A');
$empB = getOrCreateUser($pdo, 'B. Late Employee', 'emp_b_late@example.com', 'employee', $departmentId, 'EMP-B');
$empC = getOrCreateUser($pdo, 'C. Absent Employee', 'emp_c_absent@example.com', 'employee', $departmentId, 'EMP-C');
$empD = getOrCreateUser($pdo, 'D. Incomplete Employee', 'emp_d_incomplete@example.com', 'employee', $departmentId, 'EMP-D');
$empE = getOrCreateUser($pdo, 'E. Leave Employee', 'emp_e_leave@example.com', 'employee', $departmentId, 'EMP-E');
$empF = getOrCreateUser($pdo, 'F. Live Late Employee', 'emp_f_livelate@example.com', 'employee', $departmentId, 'EMP-F');
$empG = getOrCreateUser($pdo, 'G. Live Checked-In Employee', 'emp_g_liveontime@example.com', 'employee', $departmentId, 'EMP-G');

// Calculate working days for the month up to yesterday
$now = new DateTimeImmutable('now');
$monthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
$yesterday = $now->modify('-1 day')->setTime(0, 0, 0);

$pastWorkingDays = [];
$currentDate = $monthStart;
while ($currentDate <= $yesterday) {
    if ((int) $currentDate->format('N') < 6) { // Mon-Fri
        $pastWorkingDays[] = $currentDate->format('Y-m-d');
    }
    $currentDate = $currentDate->modify('+1 day');
}

$todayDateStr = $now->format('Y-m-d');
$todayDayOfWeek = (int) $now->format('N');

// If there are no past working days (e.g. today is 1st of month), just use a fixed date from last month
if (empty($pastWorkingDays)) {
    $fallbackDate = $now->modify('-2 days');
    if ((int)$fallbackDate->format('N') >= 6) $fallbackDate = $fallbackDate->modify('previous friday');
    $pastWorkingDays[] = $fallbackDate->format('Y-m-d');
}

// Calculate dynamic live shift times for Emp F and Emp G
$startDateTime = $now->modify('-2 hours');
if ($startDateTime->format('Y-m-d') !== $todayDateStr) {
    $startDateTime = $now->setTime(0, 0, 0);
}
$endDateTime = $now->modify('+6 hours');
if ($endDateTime->format('Y-m-d') !== $todayDateStr) {
    $endDateTime = $now->setTime(23, 59, 59);
}
$startTimeStr = $startDateTime->format('H:i:s');
$endTimeStr = $endDateTime->format('H:i:s');

$empGClockIn = $startDateTime->modify('+5 minutes')->format('H:i:s');
$empFClockIn = $startDateTime->modify('+30 minutes')->format('H:i:s');

// Assign Live Demo Schedule to F and G
assignLiveDemoSchedule($pdo, [$empF, $empG], $todayDateStr, $startTimeStr, $endTimeStr);

// ----------------------------------------------------------------------------
// A. 100% On-time Employee
// ----------------------------------------------------------------------------
foreach ($pastWorkingDays as $date) {
    createAttendance($pdo, $empA, $date, '08:50:00', '17:10:00');
}

// ----------------------------------------------------------------------------
// B. 100% With Late Employee (at least one late check-in)
// ----------------------------------------------------------------------------
foreach ($pastWorkingDays as $i => $date) {
    if ($i === 0) {
        // Late by 4 hr 7 min (assuming 09:00 start, arriving at 13:07)
        createAttendance($pdo, $empB, $date, '13:07:00', '18:00:00');
    } else {
        createAttendance($pdo, $empB, $date, '08:55:00', '17:05:00');
    }
}

// ----------------------------------------------------------------------------
// C. Derived Absent Employee (missing at least one day)
// ----------------------------------------------------------------------------
foreach ($pastWorkingDays as $i => $date) {
    if ($i !== count($pastWorkingDays) - 1) { // Skip the last working day
        createAttendance($pdo, $empC, $date, '08:55:00', '17:05:00');
    }
}

// ----------------------------------------------------------------------------
// D. Incomplete Employee (missing checkout on one day)
// ----------------------------------------------------------------------------
foreach ($pastWorkingDays as $i => $date) {
    if ($i === 0) {
        createAttendance($pdo, $empD, $date, '08:55:00', null); // no checkout
    } else {
        createAttendance($pdo, $empD, $date, '08:55:00', '17:05:00');
    }
}

// ----------------------------------------------------------------------------
// E. Approved Leave Employee
// ----------------------------------------------------------------------------
foreach ($pastWorkingDays as $i => $date) {
    if ($i === 0) {
        createLeaveRequest($pdo, $empE, $date); // Approved Leave
    } else {
        createAttendance($pdo, $empE, $date, '08:55:00', '17:05:00');
    }
}

// ----------------------------------------------------------------------------
// F. Live Late Employee
// ----------------------------------------------------------------------------
foreach ($pastWorkingDays as $date) {
    createAttendance($pdo, $empF, $date, '08:55:00', '17:05:00'); // On time previously
}
createAttendance($pdo, $empF, $todayDateStr, $empFClockIn, null); // Late check-in today

// ----------------------------------------------------------------------------
// G. Live Checked-In Employee
// ----------------------------------------------------------------------------
foreach ($pastWorkingDays as $date) {
    createAttendance($pdo, $empG, $date, '08:55:00', '17:05:00'); // On time previously
}
createAttendance($pdo, $empG, $todayDateStr, $empGClockIn, null); // On-time check-in today, no checkout yet

echo "Demo Seed Completed Successfully!\n\n";
echo "Demo Dates Used:\n";
echo "- Past Working Days: " . implode(', ', $pastWorkingDays) . "\n";
echo "- Live Today Date: " . $todayDateStr . " (Day of Week: " . $todayDayOfWeek . ")\n";
echo "- Was Today Made Working via Demo Policy? Yes\n\n";
echo "Login Credentials (All use password: password123)\n";
echo "--------------------------------------------------\n";
echo "Admin: admin@example.com\n";
echo "Emp A (100% On-time): emp_a_ontime@example.com\n";
echo "Emp B (100% With Late): emp_b_late@example.com\n";
echo "Emp C (Derived Absent): emp_c_absent@example.com\n";
echo "Emp D (Incomplete): emp_d_incomplete@example.com\n";
echo "Emp E (Approved Leave): emp_e_leave@example.com\n";
echo "Emp F (Live Late): emp_f_livelate@example.com\n";
echo "Emp G (Live Checked-In): emp_g_liveontime@example.com\n";

