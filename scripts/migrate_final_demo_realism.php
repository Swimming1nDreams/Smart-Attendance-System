<?php

declare(strict_types=1);

require __DIR__ . '/../src/Autoloader.php';
\App\Autoloader::register();

$config = require __DIR__ . '/../config/config.php';
$app = new \App\Core\Application($config);
$pdo = $app->getDatabase()->getConnection();

$isApply = in_array('--apply', $argv, true);

echo "--- SAPAS FINAL DEMO REALISM MIGRATION ---\n";
echo $isApply ? "[MODE: APPLY]\n" : "[MODE: DRY RUN]\n";

// 1. Verify backup
if ($isApply) {
    if (!file_exists(__DIR__ . '/../storage/backups/pre_migration_backup.sql')) {
        echo "ERROR: Backup file storage/backups/pre_migration_backup.sql not found. Aborting.\n";
        exit(1);
    }
    echo "Backup verified.\n";
}

// 2. Verify IDs
$expectedIds = [
    31 => 'admin@example.com',
    32 => 'emp_a_ontime@example.com',
    33 => 'emp_b_late@example.com',
    34 => 'emp_c_absent@example.com',
    35 => 'emp_d_incomplete@example.com',
    36 => 'emp_e_leave@example.com',
    37 => 'emp_f_livelate@example.com',
    38 => 'emp_g_liveontime@example.com',
];

