<?php

declare(strict_types=1);

if ((string) getenv('SAPAS_ALLOW_DEMO_SEED') !== '1') {
    fwrite(STDERR, "Workforce seed blocked. Set SAPAS_ALLOW_DEMO_SEED=1 to run this dev-only script.\n");
    exit(1);
}

$root = dirname(__DIR__);
$dbConfig = require $root . '/config/database.php';

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $dbConfig['host'], $dbConfig['name'], $dbConfig['charset']),
    $dbConfig['user'],
    $dbConfig['pass'],
    $dbConfig['options'],
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$passwordHash = null;
$seedDomain = '@' . (getenv('SAPAS_DEMO_EMAIL_DOMAIN') ?: 'silveroakhr.com');
$legacySeedDomain = '@legacy.invalid';
$seedRemarkPrefix = 'Workforce seed';
$legacyRemarkPrefix = 'Demo seed';
$legacyPlaceholderEmails = ['cp@sapas.com', 'kst@sapas.com'];

$departments = [
    ['name' => 'Operations', 'description' => 'Coordinates daily service delivery, workforce scheduling, and operational follow-up.'],
    ['name' => 'Human Resources', 'description' => 'Supports employee records, leave administration, and workplace policies.'],
    ['name' => 'Finance', 'description' => 'Oversees budgeting, expense controls, and financial reporting.'],
    ['name' => 'Information Technology', 'description' => 'Maintains internal systems, access controls, and technical support.'],
    ['name' => 'Customer Support', 'description' => 'Handles customer inquiries, service follow-up, and issue resolution.'],
    ['name' => 'Quality Assurance', 'description' => 'Reviews service quality, process compliance, and continuous improvement.'],
];

$positions = [
    'Operations Lead',
    'HR Officer',
    'Finance Analyst',
    'Systems Administrator',
    'Support Specialist',
    'Attendance Coordinator',
    'Quality Assurance Analyst',
    'Project Coordinator',
];

$employees = [
    ['name' => 'Thiri Aung', 'email' => 'thiri.aung' . $seedDomain, 'legacy_email' => 'maya.chen' . $legacySeedDomain, 'department' => 'Operations', 'position' => 'Operations Lead', 'status' => 'active', 'phone' => '+95 9 450 100 101', 'address' => 'Kamayut Township, Yangon'],
    ['name' => 'Ei Mon Kyaw', 'email' => 'ei.mon.kyaw' . $seedDomain, 'legacy_email' => 'nilar.win' . $legacySeedDomain, 'department' => 'Human Resources', 'position' => 'HR Officer', 'status' => 'active', 'phone' => '+95 9 450 100 103', 'address' => 'Yankin Township, Yangon'],
    ['name' => 'Htet Naing', 'email' => 'htet.naing' . $seedDomain, 'legacy_email' => 'thomas.reed' . $legacySeedDomain, 'department' => 'Finance', 'position' => 'Finance Analyst', 'status' => 'active', 'phone' => '+95 9 450 100 104', 'address' => 'Bahan Township, Yangon'],
    ['name' => 'Su Myat Noe', 'email' => 'su.myat.noe' . $seedDomain, 'legacy_email' => 'khin.htet' . $legacySeedDomain, 'department' => 'Information Technology', 'position' => 'Systems Administrator', 'status' => 'active', 'phone' => '+95 9 450 100 105', 'address' => 'Hlaing Township, Yangon'],
    ['name' => 'Nay Chi Win', 'email' => 'nay.chi.win' . $seedDomain, 'legacy_email' => 'sofia.karim' . $legacySeedDomain, 'department' => 'Customer Support', 'position' => 'Support Specialist', 'status' => 'active', 'phone' => '+95 9 450 100 106', 'address' => 'Tamwe Township, Yangon'],
    ['name' => 'Pyae Sone Aung', 'email' => 'pyae.sone.aung' . $seedDomain, 'legacy_email' => 'ye.lin' . $legacySeedDomain, 'department' => 'Customer Support', 'position' => 'Support Specialist', 'status' => 'active', 'phone' => '+95 9 450 100 107', 'address' => 'Thingangyun Township, Yangon'],
    ['name' => 'Zin Mar Htun', 'email' => 'zin.mar.htun' . $seedDomain, 'legacy_email' => 'grace.tun' . $legacySeedDomain, 'department' => 'Finance', 'position' => 'Finance Analyst', 'status' => 'inactive', 'phone' => '+95 9 450 100 108', 'address' => 'Ahlone Township, Yangon'],
    ['name' => 'Ko Ko Lwin', 'email' => 'ko.ko.lwin' . $seedDomain, 'legacy_email' => null, 'department' => 'Quality Assurance', 'position' => 'Quality Assurance Analyst', 'status' => 'active', 'phone' => '+95 9 450 100 109', 'address' => 'Mingalar Taung Nyunt Township, Yangon'],
    ['name' => 'May Thu Hlaing', 'email' => 'may.thu.hlaing' . $seedDomain, 'legacy_email' => null, 'department' => 'Operations', 'position' => 'Project Coordinator', 'status' => 'active', 'phone' => '+95 9 450 100 110', 'address' => 'Lanmadaw Township, Yangon'],
];

