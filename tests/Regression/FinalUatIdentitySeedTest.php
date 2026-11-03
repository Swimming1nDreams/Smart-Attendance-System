<?php

declare(strict_types=1);

test('final UAT demo scripts preserve authoritative identities and linked record IDs', function (): void {
    $attendanceSeed = file_get_contents(project_path('scripts/seed_phase2a_attendance_demo.php'));
    $attachmentSeed = file_get_contents(project_path('scripts/seed_phase2b_leave_attachment_demo.php'));
    $cleanupScript = file_get_contents(project_path('scripts/cleanup_phase2c_final_demo.php'));

    assert_true(is_string($attendanceSeed));
    assert_true(is_string($attachmentSeed));
    assert_true(is_string($cleanupScript));

    $authoritativeMappings = [
        [
            '$adminId', 'Aye Chan Moe', 'aye.chan.moe@silveroakhr.com', 'admin',
            '$informationTechnologyId', '$systemSupportId', '000031', 'admin@example.com', 'ADM-001', 31,
        ],
        [
            '$empA', 'Thiri Mon Aung', 'thiri.mon.aung@silveroakhr.com', 'employee',
            '$humanResourcesId', '$hrExecutiveId', '000032', 'emp_a_ontime@example.com', 'EMP-A', 32,
        ],
        [
            '$empB', 'Su Myat Nandar', 'su.myat.nandar@silveroakhr.com', 'employee',
            '$customerSupportId', '$seniorSupportId', '000033', 'emp_b_late@example.com', 'EMP-B', 33,
        ],
        [
            '$empC', 'Thant Zin Oo', 'thant.zin.oo@silveroakhr.com', 'employee',
            '$operationsId', '$operationsCoordinatorId', '000034', 'emp_c_absent@example.com', 'EMP-C', 34,
        ],
        [
            '$empD', 'Htet Naing Win', 'htet.naing.win@silveroakhr.com', 'employee',
            '$informationTechnologyId', '$itSupportId', '000035', 'emp_d_incomplete@example.com', 'EMP-D', 35,
        ],
        [
            '$empE', 'May Thu Aung', 'may.thu.aung@silveroakhr.com', 'employee',
            '$operationsId', '$operationsAssistantId', '000036', 'emp_e_leave@example.com', 'EMP-E', 36,
        ],
        [
            '$empF', 'Ei Ei Phyu', 'ei.ei.phyu@silveroakhr.com', 'employee',
            '$customerSupportId', '$supportSpecialistId', '000037', 'emp_f_livelate@example.com', 'EMP-F', 37,
        ],
        [
            '$empG', 'Kaung Htet Zaw', 'kaung.htet.zaw@silveroakhr.com', 'employee',
            '$informationTechnologyId', '$systemSupportId', '000038', 'emp_g_liveontime@example.com', 'EMP-G', 38,
        ],
    ];

    foreach ($authoritativeMappings as [
        $variable,
        $name,
        $email,
        $role,
        $departmentVariable,
        $positionVariable,
        $staffCode,
        $legacyEmail,
        $legacyStaffCode,
        $authoritativeId,
    ]) {
        $expectedCall = sprintf(
            "%s = getOrCreateUser(\$pdo, '%s', '%s', '%s', %s, %s, '%s', '%s', '%s', %d);",
            $variable,
            $name,
            $email,
            $role,
            $departmentVariable,
            $positionVariable,
            $staffCode,
            $legacyEmail,
            $legacyStaffCode,
            $authoritativeId
        );
        assert_contains($expectedCall, $attendanceSeed, "Authoritative mapping for {$name} must stay exact.");
    }

    foreach ([
        'A. On-time Employee',
        'B. Late Employee',
        'C. Absent Employee',
        'D. Incomplete Employee',
        'E. Leave Employee',
        'F. Live Late Employee',
        'G. Live Checked-In Employee',
    ] as $technicalName) {
        assert_not_contains($technicalName, $attendanceSeed);
    }

    assert_contains('FOR UPDATE', $attendanceSeed);
    assert_contains('Identity collision detected', $attendanceSeed);
    assert_contains('$id !== $authoritativeLegacyId', $attendanceSeed);
    assert_contains('expected authoritative user', $attendanceSeed);
    assert_contains('Target identity collision detected', $attendanceSeed);
    assert_contains('$pdo->beginTransaction();', $attendanceSeed);
    assert_contains('$pdo->commit();', $attendanceSeed);
    assert_contains('$pdo->rollBack();', $attendanceSeed);

    assert_same(1, preg_match('/UPDATE users SET .*? WHERE id = \?/s', $attendanceSeed));
    assert_same(1, preg_match('/UPDATE work_schedule_days.*?WHERE id = \?/s', $attendanceSeed));
    assert_contains('Existing attendance is authoritative history.', $attendanceSeed);
    assert_not_contains('UPDATE attendance_records', $attendanceSeed);
    assert_contains('SELECT id, work_schedule_template_id', $attendanceSeed);
    assert_contains('already has a different effective employee schedule', $attendanceSeed);
    assert_not_contains('UPDATE work_schedule_assignments', $attendanceSeed);
    assert_not_contains('DELETE FROM', $attendanceSeed, 'The rerunnable attendance seed must preserve linked row IDs.');
    assert_contains('Flexible Support Shift', $attendanceSeed);
    assert_contains('$legacyTemplateName = "Demo Live Shift";', $attendanceSeed);

    assert_contains('may.thu.aung@silveroakhr.com', $attachmentSeed);
    assert_contains('foreach ($leaveIds as $leaveId)', $attachmentSeed);
    assert_contains('foreach ($attachments as $attachment)', $attachmentSeed);
    assert_contains('No database rows or files were created, changed, or deleted.', $attachmentSeed);
    foreach (['%PDF-', '$dummyPdfContent', 'file_put_contents(', 'unlink(', 'DELETE FROM', 'INSERT INTO', 'UPDATE '] as $forbidden) {
        assert_not_contains($forbidden, $attachmentSeed, 'Phase 2B must validate real attachments without fabricating or mutating data.');
    }

    assert_contains('Apply mode has been retired.', $cleanupScript);
    assert_contains('aye.chan.moe@silveroakhr.com', $cleanupScript);
    assert_contains('Mode: READ-ONLY', $cleanupScript);
    assert_true(
        strpos($cleanupScript, 'exit(1);') < strpos($cleanupScript, "require_once __DIR__ . '/../vendor/autoload.php';"),
        'The retired apply mode must exit before opening a database connection.'
    );
    foreach (['DELETE FROM', 'INSERT INTO', 'UPDATE ', 'TRUNCATE ', 'file_put_contents(', 'unlink(', 'exec(', 'beginTransaction('] as $forbidden) {
        assert_not_contains($forbidden, $cleanupScript, 'The retired cleanup script must remain a read-only audit.');
    }
});
