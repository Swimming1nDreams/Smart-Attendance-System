<?php

declare(strict_types=1);

$isApplyRequested = in_array('--apply', $argv, true);
if ($isApplyRequested) {
    fwrite(STDERR, "Apply mode has been retired. This legacy cleanup is dry-run only because it can delete linked attendance, leave, attachment, and policy data.\n");
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Autoloader.php';
\App\Autoloader::register();

$config = require __DIR__ . '/../config/config.php';
$app = new \App\Core\Application($config);
$db = $app->getDatabase()->getConnection();
$storageDir = __DIR__ . '/../storage/private/leave_attachments';

$presentationEmails = [
    'thiri.mon.aung@silveroakhr.com',
    'su.myat.nandar@silveroakhr.com',
    'thant.zin.oo@silveroakhr.com',
    'htet.naing.win@silveroakhr.com',
    'may.thu.aung@silveroakhr.com',
    'ei.ei.phyu@silveroakhr.com',
    'kaung.htet.zaw@silveroakhr.com',
    'aye.chan.moe@silveroakhr.com',
];

echo "========================================\n";
echo " OAAS Phase 2C Final Demo Audit\n";
echo " Mode: READ-ONLY\n";
echo "========================================\n\n";

try {
    $stmt = $db->query("SELECT id, name, email, staff_code, role, status FROM users ORDER BY id");
    $allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $presentationUsers = [];
    $technicalIdentities = [];
    foreach ($allUsers as $user) {
        if (in_array((string) $user['email'], $presentationEmails, true)) {
            $presentationUsers[] = $user;
        }

        $technicalName = preg_match('/^[A-G]\.\s/', (string) $user['name']) === 1;
        $technicalEmail = str_ends_with(strtolower((string) $user['email']), '@example.com');
        $technicalStaffCode = preg_match('/^EMP-[A-G]$/D', (string) $user['staff_code']) === 1;
        if ($technicalName || $technicalEmail || $technicalStaffCode) {
            $technicalIdentities[] = $user;
        }
    }

    echo 'Presentation identities found: ' . count($presentationUsers) . ' / ' . count($presentationEmails) . "\n";
    foreach ($presentationUsers as $user) {
        echo "  - #{$user['id']} {$user['name']} <{$user['email']}> [{$user['staff_code']}]\n";
    }

    echo 'Technical/demo identities still visible: ' . count($technicalIdentities) . "\n";
    foreach ($technicalIdentities as $user) {
        echo "  - #{$user['id']} {$user['name']} <{$user['email']}> [{$user['staff_code']}]\n";
    }

    $countQueries = [
        'Users' => 'SELECT COUNT(*) FROM users',
        'Attendance rows' => 'SELECT COUNT(*) FROM attendance_records',
        'Leave requests' => 'SELECT COUNT(*) FROM leave_requests',
        'Attachment metadata rows' => 'SELECT COUNT(*) FROM leave_request_attachments',
        'Policy assignments' => 'SELECT COUNT(*) FROM work_schedule_assignments',
    ];
    foreach ($countQueries as $label => $sql) {
        echo $label . ': ' . $db->query($sql)->fetchColumn() . "\n";
    }

    $stmt = $db->query(
        "SELECT user_id, start_date, COUNT(*) AS duplicate_count
         FROM leave_requests
         GROUP BY user_id, start_date
         HAVING COUNT(*) > 1
         ORDER BY user_id, start_date"
    );
    $duplicateLeaveGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo 'Duplicate leave date groups requiring manual review: ' . count($duplicateLeaveGroups) . "\n";

    $orphanedFiles = [];
    if (is_dir($storageDir)) {
        $stmt = $db->query("SELECT file_path FROM leave_request_attachments");
        $validBasenames = array_map(
            static fn (string $path): string => basename(str_replace('\\', '/', $path)),
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        );

        $files = scandir($storageDir);
        if ($files !== false) {
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                if (!in_array($file, $validBasenames, true)) {
                    $orphanedFiles[] = $file;
                }
            }
        }
    }
    echo 'Orphaned attachment files requiring manual review: ' . count($orphanedFiles) . "\n";
    echo "\nAudit complete. No database rows or files were changed.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Audit failed: {$e->getMessage()}\n");
    exit(1);
}