$holidays = require __DIR__ . '/data/holidays_2026.php';

$leaveRequests = [
    ['email' => 'thiri.aung' . $seedDomain, 'leave_type' => 'casual', 'start_date' => '2026-07-07', 'end_date' => '2026-07-07', 'duration_days' => 1, 'reason' => 'Family commitment', 'status' => 'approved', 'review_note' => 'Coverage confirmed.', 'created_at' => '2026-07-03 10:15:00', 'reviewed_at' => '2026-07-03 14:20:00'],
    ['email' => 'su.myat.noe' . $seedDomain, 'leave_type' => 'medical', 'start_date' => '2026-07-16', 'end_date' => '2026-07-17', 'duration_days' => 2, 'reason' => 'Medical appointment and recovery', 'status' => 'pending', 'review_note' => null, 'created_at' => '2026-07-12 09:35:00', 'reviewed_at' => null],
    ['email' => 'nay.chi.win' . $seedDomain, 'leave_type' => 'unpaid', 'start_date' => '2026-07-10', 'end_date' => '2026-07-10', 'duration_days' => 1, 'reason' => 'Personal obligation', 'status' => 'rejected', 'review_note' => 'Requested date overlaps with minimum support coverage.', 'created_at' => '2026-07-06 11:40:00', 'reviewed_at' => '2026-07-07 09:10:00'],
    ['email' => 'pyae.sone.aung' . $seedDomain, 'leave_type' => 'casual', 'start_date' => '2026-07-09', 'end_date' => '2026-07-09', 'duration_days' => 1, 'reason' => 'Personal appointment', 'status' => 'approved', 'review_note' => 'Shift coverage arranged.', 'created_at' => '2026-07-05 15:25:00', 'reviewed_at' => '2026-07-06 08:55:00'],
    ['email' => 'may.thu.hlaing' . $seedDomain, 'leave_type' => 'medical', 'start_date' => '2026-07-24', 'end_date' => '2026-07-24', 'duration_days' => 1, 'reason' => 'Scheduled medical checkup', 'status' => 'pending', 'review_note' => null, 'created_at' => '2026-07-13 08:40:00', 'reviewed_at' => null],
];

$pdo->beginTransaction();