foreach ($expectedIds as $id => $email) {
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $currentEmail = $stmt->fetchColumn();
    
    if (!$currentEmail) {
        // Might already be migrated
        $stmt = $pdo->prepare("SELECT email FROM users WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $checkMigrated = $stmt->fetchColumn();
        if (!$checkMigrated) {
            echo "ERROR: User ID $id not found.\n";
            exit(1);
        }
    }
}
echo "User IDs verified.\n\n";

if ($isApply) {
    $pdo->beginTransaction();
}

try {
    // 3. Departments
    $departments = [
        'Human Resources',
        'Customer Support',
        'Operations',
        'Information Technology',
    ];
    $deptIds = [];
    foreach ($departments as $dept) {
        $stmt = $pdo->prepare("SELECT id FROM departments WHERE name = :name");
        $stmt->execute(['name' => $dept]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            echo "Creating department: $dept\n";
            if ($isApply) {
                $stmt = $pdo->prepare("INSERT INTO departments (name) VALUES (:name)");
                $stmt->execute(['name' => $dept]);
                $id = $pdo->lastInsertId();
            } else {
                $id = rand(100, 999); // dummy ID for dry run
            }
        } else {
            echo "Reusing department: $dept (ID: $id)\n";
        }
        $deptIds[$dept] = $id;
    }

    // 4. Positions
    $positions = [
        'HR Manager',
        'Customer Support Representative',
        'Senior Customer Support Representative',
        'Operations Coordinator',
        'Operations Assistant',
        'IT Support Specialist',
        'Customer Support Specialist',
        'System Support Officer',
    ];
    $posIds = [];
    foreach ($positions as $pos) {
        $stmt = $pdo->prepare("SELECT id FROM positions WHERE name = :name");
        $stmt->execute(['name' => $pos]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            echo "Creating position: $pos\n";
            if ($isApply) {
                $stmt = $pdo->prepare("INSERT INTO positions (name) VALUES (:name)");
                $stmt->execute(['name' => $pos]);
                $id = $pdo->lastInsertId();
            } else {
                $id = rand(100, 999);
            }
        } else {
            echo "Reusing position: $pos (ID: $id)\n";
        }
        $posIds[$pos] = $id;
    }

    echo "\n";

    // 5. Update Users IN PLACE
    $identityMap = [
        31 => ['name' => 'Thiri Mon', 'email' => 'thiri.mon@silveroakhr.com', 'role' => 'admin', 'department' => 'Human Resources', 'position' => 'HR Manager', 'staff_code' => 'EMP031'],
        32 => ['name' => 'Aung Min Khant', 'email' => 'aung.min.khant@silveroakhr.com', 'role' => 'employee', 'department' => 'Customer Support', 'position' => 'Customer Support Representative', 'staff_code' => 'EMP032'],
        33 => ['name' => 'Su Myat Noe', 'email' => 'su.myat.noe@silveroakhr.com', 'role' => 'employee', 'department' => 'Customer Support', 'position' => 'Senior Customer Support Representative', 'staff_code' => 'EMP033'],
        34 => ['name' => 'Thant Zin Oo', 'email' => 'thant.zin.oo@silveroakhr.com', 'role' => 'employee', 'department' => 'Operations', 'position' => 'Operations Coordinator', 'staff_code' => 'EMP034'],
        35 => ['name' => 'May Thu Aung', 'email' => 'may.thu.aung@silveroakhr.com', 'role' => 'employee', 'department' => 'Operations', 'position' => 'Operations Assistant', 'staff_code' => 'EMP035'],
        36 => ['name' => 'Htet Naing Win', 'email' => 'htet.naing.win@silveroakhr.com', 'role' => 'employee', 'department' => 'Information Technology', 'position' => 'IT Support Specialist', 'staff_code' => 'EMP036'],
        37 => ['name' => 'Ei Ei Phyu', 'email' => 'ei.ei.phyu@silveroakhr.com', 'role' => 'employee', 'department' => 'Customer Support', 'position' => 'Customer Support Specialist', 'staff_code' => 'EMP037'],
        38 => ['name' => 'Kaung Htet Zaw', 'email' => 'kaung.htet.zaw@silveroakhr.com', 'role' => 'employee', 'department' => 'Information Technology', 'position' => 'System Support Officer', 'staff_code' => 'EMP038'],
    ];

    foreach ($identityMap as $id => $data) {
        echo "Updating User $id: {$data['name']} ({$data['email']})\n";
        if ($isApply) {
            $stmt = $pdo->prepare("UPDATE users SET name = :name, email = :email, role = :role, department_id = :dept_id, position_id = :pos_id WHERE id = :id");
            $stmt->execute([
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $data['role'],
                'dept_id' => $deptIds[$data['department']],
                'pos_id' => $posIds[$data['position']],
                'id' => $id
            ]);
            
            // Just update staff_code if it doesn't match a new format
            $pdo->prepare("UPDATE users SET staff_code = :code WHERE id = :id")->execute(['code' => $data['staff_code'], 'id' => $id]);
        }
    }
    echo "\n";

    // 6. Work Policies
    $policies = [
        'Company Standard' => ['start' => '08:30:00', 'end' => '17:30:00', 'grace' => 5],
        'Customer Support Shift' => ['start' => '09:00:00', 'end' => '18:00:00', 'grace' => 10],
        'Live Shift' => ['start' => '09:00:00', 'end' => '18:00:00', 'grace' => 15],
    ];
    $templateIds = [];
    foreach ($policies as $name => $policy) {
        $stmt = $pdo->prepare("SELECT id FROM work_schedule_templates WHERE name = :name");
        $stmt->execute(['name' => $name]);
        $tid = $stmt->fetchColumn();
        if (!$tid) {
            echo "Creating Work Template: $name\n";
            if ($isApply) {
                $stmt = $pdo->prepare("INSERT INTO work_schedule_templates (name, timezone, effective_from, version_number) VALUES (:name, 'Asia/Yangon', '2020-01-01', 1)");
                $stmt->execute(['name' => $name]);
                $tid = $pdo->lastInsertId();
                // Add days
                for ($d = 1; $d <= 7; $d++) {
                    $isWork = ($d <= 5); // Mon-Fri
                    if ($name === 'Live Shift') {
                        $isWork = ($d <= 6); // Sat working for Live Shift
                    }
                    $stmt = $pdo->prepare("INSERT INTO work_schedule_days (work_schedule_template_id, day_of_week, is_working_day, start_time, end_time, expected_minutes, grace_minutes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $tid, $d, $isWork ? 1 : 0, 
                        $isWork ? $policy['start'] : null, 
                        $isWork ? $policy['end'] : null,
                        $isWork ? 540 : 0,
                        $isWork ? $policy['grace'] : 0
                    ]);
                }
            } else {
                $tid = rand(100, 999);
            }
        } else {
            echo "Reusing Work Template: $name (ID: $tid)\n";
        }
        $templateIds[$name] = $tid;
    }

    echo "\n";
    // 7. Assignments
    $assignments = [
        ['scope' => 'company', 'id' => null, 'template' => 'Company Standard'],
        ['scope' => 'department', 'id' => $deptIds['Customer Support'], 'template' => 'Customer Support Shift'],
        ['scope' => 'employee', 'id' => 37, 'template' => 'Live Shift'],
        ['scope' => 'employee', 'id' => 38, 'template' => 'Live Shift'],
    ];

    foreach ($assignments as $a) {
        $stmt = $pdo->prepare("SELECT id FROM work_schedule_assignments WHERE scope_type = :scope AND scope_id " . ($a['id'] === null ? "IS NULL" : "= :id"));
        $params = ['scope' => $a['scope']];
        if ($a['id'] !== null) $params['id'] = $a['id'];
        $stmt->execute($params);
        if (!$stmt->fetchColumn()) {
            echo "Creating Assignment: {$a['scope']} -> {$a['template']}\n";
            if ($isApply) {
                $stmt = $pdo->prepare("INSERT INTO work_schedule_assignments (work_schedule_template_id, scope_type, scope_id, effective_from) VALUES (?, ?, ?, '2020-01-01')");
                $stmt->execute([$templateIds[$a['template']], $a['scope'], $a['id']]);
            }
        } else {
            echo "Reusing Assignment: {$a['scope']} -> {$a['template']}\n";
            if ($isApply) {
                $stmt = $pdo->prepare("UPDATE work_schedule_assignments SET work_schedule_template_id = ? WHERE scope_type = ? AND scope_id " . ($a['id'] === null ? "IS NULL" : "= ?"));
                $execParams = [$templateIds[$a['template']], $a['scope']];
                if ($a['id'] !== null) $execParams[] = $a['id'];
                $stmt->execute($execParams);
            }
        }
    }

    echo "\n";
    // 8. Phase 2B Canonical Leave
    $stmt = $pdo->prepare("SELECT id, reason FROM leave_requests WHERE user_id = 36 AND leave_type = 'medical' LIMIT 1");
    $stmt->execute();
    $leave = $stmt->fetch();
    
    if ($leave) {
        echo "Updating Canonical Leave ID: {$leave['id']}\n";
        if ($isApply) {
            $pdo->prepare("UPDATE leave_requests SET reason = 'Medical checkup' WHERE id = ?")->execute([$leave['id']]);
            
            $stmt = $pdo->prepare("SELECT id, file_path FROM leave_request_attachments WHERE leave_request_id = ? LIMIT 1");
            $stmt->execute([$leave['id']]);
            $att = $stmt->fetch();
            if ($att) {
                $hex = bin2hex(random_bytes(8));
                $newPath = "leave_attachments/phase2b_demo_$hex.pdf";
                echo "Updating Attachment ID: {$att['id']} -> $newPath\n";
                
                $pdo->prepare("UPDATE leave_request_attachments SET file_path = ?, original_name = 'medical_certificate.pdf' WHERE id = ?")->execute([$newPath, $att['id']]);
                
                // Copy physical file if dry run wasn't used
                $oldFullPath = __DIR__ . '/../storage/app/private/' . ltrim($att['file_path'], '/');
                $newFullPath = __DIR__ . '/../storage/app/private/' . ltrim($newPath, '/');
                
                if (file_exists($oldFullPath)) {
                    copy($oldFullPath, $newFullPath);
                    unlink($oldFullPath);
                } else {
                    // Create dummy if missing
                    file_put_contents($newFullPath, "Dummy PDF Content");
                }
            }
        }
    } else {
        echo "WARNING: Canonical leave for user 36 not found (maybe not seeded yet).\n";
    }

    if ($isApply) {
        $pdo->commit();
        echo "\n[APPLIED SUCCESSFULLY]\n";
    } else {
        echo "\n[DRY RUN COMPLETE - NO CHANGES MADE]\n";
    }

} catch (\Exception $e) {
    if ($isApply) $pdo->rollBack();
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
