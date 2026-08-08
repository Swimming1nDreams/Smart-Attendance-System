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

$scenarios = [
    'ADMIN' => ['name' => 'Thiri Mon', 'email' => 'thiri.mon@silveroakhr.com', 'old_email' => 'admin@example.com', 'role' => 'admin', 'dept' => 'Human Resources', 'pos' => 'HR Manager', 'staff_code' => 'EMP031'],
    'A' => ['name' => 'Aung Min Khant', 'email' => 'aung.min.khant@silveroakhr.com', 'old_email' => 'emp_a_ontime@example.com', 'role' => 'employee', 'dept' => 'Customer Support', 'pos' => 'Customer Support Representative', 'staff_code' => 'EMP032'],
    'B' => ['name' => 'Su Myat Noe', 'email' => 'su.myat.noe@silveroakhr.com', 'old_email' => 'emp_b_late@example.com', 'role' => 'employee', 'dept' => 'Customer Support', 'pos' => 'Senior Customer Support Representative', 'staff_code' => 'EMP033'],
    'C' => ['name' => 'Thant Zin Oo', 'email' => 'thant.zin.oo@silveroakhr.com', 'old_email' => 'emp_c_absent@example.com', 'role' => 'employee', 'dept' => 'Operations', 'pos' => 'Operations Coordinator', 'staff_code' => 'EMP034'],
    'D' => ['name' => 'May Thu Aung', 'email' => 'may.thu.aung@silveroakhr.com', 'old_email' => 'emp_d_incomplete@example.com', 'role' => 'employee', 'dept' => 'Operations', 'pos' => 'Operations Assistant', 'staff_code' => 'EMP035'],
    'E' => ['name' => 'Htet Naing Win', 'email' => 'htet.naing.win@silveroakhr.com', 'old_email' => 'emp_e_leave@example.com', 'role' => 'employee', 'dept' => 'Information Technology', 'pos' => 'IT Support Specialist', 'staff_code' => 'EMP036'],
    'F' => ['name' => 'Ei Ei Phyu', 'email' => 'ei.ei.phyu@silveroakhr.com', 'old_email' => 'emp_f_livelate@example.com', 'role' => 'employee', 'dept' => 'Customer Support', 'pos' => 'Customer Support Specialist', 'staff_code' => 'EMP037'],
    'G' => ['name' => 'Kaung Htet Zaw', 'email' => 'kaung.htet.zaw@silveroakhr.com', 'old_email' => 'emp_g_liveontime@example.com', 'role' => 'employee', 'dept' => 'Information Technology', 'pos' => 'System Support Officer', 'staff_code' => 'EMP038'],
];

function getOrCreateDepartment(PDO $pdo, string $name): int {
    $stmt = $pdo->prepare("SELECT id FROM departments WHERE name = ?");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id) return (int) $id;
    
    $pdo->prepare("INSERT INTO departments (name) VALUES (?)")->execute([$name]);
    return (int) $pdo->lastInsertId();
}

function getOrCreatePosition(PDO $pdo, string $name): int {
    $stmt = $pdo->prepare("SELECT id FROM positions WHERE name = ?");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id) return (int) $id;
    
    $pdo->prepare("INSERT INTO positions (name) VALUES (?)")->execute([$name]);
    return (int) $pdo->lastInsertId();
}

function getOrCreateUser(PDO $pdo, array $scenario): int {
    $deptId = getOrCreateDepartment($pdo, $scenario['dept']);
    $posId = getOrCreatePosition($pdo, $scenario['pos']);
    
    // Check new email
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$scenario['email']]);
    $id = $stmt->fetchColumn();
    
    if (!$id) {
        // Check old email
        $stmt->execute([$scenario['old_email']]);
        $id = $stmt->fetchColumn();
    }
    
    $password = password_hash('password123', PASSWORD_DEFAULT);
    if ($id) {
        $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, status = 'active', expected_daily_work_hours = 8.00, staff_code = ?, department_id = ?, position_id = ? WHERE id = ?");
        $stmt->execute([$scenario['name'], $scenario['email'], $scenario['role'], $scenario['staff_code'], $deptId, $posId, $id]);
        return (int) $id;
    }
    
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, department_id, position_id, staff_code, status, expected_daily_work_hours, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 8.00, NOW())");
    $stmt->execute([$scenario['name'], $scenario['email'], $password, $scenario['role'], $deptId, $posId, $scenario['staff_code']]);
    
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
    $templateName = "Live Shift";
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
    
    // Live shift is Mon-Sat
    for ($i = 1; $i <= 7; $i++) {
        $isWork = $i <= 6;
        $stmt = $pdo->prepare("INSERT INTO work_schedule_days (work_schedule_template_id, day_of_week, is_working_day, start_time, end_time, expected_minutes, grace_minutes) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$templateId, $i, $isWork ? 1 : 0, $isWork ? $startTime : null, $isWork ? $endTime : null, $isWork ? 540 : 0, $isWork ? 15 : 0]);
    }
    
    foreach ($userIds as $userId) {
        $pdo->prepare("DELETE FROM work_schedule_assignments WHERE scope_type = 'employee' AND scope_id = ? AND effective_from >= ?")->execute([$userId, $todayDateStr]);
        $stmt = $pdo->prepare("INSERT INTO work_schedule_assignments (work_schedule_template_id, scope_type, scope_id, effective_from) VALUES (?, 'employee', ?, ?)");
        $stmt->execute([$templateId, $userId, $todayDateStr]);
    }
    return true;
}

// Users
$adminId = getOrCreateUser($pdo, $scenarios['ADMIN']);
$empA = getOrCreateUser($pdo, $scenarios['A']);
$empB = getOrCreateUser($pdo, $scenarios['B']);
$empC = getOrCreateUser($pdo, $scenarios['C']);
$empD = getOrCreateUser($pdo, $scenarios['D']);
$empE = getOrCreateUser($pdo, $scenarios['E']);
$empF = getOrCreateUser($pdo, $scenarios['F']);
$empG = getOrCreateUser($pdo, $scenarios['G']);

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
assignLiveDemoSchedule($pdo, [$empF, $empG], '2020-01-01', $startTimeStr, $endTimeStr);

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
foreach ($scenarios as $s) {
    echo "{$s['name']}: {$s['email']}\n";
}