try {
    removeLegacyPlaceholders($pdo, $legacyPlaceholderEmails);
    seedCompanyProfile($pdo, [
        'company_name' => 'Silver Oak HR',
        'short_name' => 'Silver Oak',
        'email' => 'contact@silveroakhr.com',
        'phone' => '+95 1 234 5678',
        'address' => '123 Sule Pagoda Road, Kyauktada Township, Yangon',
    ]);
    $departmentIds = seedDepartments($pdo, $departments);
    $positionIds = seedPositions($pdo, $positions);
    $employeeIds = seedEmployees($pdo, $employees, $departmentIds, $positionIds, $passwordHash);
    removeLegacyMasterData($pdo);
    seedHolidays($pdo, $holidays);
    clearSeededDependentRows($pdo, array_values($employeeIds), [$seedRemarkPrefix, $legacyRemarkPrefix]);
    $adminId = findAdminUserId($pdo);
    seedLeaveRequests($pdo, $leaveRequests, $employeeIds, $adminId);
    $approvedLeaveDates = buildApprovedLeaveDateMap($leaveRequests);
    $holidayDates = array_fill_keys(array_column($holidays, 'date'), true);
    $attendanceRows = seedAttendance($pdo, $employees, $employeeIds, $seedRemarkPrefix, $approvedLeaveDates, $holidayDates);

    $pdo->commit();
} catch (Throwable $exception) {
    $pdo->rollBack();
    fwrite(STDERR, 'Workforce seed failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

printf("Workforce seed complete for database `%s`.\n", (string) $dbConfig['name']);
printf("Employees upserted: %d\n", count($employeeIds));
printf("Attendance rows inserted: %d\n", $attendanceRows);
printSeedSummary($pdo, $seedDomain);
require __DIR__ . '/seed_august_2026_demo.php';
printf("Run `php scripts/refresh-demo-accounts.php` to generate private demonstration credentials.\n");

function seedCompanyProfile(PDO $pdo, array $profile): void
{
    $pdo->prepare('UPDATE company_profiles SET company_name = :company_name, short_name = :short_name, email = :email, phone = :phone, address = :address ORDER BY id ASC LIMIT 1')
        ->execute([
            ':company_name' => $profile['company_name'],
            ':short_name' => $profile['short_name'],
            ':email' => $profile['email'],
            ':phone' => $profile['phone'],
            ':address' => $profile['address'],
        ]);
}

function seedDepartments(PDO $pdo, array $departments): array
{
    $ids = [];
    $select = $pdo->prepare('SELECT id FROM departments WHERE name = :name LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO departments (name, description) VALUES (:name, :description)');
    $update = $pdo->prepare('UPDATE departments SET description = :description WHERE id = :id');

    foreach ($departments as $department) {
        $select->execute([':name' => $department['name']]);
        $id = $select->fetchColumn();

        if ($id === false) {
            $insert->execute([':name' => $department['name'], ':description' => $department['description']]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $id = (int) $id;
            $update->execute([':id' => $id, ':description' => $department['description']]);
        }

        $ids[$department['name']] = $id;
    }

    return $ids;
}

function seedPositions(PDO $pdo, array $positions): array
{
    $ids = [];
    $select = $pdo->prepare('SELECT id FROM positions WHERE name = :name LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO positions (name) VALUES (:name)');

    foreach ($positions as $position) {
        $select->execute([':name' => $position]);
        $id = $select->fetchColumn();

        if ($id === false) {
            $insert->execute([':name' => $position]);
            $id = (int) $pdo->lastInsertId();
        }

        $ids[$position] = (int) $id;
    }

    return $ids;
}

function seedEmployees(PDO $pdo, array $employees, array $departmentIds, array $positionIds, ?string $passwordHash): array
{
    $ids = [];
    $select = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $insert = $pdo->prepare(
        'INSERT INTO users (name, email, password, role, department_id, position_id, status, staff_code, email_verified_at) VALUES (:name, :email, :password, "employee", :department_id, :position_id, :status, :staff_code, NOW())'
    );
    $update = $pdo->prepare(
        'UPDATE users SET name = :name, email = :email, password = :password, role = "employee", department_id = :department_id, position_id = :position_id, status = :status, staff_code = IF(staff_code = "", :staff_code, staff_code), email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = :id'
    );
    $profile = $pdo->prepare(
        'INSERT INTO employee_profiles (user_id, phone, address) VALUES (:user_id, :phone, :address) ON DUPLICATE KEY UPDATE phone = VALUES(phone), address = VALUES(address)'
    );

    $staffCodeCounter = 1000;
    foreach ($employees as $employee) {
        $staffCodeCounter++;
        $passwordHash = password_hash('Seed!Aq7' . rtrim(strtr(base64_encode(random_bytes(15)), '+/', 'XZ'), '='), PASSWORD_DEFAULT);
        $departmentId = $departmentIds[$employee['department']] ?? null;
        $positionId = $positionIds[$employee['position']] ?? null;

        $select->execute([':email' => $employee['email']]);
        $id = $select->fetchColumn();

        if ($id === false && !empty($employee['legacy_email'])) {
            $select->execute([':email' => $employee['legacy_email']]);
            $id = $select->fetchColumn();
        }

        $params = [
            ':name' => $employee['name'],
            ':email' => $employee['email'],
            ':password' => $passwordHash,
            ':department_id' => $departmentId,
            ':position_id' => $positionId,
            ':status' => $employee['status'],
            ':staff_code' => 'EMP-' . $staffCodeCounter,
        ];

        if ($id === false) {
            $insert->execute($params);
            $id = (int) $pdo->lastInsertId();
        } else {
            $id = (int) $id;
            $params[':id'] = $id;
            $update->execute($params);
        }

        $profile->execute([
            ':user_id' => $id,
            ':phone' => $employee['phone'],
            ':address' => $employee['address'],
        ]);

        $ids[$employee['email']] = $id;
    }

    return $ids;
}

function removeLegacyPlaceholders(PDO $pdo, array $emails): void
{
    if ($emails === []) {
        return;
    }

    $emailPlaceholders = implode(',', array_fill(0, count($emails), '?'));
    $idStatement = $pdo->prepare("SELECT id FROM users WHERE email IN ($emailPlaceholders)");
    $idStatement->execute(array_values($emails));
    $userIds = array_map('intval', $idStatement->fetchAll(PDO::FETCH_COLUMN));

    if ($userIds === []) {
        return;
    }

    $userPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
    $pdo->prepare("DELETE FROM notifications WHERE user_id IN ($userPlaceholders) OR related_user_id IN ($userPlaceholders)")
        ->execute(array_merge($userIds, $userIds));
    $pdo->prepare("DELETE FROM users WHERE id IN ($userPlaceholders)")
        ->execute($userIds);
}

function removeLegacyMasterData(PDO $pdo): void
{
    $pdo->exec(
        "DELETE FROM departments
         WHERE name = 'QA Local Department'
           AND description LIKE 'Local manual regression testing department%'
           AND NOT EXISTS (SELECT 1 FROM users WHERE users.department_id = departments.id)"
    );
    $pdo->exec(
        "DELETE FROM holidays
         WHERE (holiday_name = 'Holiday 1' AND description LIKE 'Local manual regression testing holiday%')
            OR (holiday_name = 'Local Holiday' AND description = '17')"
    );
}

function seedHolidays(PDO $pdo, array $holidays): void
{
    $statement = $pdo->prepare(
        'INSERT INTO holidays (holiday_name, holiday_date, description) VALUES (:name, :date, :description) ON DUPLICATE KEY UPDATE holiday_name = VALUES(holiday_name), description = VALUES(description)'
    );

    foreach ($holidays as $holiday) {
        $statement->execute([
            ':name' => $holiday['name'],
            ':date' => $holiday['date'],
            ':description' => $holiday['description'],
        ]);
    }
}

function clearSeededDependentRows(PDO $pdo, array $userIds, array $remarkPrefixes): void
{
    if ($userIds === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $pdo->prepare("DELETE FROM notifications WHERE user_id IN ($placeholders) OR related_user_id IN ($placeholders)")
        ->execute(array_merge($userIds, $userIds));
    $pdo->prepare("DELETE FROM leave_requests WHERE user_id IN ($placeholders)")
        ->execute($userIds);

    $remarkConditions = implode(' OR ', array_fill(0, count($remarkPrefixes), 'remarks LIKE ?'));
    $pdo->prepare("DELETE FROM attendance_records WHERE user_id IN ($placeholders) AND ($remarkConditions)")
        ->execute(array_merge($userIds, array_map(static fn (string $prefix): string => $prefix . '%', $remarkPrefixes)));
}

function seedAttendance(
    PDO $pdo,
    array $employees,
    array $employeeIds,
    string $seedRemarkPrefix,
    array $approvedLeaveDates,
    array $holidayDates
): int
{
    $sql = 'INSERT INTO attendance_records (user_id, attendance_date, clock_in_time, clock_out_time, working_hours, status, late_minutes, remarks) VALUES (:user_id, :attendance_date, :clock_in_time, :clock_out_time, :working_hours, :status, :late_minutes, :remarks)';
    $insert = $pdo->prepare($sql);

    $dates = buildSeedDates('2026-06-01', '2026-07-21');
    $inserted = 0;

    foreach ($employees as $employeeIndex => $employee) {
        $userId = $employeeIds[$employee['email']] ?? null;
        if ($userId === null) {
            continue;
        }

        foreach ($dates as $dateIndex => $date) {
            if (!empty($approvedLeaveDates[$employee['email']][$date])) {
                continue;
            }

            $profile = attendanceProfileFor($date, $employeeIndex, $dateIndex, $holidayDates);
            $insert->execute([
                ':user_id' => $userId,
                ':attendance_date' => $date,
                ':clock_in_time' => $profile['clock_in_time'],
                ':clock_out_time' => $profile['clock_out_time'],
                ':working_hours' => $profile['working_hours'],
                ':status' => $profile['status'],
                ':late_minutes' => $profile['late_minutes'],
                ':remarks' => $seedRemarkPrefix . ' attendance history',
            ]);
            if ($insert->rowCount() > 0) {
                $inserted++;
            }
        }
    }

    return $inserted;
}

function buildSeedDates(string $from, string $to): array
{
    $dates = [];
    $current = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);

    while ($current <= $end) {
        $dates[] = $current->format('Y-m-d');
        $current = $current->modify('+1 day');
    }

    return $dates;
}

function attendanceProfileFor(string $date, int $employeeIndex, int $dateIndex, array $holidayDates): array
{
    $dayOfWeek = (int) (new DateTimeImmutable($date))->format('N');

    if (!empty($holidayDates[$date])) {
        return [
            'status' => 'holiday',
            'clock_in_time' => null,
            'clock_out_time' => null,
            'working_hours' => 0,
            'late_minutes' => 0,
        ];
    }

    if ($dayOfWeek >= 6) {
        return [
            'status' => 'weekend',
            'clock_in_time' => null,
            'clock_out_time' => null,
            'working_hours' => 0,
            'late_minutes' => 0,
        ];
    }

    if ((($dateIndex + $employeeIndex) % 17) === 0) {
        return [
            'status' => 'absent',
            'clock_in_time' => null,
            'clock_out_time' => null,
            'working_hours' => 0,
            'late_minutes' => 0,
        ];
    }

    $latePattern = (($dateIndex + ($employeeIndex * 2)) % 7) === 0;
    $clockIn = $latePattern ? '09:' . str_pad((string) (36 + (($employeeIndex + $dateIndex) % 18)), 2, '0', STR_PAD_LEFT) . ':00' : '08:' . str_pad((string) (48 + (($employeeIndex + $dateIndex) % 10)), 2, '0', STR_PAD_LEFT) . ':00';
    $clockOutHour = 17 + (($employeeIndex + $dateIndex) % 2);
    $clockOutMinute = 5 + (($employeeIndex * 7 + $dateIndex) % 45);
    $clockOut = sprintf('%02d:%02d:00', $clockOutHour, $clockOutMinute);

    return [
        'status' => 'present',
        'clock_in_time' => $clockIn,
        'clock_out_time' => $clockOut,
        'working_hours' => calculateHours($clockIn, $clockOut),
        'late_minutes' => calculateLateMinutes($clockIn),
    ];
}

function calculateLateMinutes(string $clockIn): int
{
    $shift = new DateTimeImmutable('2026-01-01 09:00:00');
    $grace = new DateTimeImmutable('2026-01-01 09:30:00');
    $actual = new DateTimeImmutable('2026-01-01 ' . $clockIn);

    if ($actual <= $grace) {
        return 0;
    }

    return (int) floor(($actual->getTimestamp() - $shift->getTimestamp()) / 60);
}

function calculateHours(string $clockIn, string $clockOut): float
{
    $start = new DateTimeImmutable('2026-01-01 ' . $clockIn);
    $end = new DateTimeImmutable('2026-01-01 ' . $clockOut);

    return round(max(0, ($end->getTimestamp() - $start->getTimestamp()) / 3600), 2);
}

function buildApprovedLeaveDateMap(array $leaveRequests): array
{
    $datesByEmail = [];

    foreach ($leaveRequests as $leaveRequest) {
        if (($leaveRequest['status'] ?? '') !== 'approved') {
            continue;
        }

        $current = new DateTimeImmutable((string) $leaveRequest['start_date']);
        $end = new DateTimeImmutable((string) $leaveRequest['end_date']);

        while ($current <= $end) {
            $datesByEmail[$leaveRequest['email']][$current->format('Y-m-d')] = true;
            $current = $current->modify('+1 day');
        }
    }

    return $datesByEmail;
}

function seedLeaveRequests(PDO $pdo, array $leaveRequests, array $employeeIds, ?int $adminId): void
{
    $statement = $pdo->prepare(
        'INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, duration_days, reason, status, reviewed_by, reviewed_at, review_note, created_at) VALUES (:user_id, :leave_type, :start_date, :end_date, :duration_days, :reason, :status, :reviewed_by, :reviewed_at, :review_note, :created_at)'
    );

    foreach ($leaveRequests as $leaveRequest) {
        $userId = $employeeIds[$leaveRequest['email']] ?? null;
        if ($userId === null) {
            continue;
        }

        $isReviewed = in_array($leaveRequest['status'], ['approved', 'rejected'], true);
        $statement->execute([
            ':user_id' => $userId,
            ':leave_type' => $leaveRequest['leave_type'],
            ':start_date' => $leaveRequest['start_date'],
            ':end_date' => $leaveRequest['end_date'],
            ':duration_days' => $leaveRequest['duration_days'],
            ':reason' => $leaveRequest['reason'],
            ':status' => $leaveRequest['status'],
            ':reviewed_by' => $isReviewed ? $adminId : null,
            ':reviewed_at' => $isReviewed ? $leaveRequest['reviewed_at'] : null,
            ':review_note' => $leaveRequest['review_note'],
            ':created_at' => $leaveRequest['created_at'],
        ]);

        $leaveId = (int) $pdo->lastInsertId();
        seedLeaveNotification(
            $pdo,
            $leaveId,
            $userId,
            $adminId,
            (string) $leaveRequest['status'],
            (string) $leaveRequest['created_at'],
            $leaveRequest['reviewed_at'] ?? null,
        );
    }
}

function seedLeaveNotification(PDO $pdo, int $leaveId, int $employeeId, ?int $adminId, string $status, string $createdAt, ?string $reviewedAt): void
{
    $statement = $pdo->prepare(
        'INSERT INTO notifications (user_id, type, title_key, message_key, link_url, related_user_id, related_leave_request_id, read_at, created_at)
         VALUES (:user_id, :type, :title_key, :message_key, :link_url, :related_user_id, :related_leave_request_id, :read_at, :created_at)'
    );

    if ($status === 'pending' && $adminId !== null) {
        $statement->execute([
            ':user_id' => $adminId,
            ':type' => 'leave_request_submitted',
            ':title_key' => 'notifications.events.leave_request_submitted.title',
            ':message_key' => 'notifications.events.leave_request_submitted.message',
            ':link_url' => '/admin/leave/show?id=' . $leaveId,
            ':related_user_id' => $employeeId,
            ':related_leave_request_id' => $leaveId,
            ':read_at' => null,
            ':created_at' => $createdAt,
        ]);
        return;
    }

    if (!in_array($status, ['approved', 'rejected'], true)) {
        return;
    }

    $event = $status === 'approved' ? 'leave_approved' : 'leave_rejected';
    $statement->execute([
        ':user_id' => $employeeId,
        ':type' => $event,
        ':title_key' => 'notifications.events.' . $event . '.title',
        ':message_key' => 'notifications.events.' . $event . '.message',
        ':link_url' => '/leave/show?id=' . $leaveId,
        ':related_user_id' => null,
        ':related_leave_request_id' => $leaveId,
        ':read_at' => $reviewedAt,
        ':created_at' => $reviewedAt ?? $createdAt,
    ]);
}

function findAdminUserId(PDO $pdo): ?int
{
    $statement = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
    $id = $statement->fetchColumn();

    return $id === false ? null : (int) $id;
}

function printSeedSummary(PDO $pdo, string $seedDomain): void
{
    $employeeStatement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email LIKE :email');
    $employeeStatement->execute([':email' => '%' . $seedDomain]);
    printf("Seeded employees in database: %d\n", (int) $employeeStatement->fetchColumn());

    $attendanceStatement = $pdo->prepare(
        'SELECT ar.status, COUNT(*) AS row_count, ROUND(COALESCE(SUM(ar.working_hours), 0), 2) AS total_hours
         FROM attendance_records ar
         INNER JOIN users u ON u.id = ar.user_id
         WHERE u.email LIKE :email
         GROUP BY ar.status
         ORDER BY ar.status'
    );
    $attendanceStatement->execute([':email' => '%' . $seedDomain]);

    foreach ($attendanceStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        printf(
            "Attendance %s: %d rows, %.2f hours\n",
            (string) $row['status'],
            (int) $row['row_count'],
            (float) $row['total_hours'],
        );
    }

    $leaveStatement = $pdo->prepare(
        'SELECT lr.status, COUNT(*) AS row_count
         FROM leave_requests lr
         INNER JOIN users u ON u.id = lr.user_id
         WHERE u.email LIKE :email
         GROUP BY lr.status
         ORDER BY lr.status'
    );
    $leaveStatement->execute([':email' => '%' . $seedDomain]);

    foreach ($leaveStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        printf("Leave %s: %d requests\n", (string) $row['status'], (int) $row['row_count']);
    }
}
