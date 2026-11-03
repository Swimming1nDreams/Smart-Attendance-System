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

function getOrCreateDepartment(PDO $pdo, string $name): int {
    $stmt = $pdo->prepare("SELECT id FROM departments WHERE name = ?");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }

    $stmt = $pdo->prepare("INSERT INTO departments (name, description) VALUES (?, ?)");
    $stmt->execute([$name, $name . ' department']);
    return (int) $pdo->lastInsertId();
}

function getOrCreatePosition(PDO $pdo, string $name): int {
    $stmt = $pdo->prepare("SELECT id FROM positions WHERE name = ?");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }

    $stmt = $pdo->prepare("INSERT INTO positions (name) VALUES (?)");
    $stmt->execute([$name]);
    return (int) $pdo->lastInsertId();
}

function getOrCreateUser(
    PDO $pdo,
    string $name,
    string $email,
    string $role,
    int $departmentId,
    ?int $positionId,
    string $staffCode,
    ?string $legacyEmail = null,
    ?string $legacyStaffCode = null,
    ?int $authoritativeLegacyId = null
): int {
    $emails = array_values(array_unique(array_filter([$email, $legacyEmail])));
    $staffCodes = array_values(array_unique(array_filter([$staffCode, $legacyStaffCode])));
    $emailPlaceholders = implode(', ', array_fill(0, count($emails), '?'));
    $staffCodePlaceholders = implode(', ', array_fill(0, count($staffCodes), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, name, email, role, staff_code
         FROM users
         WHERE email IN ({$emailPlaceholders}) OR staff_code IN ({$staffCodePlaceholders})
         FOR UPDATE"
    );
    $stmt->execute([...$emails, ...$staffCodes]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $candidateIds = array_values(array_unique(array_map('intval', array_column($candidates, 'id'))));

    if (count($candidateIds) > 1) {
        throw new RuntimeException("Identity collision detected for {$name}; no user was changed.");
    }

    $password = password_hash('password123', PASSWORD_DEFAULT);
    if ($candidates !== []) {
        $candidate = $candidates[0];
        $id = (int) $candidate['id'];
        $matchesLegacyIdentity = (
            $legacyEmail !== null
            && strcasecmp((string) $candidate['email'], $legacyEmail) === 0
        ) || (
            $legacyStaffCode !== null
            && strcasecmp((string) $candidate['staff_code'], $legacyStaffCode) === 0
        );

        if ($matchesLegacyIdentity && $authoritativeLegacyId !== null && $id !== $authoritativeLegacyId) {
            throw new RuntimeException(
                "Legacy identity for {$name} resolved to user #{$id}; expected authoritative user #{$authoritativeLegacyId}."
            );
        }

        $matchesTargetIdentity = strcasecmp((string) $candidate['email'], $email) === 0
            && strcasecmp((string) $candidate['staff_code'], $staffCode) === 0
            && (string) $candidate['name'] === $name
            && (string) $candidate['role'] === $role;
        $matchesAuthoritativeTarget = $authoritativeLegacyId !== null
            && $id === $authoritativeLegacyId
            && strcasecmp((string) $candidate['email'], $email) === 0;
        if (!$matchesLegacyIdentity && !$matchesTargetIdentity && !$matchesAuthoritativeTarget) {
            throw new RuntimeException("Target identity collision detected for {$name}; no user was changed.");
        }

        $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, department_id = ?, position_id = ?, status = 'active', expected_daily_work_hours = 8.00, staff_code = ? WHERE id = ?");
        $stmt->execute([$name, $email, $role, $departmentId, $positionId, $staffCode, $id]);
        $pdo->prepare("INSERT INTO employee_profiles (user_id) SELECT ? WHERE NOT EXISTS (SELECT 1 FROM employee_profiles WHERE user_id = ?)")
            ->execute([$id, $id]);
        return (int) $id;
    }

    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, department_id, position_id, staff_code, status, expected_daily_work_hours, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 8.00, NOW())");
    $stmt->execute([$name, $email, $password, $role, $departmentId, $positionId, $staffCode]);

    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO employee_profiles (user_id) VALUES (?)")->execute([$userId]);

    return $userId;
}

function createAttendance(PDO $pdo, int $userId, string $date, ?string $clockIn, ?string $clockOut, string $status = 'present'): void {
    $now = new DateTimeImmutable('now');
    $todayDate = $now->format('Y-m-d');
    $currentTime = $now->format('H:i:s');

    // Presentation Data Correction: Never create impossible future data.
    if ($date === $todayDate) {
        if ($clockIn && $clockIn > $currentTime) {
            return; // Future check-in means shift hasn't started yet.
        }
        if ($clockOut && $clockOut > $currentTime) {
            $clockOut = null; // Future checkout means shift is in progress.
        }
    }

    $stmt = $pdo->prepare("SELECT id FROM attendance_records WHERE user_id = ? AND attendance_date = ? FOR UPDATE");
    $stmt->execute([$userId, $date]);
    $attendanceId = $stmt->fetchColumn();

    // Existing attendance is authoritative history. Seed reruns may fill missing
    // scenario dates, but must not rewrite timestamps or dependent row state.
    if ($attendanceId) {
        return;
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

function createLeaveRequest(PDO $pdo, int $userId, string $date, string $status = 'approved'): void {
    $stmt = $pdo->prepare("SELECT id FROM leave_requests WHERE user_id = ? AND start_date = ?");
    $stmt->execute([$userId, $date]);
    if ($stmt->fetchColumn()) {
        return;
    }
    
    $stmt = $pdo->prepare("INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, duration_days, reason, status) VALUES (?, 'casual', ?, ?, 1, 'Demo Leave', ?)");
    $stmt->execute([$userId, $date, $date, $status]);
}

function assignLiveDemoSchedule(PDO $pdo, array $userIds, string $todayDateStr, string $startTime, string $endTime): bool {
    $templateName = "Flexible Support Shift";
    $legacyTemplateName = "Demo Live Shift";
    $templateDescription = 'Flexible schedule for live support coverage';
    $stmt = $pdo->prepare(
        "SELECT id, name
         FROM work_schedule_templates
         WHERE is_active = 1 AND name IN (?, ?)
         ORDER BY id
         FOR UPDATE"
    );
    $stmt->execute([$templateName, $legacyTemplateName]);
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($templates) > 1) {
        throw new RuntimeException('Live schedule template collision detected; no schedule was changed.');
    }

    $templateId = isset($templates[0]['id']) ? (int) $templates[0]['id'] : null;
    
    if (!$templateId) {
        $stmt = $pdo->prepare("INSERT INTO work_schedule_templates (name, description, effective_from) VALUES (?, ?, '2020-01-01')");
        $stmt->execute([$templateName, $templateDescription]);
        $templateId = (int) $pdo->lastInsertId();
    } else {
        $pdo->prepare("UPDATE work_schedule_templates SET name = ?, description = ? WHERE id = ?")
            ->execute([$templateName, $templateDescription, $templateId]);
    }
    
    for ($i = 1; $i <= 7; $i++) {
        $stmt = $pdo->prepare(
            "SELECT id FROM work_schedule_days WHERE work_schedule_template_id = ? AND day_of_week = ? FOR UPDATE"
        );
        $stmt->execute([$templateId, $i]);
        $scheduleDayId = $stmt->fetchColumn();

        if ($scheduleDayId) {
            $pdo->prepare(
                "UPDATE work_schedule_days
                 SET is_working_day = 1, start_time = ?, end_time = ?, is_overnight = 0,
                     break_minutes = 0, expected_minutes = 480, grace_minutes = 15
                 WHERE id = ?"
            )->execute([$startTime, $endTime, (int) $scheduleDayId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO work_schedule_days (work_schedule_template_id, day_of_week, is_working_day, start_time, end_time, expected_minutes, grace_minutes) VALUES (?, ?, 1, ?, ?, 480, 15)");
            $stmt->execute([$templateId, $i, $startTime, $endTime]);
        }
    }
    
    foreach ($userIds as $userId) {
        $stmt = $pdo->prepare(
            "SELECT id, work_schedule_template_id
             FROM work_schedule_assignments
             WHERE scope_type = 'employee'
               AND scope_id = ?
               AND is_active = 1
               AND effective_from <= ?
               AND (effective_to IS NULL OR effective_to >= ?)
             ORDER BY effective_from DESC, id DESC
             FOR UPDATE"
        );
        $stmt->execute([$userId, $todayDateStr, $todayDateStr]);
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($assignments) > 1) {
            throw new RuntimeException("Live schedule assignment collision detected for user #{$userId}; no schedule was changed.");
        }

        if ($assignments !== []) {
            if ((int) $assignments[0]['work_schedule_template_id'] !== $templateId) {
                throw new RuntimeException(
                    "User #{$userId} already has a different effective employee schedule; no assignment was changed."
                );
            }
        } else {
            $stmt = $pdo->prepare("INSERT INTO work_schedule_assignments (work_schedule_template_id, scope_type, scope_id, effective_from) VALUES (?, 'employee', ?, ?)");
            $stmt->execute([$templateId, $userId, $todayDateStr]);
        }
    }
    return true;
}

try {
    $pdo->beginTransaction();

    $customerSupportId = getOrCreateDepartment($pdo, 'Customer Support');
    $humanResourcesId = getOrCreateDepartment($pdo, 'Human Resources');
    $operationsId = getOrCreateDepartment($pdo, 'Operations');
    $informationTechnologyId = getOrCreateDepartment($pdo, 'Information Technology');

    $hrExecutiveId = getOrCreatePosition($pdo, 'HR Executive');
    $seniorSupportId = getOrCreatePosition($pdo, 'Senior Customer Support Representative');
    $operationsCoordinatorId = getOrCreatePosition($pdo, 'Operations Coordinator');
    $itSupportId = getOrCreatePosition($pdo, 'IT Support Specialist');
    $operationsAssistantId = getOrCreatePosition($pdo, 'Operations Assistant');
    $supportSpecialistId = getOrCreatePosition($pdo, 'Customer Support Specialist');
    $systemSupportId = getOrCreatePosition($pdo, 'System Support Officer');

    // Admin Account
    $adminId = getOrCreateUser($pdo, 'Aye Chan Moe', 'aye.chan.moe@silveroakhr.com', 'admin', $informationTechnologyId, $systemSupportId, '000031', 'admin@example.com', 'ADM-001', 31);

    // Presentation employees. Legacy identity fields make reruns update IDs 32-38 instead of recreating users.
    $empA = getOrCreateUser($pdo, 'Thiri Mon Aung', 'thiri.mon.aung@silveroakhr.com', 'employee', $humanResourcesId, $hrExecutiveId, '000032', 'emp_a_ontime@example.com', 'EMP-A', 32);
    $empB = getOrCreateUser($pdo, 'Su Myat Nandar', 'su.myat.nandar@silveroakhr.com', 'employee', $customerSupportId, $seniorSupportId, '000033', 'emp_b_late@example.com', 'EMP-B', 33);
    $empC = getOrCreateUser($pdo, 'Thant Zin Oo', 'thant.zin.oo@silveroakhr.com', 'employee', $operationsId, $operationsCoordinatorId, '000034', 'emp_c_absent@example.com', 'EMP-C', 34);
    $empD = getOrCreateUser($pdo, 'Htet Naing Win', 'htet.naing.win@silveroakhr.com', 'employee', $informationTechnologyId, $itSupportId, '000035', 'emp_d_incomplete@example.com', 'EMP-D', 35);
    $empE = getOrCreateUser($pdo, 'May Thu Aung', 'may.thu.aung@silveroakhr.com', 'employee', $operationsId, $operationsAssistantId, '000036', 'emp_e_leave@example.com', 'EMP-E', 36);
    $empF = getOrCreateUser($pdo, 'Ei Ei Phyu', 'ei.ei.phyu@silveroakhr.com', 'employee', $customerSupportId, $supportSpecialistId, '000037', 'emp_f_livelate@example.com', 'EMP-F', 37);
    $empG = getOrCreateUser($pdo, 'Kaung Htet Zaw', 'kaung.htet.zaw@silveroakhr.com', 'employee', $informationTechnologyId, $systemSupportId, '000038', 'emp_g_liveontime@example.com', 'EMP-G', 38);

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

// Include today in the general loop so people aren't marked as absent today
$allDemoDays = $pastWorkingDays;
if (!in_array($todayDateStr, $allDemoDays)) {
    $allDemoDays[] = $todayDateStr;
}

// ----------------------------------------------------------------------------
// A. Thiri Mon Aung - historical 100% on-time scenario
// ----------------------------------------------------------------------------
foreach ($allDemoDays as $date) {
    createAttendance($pdo, $empA, $date, '08:50:00', '17:10:00');
}

// ----------------------------------------------------------------------------
// B. Su Myat Nandar - 100% attendance with at least one late check-in
// ----------------------------------------------------------------------------
foreach ($allDemoDays as $i => $date) {
    if ($i === 0) {
        // Late by 4 hr 7 min (assuming 09:00 start, arriving at 13:07)
        createAttendance($pdo, $empB, $date, '13:07:00', '18:00:00');
    } else {
        createAttendance($pdo, $empB, $date, '08:55:00', '17:05:00');
    }
}

// ----------------------------------------------------------------------------
// C. Thant Zin Oo - derived absence on at least one day
// ----------------------------------------------------------------------------
foreach ($allDemoDays as $i => $date) {
    if ($i !== 8) { // Skip a past working day (e.g. index 8) instead of today!
        createAttendance($pdo, $empC, $date, '08:55:00', '17:05:00');
    }
}

// ----------------------------------------------------------------------------
// D. Htet Naing Win - incomplete attendance with one missing checkout
// ----------------------------------------------------------------------------
foreach ($allDemoDays as $i => $date) {
    if ($i === 0) {
        createAttendance($pdo, $empD, $date, '08:55:00', null); // no checkout
    } else {
        createAttendance($pdo, $empD, $date, '08:55:00', '17:05:00');
    }
}

// ----------------------------------------------------------------------------
// E. May Thu Aung - approved leave scenario
// ----------------------------------------------------------------------------
foreach ($allDemoDays as $i => $date) {
    if ($i === 0) {
        createLeaveRequest($pdo, $empE, $date); // Approved Leave
    } else {
        createAttendance($pdo, $empE, $date, '08:55:00', '17:05:00');
    }
}

// ----------------------------------------------------------------------------
// F. Ei Ei Phyu - current live late scenario
foreach ($pastWorkingDays as $i => $date) {
    if ($i !== 2) { // Miss one day
        createAttendance($pdo, $empF, $date, '08:55:00', '17:05:00'); // On time previously
    }
}
createAttendance($pdo, $empF, $todayDateStr, $empFClockIn, null); // Late check-in today

// ----------------------------------------------------------------------------
// G. Kaung Htet Zaw - current live on-time checked-in scenario
// ----------------------------------------------------------------------------
foreach ($pastWorkingDays as $i => $date) {
    if ($i !== 3) { // Miss one day
        createAttendance($pdo, $empG, $date, '08:55:00', '17:05:00'); // On time previously
    }
}
createAttendance($pdo, $empG, $todayDateStr, $empGClockIn, null); // On-time check-in today, no checkout yet

// ----------------------------------------------------------------------------
// H. Generate Realistic Attendance for All Other Active Employees
// ----------------------------------------------------------------------------
// H. Generate Realistic Attendance for All Other Active Employees
// ----------------------------------------------------------------------------
$stmt = $pdo->query("SELECT id FROM users WHERE status = 'active' AND role = 'employee'");
$allActiveUserIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

// The specifically handled employees
$handledIds = [$adminId, $empA, $empB, $empC, $empD, $empE, $empF, $empG];
$otherUserIds = array_diff($allActiveUserIds, $handledIds);

// Sort array to ensure deterministic slicing
sort($otherUserIds);

// Pick 0 extra candidates for 100% (Emp A and Emp B are already 100%)
$hundredCandidates = array_slice($otherUserIds, 0, 0);

// Pick exactly 3 candidates for Review (under 90%)
$reviewCandidates = array_slice($otherUserIds, count($hundredCandidates), min(3, count($otherUserIds) - count($hundredCandidates)));

foreach ($otherUserIds as $uId) {
    $isHundred = in_array($uId, $hundredCandidates);
    $isReview = in_array($uId, $reviewCandidates);
    
    foreach ($allDemoDays as $date) {
        // Deterministic pseudo-random based on user ID and date string
        $hash = crc32((string)$uId . $date);
        $rand = ($hash % 100) + 1; // 1 to 100
        
        // Handle "Today" differently so we don't insert future checkouts
        $isToday = ($date === $todayDateStr);
        $checkout = $isToday ? null : '17:05:00';
        $checkoutLate = $isToday ? null : '17:15:00';
        
        if ($isHundred) {
            // 100% Present, on time
            createAttendance($pdo, (int)$uId, $date, '08:50:00', $checkout);
        } elseif ($isReview) {
            // Review Candidate: ~75% Present, 15% Absent, 10% Late
            if ($rand <= 75) {
                createAttendance($pdo, (int)$uId, $date, '08:50:00', $checkout);
            } elseif ($rand <= 90) {
                // Absent: Do nothing, it will be a derived absence
            } else {
                createAttendance($pdo, (int)$uId, $date, '09:45:00', $checkoutLate); // Late
            }
        } else {
            // Healthy Employee (85-99%): Force exactly 1 absence on a PAST day based on uId so they are never 100%.
            // Additionally, add some minor random variation for lates/leaves.
            $dateIndex = array_search($date, $allDemoDays);
            // $allDemoDays is 11 days (10 past + 1 today). We want exception index to be 0 to 9.
            $pastDaysCount = max(1, count($allDemoDays) - 1);
            $absentIndex = $uId % $pastDaysCount;
            
            if ($dateIndex === $absentIndex) {
                // Force Absent
                continue;
            }
            
            // For other days, just a tiny chance of late or leave to add variety
            $mod = ($uId + $dateIndex) % 20;
            if ($mod === 0) {
                createAttendance($pdo, (int)$uId, $date, '09:20:00', $checkout); // Late
            } elseif ($mod === 1 && $dateIndex % 3 === 0) {
                createLeaveRequest($pdo, (int)$uId, $date, 'approved'); // Leave
            } else {
                createAttendance($pdo, (int)$uId, $date, '08:55:00', $checkout);
            }
        }
    }
}

$pdo->commit();echo "Demo Seed Completed Successfully!\n\n";
echo "Demo Dates Used:\n";
echo "- Past Working Days: " . implode(', ', $pastWorkingDays) . "\n";
echo "- Live Today Date: " . $todayDateStr . " (Day of Week: " . $todayDayOfWeek . ")\n";
echo "- Was Today Made Working via Demo Policy? Yes\n\n";
echo "Login Credentials (All use password: password123)\n";
echo "--------------------------------------------------\n";
echo "Administrator: aye.chan.moe@silveroakhr.com\n";
echo "Thiri Mon Aung (100% On-time): thiri.mon.aung@silveroakhr.com\n";
echo "Su Myat Nandar (100% With Late): su.myat.nandar@silveroakhr.com\n";
echo "Thant Zin Oo (Derived Absent): thant.zin.oo@silveroakhr.com\n";
echo "Htet Naing Win (Incomplete): htet.naing.win@silveroakhr.com\n";
echo "May Thu Aung (Approved Leave): may.thu.aung@silveroakhr.com\n";
echo "Ei Ei Phyu (Live Late): ei.ei.phyu@silveroakhr.com\n";
echo "Kaung Htet Zaw (Live Checked-In): kaung.htet.zaw@silveroakhr.com\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "Demo seed failed: {$exception->getMessage()}\n");
    exit(1);
}

