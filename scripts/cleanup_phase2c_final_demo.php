<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Autoloader.php';
\App\Autoloader::register();

$config = require __DIR__ . '/../config/config.php';
$app = new \App\Core\Application($config);
$db = $app->getDatabase()->getConnection();
$storageDir = __DIR__ . '/../storage/private/leave_attachments';
$backupsDir = __DIR__ . '/../storage/backups';

$isApply = in_array('--apply', $argv);

echo "========================================\n";
echo " SAPAS Phase 2C Final Demo Cleanup\n";
echo " Mode: " . ($isApply ? "APPLY (DESTRUCTIVE)" : "DRY RUN") . "\n";
echo "========================================\n\n";

if ($isApply) {
    if (!is_dir($backupsDir)) {
        mkdir($backupsDir, 0750, true);
    }
    
    $timestamp = date('Ymd_His');
    $backupFile = $backupsDir . '/pre_phase2c_cleanup_' . $timestamp . '.sql';
    
    $dbConfig = $config['database'] ?? [];
    $host = $dbConfig['host'] ?? '127.0.0.1';
    $dbname = $dbConfig['dbname'] ?? 'smart_attendance';
    $user = escapeshellarg($dbConfig['username'] ?? 'root');
    $pass = isset($dbConfig['password']) && $dbConfig['password'] !== '' ? '-p' . escapeshellarg($dbConfig['password']) : '';
    
    $mysqldump = 'mysqldump';
    if (file_exists('C:\\xampp\\mysql\\bin\\mysqldump.exe')) {
        $mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
    }

    $cmd = escapeshellarg($mysqldump) . " -h " . escapeshellarg($host) . " -u {$user} {$pass} " . escapeshellarg($dbname) . " > " . escapeshellarg($backupFile) . " 2>&1";
    
    echo "Creating backup...\n";
    exec($cmd, $output, $returnVar);
    
    if ($returnVar !== 0) {
        echo "mysqldump failed (code $returnVar). Output: " . implode("\n", $output) . "\n";
        echo "Creating JSON fallback backup instead...\n";
        $fallbackFile = str_replace('.sql', '.json', $backupFile);
        
        $backupData = [];
        $tables = ['users', 'attendance_records', 'leave_requests', 'leave_request_attachments', 'notifications', 'departments', 'positions', 'employee_profiles', 'work_schedule_assignments'];
        foreach ($tables as $table) {
            try {
                $stmt = $db->query("SELECT * FROM " . $table);
                if ($stmt) {
                    $backupData[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } catch (Exception $e) {}
        }
        
        if (file_put_contents($fallbackFile, json_encode($backupData, JSON_PRETTY_PRINT)) !== false) {
            echo "Fallback JSON backup created at: $fallbackFile\n\n";
            if (file_exists($backupFile)) unlink($backupFile); // remove empty/broken sql
        } else {
            echo "ERROR: Fallback backup also failed. Cannot proceed with --apply.\n";
            exit(1);
        }
    } else {
        echo "Backup created at: $backupFile\n\n";
    }
}

$emailsToKeep = [
    'emp_a_ontime@example.com',
    'emp_b_late@example.com',
    'emp_c_absent@example.com',
    'emp_d_incomplete@example.com',
    'emp_e_leave@example.com',
    'emp_f_livelate@example.com',
    'emp_g_liveontime@example.com',
    'admin@example.com'
];

try {
    if ($isApply) {
        $db->beginTransaction();
    }

    // 1. Identify Users
    $stmt = $db->query("SELECT id, email, role, status FROM users");
    $allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $usersToKeep = [];
    $usersToDelete = [];
    
    $inactiveKept = false;
    foreach ($allUsers as $u) {
        if (in_array($u['email'], $emailsToKeep) || $u['role'] === 'admin') {
            $usersToKeep[] = $u;
        } else if (!$inactiveKept && $u['status'] === 'inactive') {
            $usersToKeep[] = $u;
            $inactiveKept = true;
        } else {
            $usersToDelete[] = $u;
        }
    }
    
    echo "Users to keep: " . count($usersToKeep) . "\n";
    foreach ($usersToKeep as $u) {
        echo "  - {$u['email']} ({$u['role']}, {$u['status']})\n";
    }
    
    echo "Users to delete: " . count($usersToDelete) . "\n";
    
    $userIdsToDelete = array_column($usersToDelete, 'id');
    
    // 2. Notifications Cleanup
    $notificationsDeleted = 0;
    if (!empty($userIdsToDelete)) {
        $placeholders = implode(',', array_fill(0, count($userIdsToDelete), '?'));
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id IN ($placeholders)");
        $stmt->execute($userIdsToDelete);
        $notificationsDeleted = $stmt->fetchColumn();
        
        if ($isApply) {
            $db->prepare("DELETE FROM notifications WHERE user_id IN ($placeholders)")->execute($userIdsToDelete);
        }
    }
    // Also delete any very old notifications
    $stmt = $db->query("SELECT COUNT(*) FROM notifications WHERE created_at < '2026-08-01'");
    $oldNotifs = $stmt->fetchColumn();
    $notificationsDeleted += $oldNotifs;
    if ($isApply) {
        $db->exec("DELETE FROM notifications WHERE created_at < '2026-08-01'");
    }
    echo "Notifications to delete: $notificationsDeleted\n";

    // 3. Attendance Cleanup
    $stmt = $db->query("SELECT COUNT(*) FROM attendance_records WHERE attendance_date < '2026-08-01'");
    $oldAttendanceCount = $stmt->fetchColumn();
    
    $attendanceDeleted = $oldAttendanceCount;
    if ($isApply) {
        $db->exec("DELETE FROM attendance_records WHERE attendance_date < '2026-08-01'");
    }
    
    if (!empty($userIdsToDelete)) {
        $placeholders = implode(',', array_fill(0, count($userIdsToDelete), '?'));
        $stmt = $db->prepare("SELECT COUNT(*) FROM attendance_records WHERE user_id IN ($placeholders)");
        $stmt->execute($userIdsToDelete);
        $attendanceDeleted += $stmt->fetchColumn();
        
        if ($isApply) {
            $db->prepare("DELETE FROM attendance_records WHERE user_id IN ($placeholders)")->execute($userIdsToDelete);
        }
    }
    echo "Attendance records to delete: $attendanceDeleted\n";

    $stmt = $db->query("SELECT DATE_FORMAT(attendance_date, '%Y-%m') as month, COUNT(*) as count FROM attendance_records GROUP BY month ORDER BY month");
    $afterAttendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Attendance records remaining (if applied):\n";
    if (empty($afterAttendance)) {
        // Compute what's left virtually
        echo "  (Will be recalculated after deletion)\n";
    }
    foreach ($afterAttendance as $row) {
        echo "  - {$row['month']}: {$row['count']} rows\n";
    }

    // 4. Leave & Attachments Cleanup
    // Delete leaves for deleted users
    $leavesDeleted = 0;
    if (!empty($userIdsToDelete)) {
        $placeholders = implode(',', array_fill(0, count($userIdsToDelete), '?'));
        $stmt = $db->prepare("SELECT id FROM leave_requests WHERE user_id IN ($placeholders)");
        $stmt->execute($userIdsToDelete);
        $leaveIdsToDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($leaveIdsToDelete)) {
            $leavesDeleted += count($leaveIdsToDelete);
            $lPlaceholders = implode(',', array_fill(0, count($leaveIdsToDelete), '?'));
            
            // Unlink their attachments
            $stmt = $db->prepare("SELECT file_path FROM leave_request_attachments WHERE leave_request_id IN ($lPlaceholders)");
            $stmt->execute($leaveIdsToDelete);
            $filesToDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if ($isApply) {
                foreach ($filesToDelete as $f) {
                    $fullPath = __DIR__ . '/../storage/private/' . $f;
                    if (file_exists($fullPath)) unlink($fullPath);
                }
                $db->prepare("DELETE FROM leave_request_attachments WHERE leave_request_id IN ($lPlaceholders)")->execute($leaveIdsToDelete);
                $db->prepare("DELETE FROM leave_requests WHERE id IN ($lPlaceholders)")->execute($leaveIdsToDelete);
            }
        }
    }
    
    // Deduplicate Leave Requests for remaining users (keep latest for each user/date)
    // Especially for emp_e_leave
    $stmt = $db->query("
        SELECT id, file_path 
        FROM leave_request_attachments 
        WHERE leave_request_id NOT IN (
            SELECT MAX(lr.id) FROM leave_requests lr GROUP BY lr.user_id, lr.start_date
        )
    ");
    $duplicateAttachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $duplicateFiles = [];
    foreach ($duplicateAttachments as $dup) {
        $duplicateFiles[] = $dup['file_path'];
    }
    
    $stmt = $db->query("
        SELECT id 
        FROM leave_requests 
        WHERE id NOT IN (
            SELECT MAX(id) FROM leave_requests GROUP BY user_id, start_date
        )
    ");
    $duplicateLeaveIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $leavesDeleted += count($duplicateLeaveIds);
    
    if ($isApply && !empty($duplicateLeaveIds)) {
        $lPlaceholders = implode(',', array_fill(0, count($duplicateLeaveIds), '?'));
        foreach ($duplicateFiles as $f) {
            $fullPath = __DIR__ . '/../storage/private/' . $f;
            if (file_exists($fullPath)) unlink($fullPath);
        }
        $db->prepare("DELETE FROM leave_request_attachments WHERE leave_request_id IN ($lPlaceholders)")->execute($duplicateLeaveIds);
        $db->prepare("DELETE FROM leave_requests WHERE id IN ($lPlaceholders)")->execute($duplicateLeaveIds);
    }
    
    echo "Leave requests to delete: $leavesDeleted (including duplicates)\n";
    echo "Duplicate attachment files to remove: " . count($duplicateFiles) . "\n";
    
    // Check orphaned files in directory
    $orphanedFiles = 0;
    if (is_dir($storageDir)) {
        $stmt = $db->query("SELECT file_path FROM leave_request_attachments");
        $validFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $validBasenames = array_map(function($path) {
            return basename($path);
        }, $validFiles);
        
        $files = scandir($storageDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            // In apply mode, validFiles might reflect pre-delete state.
            // But since we just deleted duplicates, validBasenames is not accurate if queried before delete.
            // So query again if apply.
            if ($isApply) {
                $stmt = $db->query("SELECT file_path FROM leave_request_attachments");
                $validFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $validBasenames = array_map(function($path) {
                    return basename($path);
                }, $validFiles);
            }
            
            if (!in_array($file, $validBasenames)) {
                $orphanedFiles++;
                if ($isApply) {
                    unlink($storageDir . '/' . $file);
                }
            }
        }
    }
    echo "Orphaned files to remove: $orphanedFiles\n";

    // 5. Work Schedule Assignments and Employee Profiles
    if (!empty($userIdsToDelete)) {
        $placeholders = implode(',', array_fill(0, count($userIdsToDelete), '?'));
        if ($isApply) {
            $db->prepare("DELETE FROM work_schedule_assignments WHERE scope_type = 'employee' AND scope_id IN ($placeholders)")->execute($userIdsToDelete);
            $db->prepare("DELETE FROM employee_profiles WHERE user_id IN ($placeholders)")->execute($userIdsToDelete);
        }
    }

    // 6. Users
    if (!empty($userIdsToDelete)) {
        $placeholders = implode(',', array_fill(0, count($userIdsToDelete), '?'));
        if ($isApply) {
            $db->prepare("DELETE FROM users WHERE id IN ($placeholders)")->execute($userIdsToDelete);
        }
    }

    // 7. Departments and Positions
    // We only keep departments/positions that have remaining active users.
    $stmt = $db->query("SELECT id, name FROM departments");
    $allDepts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $deptsDeleted = 0;
    foreach ($allDepts as $dept) {
        // Are there users?
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE department_id = ?");
        $stmt->execute([$dept['id']]);
        if ($stmt->fetchColumn() == 0) {
            $deptsDeleted++;
            if ($isApply) {
                // Ignore errors if foreign key constraints exist on other tables
                try {
                    $db->prepare("DELETE FROM departments WHERE id = ?")->execute([$dept['id']]);
                } catch (Exception $e) {}
            }
        }
    }
    
    $stmt = $db->query("SELECT id, name FROM positions");
    $allPos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $posDeleted = 0;
    foreach ($allPos as $pos) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE position_id = ?");
        $stmt->execute([$pos['id']]);
        if ($stmt->fetchColumn() == 0) {
            $posDeleted++;
            if ($isApply) {
                try {
                    $db->prepare("DELETE FROM positions WHERE id = ?")->execute([$pos['id']]);
                } catch (Exception $e) {}
            }
        }
    }
    echo "Departments removed (or skipped): $deptsDeleted / " . count($allDepts) . "\n";
    echo "Positions removed (or skipped): $posDeleted / " . count($allPos) . "\n";

    if ($isApply) {
        $db->commit();
        echo "\nDatabase Cleanup Committed Successfully!\n";
        
        echo "\nRe-running Seed Scripts to refresh Demo Data...\n";
        exec("php " . escapeshellarg(__DIR__ . '/seed_phase2a_attendance_demo.php'), $outA, $retA);
        echo implode("\n", $outA) . "\n";
        
        // No need to run phase2b seed because we carefully deduplicated leave requests and kept the latest valid one.
        echo "Note: seed_phase2b_leave_attachment_demo.php not re-run automatically to prevent new duplicates, since we preserved the latest valid attachment.\n";
    }

    echo "\nSummary of final state (estimated):\n";
    $stmt = $db->query("SELECT COUNT(*) FROM users");
    echo "Users: " . $stmt->fetchColumn() . "\n";
    $stmt = $db->query("SELECT COUNT(*) FROM attendance_records");
    echo "Attendance rows: " . $stmt->fetchColumn() . "\n";
    $stmt = $db->query("SELECT COUNT(*) FROM leave_requests");
    echo "Leave requests: " . $stmt->fetchColumn() . "\n";
    $stmt = $db->query("SELECT COUNT(*) FROM leave_request_attachments");
    echo "Attachments: " . $stmt->fetchColumn() . "\n";
    
} catch (\Throwable $e) {
    if ($isApply && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
