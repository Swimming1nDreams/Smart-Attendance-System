<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Autoloader.php';
\App\Autoloader::register();

$config = require __DIR__ . '/../config/config.php';
$app = new \App\Core\Application($config);
$db = $app->getDatabase()->getConnection();
$storageBaseDir = __DIR__ . '/../storage/private';
$storageDir = $storageBaseDir . '/leave_attachments';

$dummyPdfContent = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> >>\nendobj\n4 0 obj\n<< /Length 51 >>\nstream\nBT\n/F1 24 Tf\n100 700 Td\n(Medical Certificate) Tj\nET\nendstream\nendobj\nxref\n0 5\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \n0000000253 00000 n \ntrailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n355\n%%EOF\n";

try {
    $db->beginTransaction();

    echo "Running Phase 2B Leave Attachment Demo Seed...\n";

    // Find Employee E (emp_e_leave@example.com) for Medical Leave demo
    $stmt = $db->prepare("SELECT id FROM users WHERE email = 'emp_e_leave@example.com' AND role = 'employee'");
    $stmt->execute();
    $empD = $stmt->fetchColumn();

    if (!$empD) {
        throw new RuntimeException("Employee E not found. Did you run the Phase 2A seed?");
    }

    if (!is_dir($storageDir)) {
        if (!mkdir($storageDir, 0750, true)) {
            throw new RuntimeException("Failed to create storage directory.");
        }
    }

    $demoReason = 'SAPAS Phase 2B Demo - Medical checkup';
    $demoOriginalName = 'medical_certificate.pdf';

    // 1. Find existing demo records
    // Identify by: user_id = emp_e_leave, leave_type = medical,
    // AND (reason = Canonical OR (reason = 'Medical checkup' AND original_name = 'medical_certificate.pdf'))
    $stmt = $db->prepare("SELECT lr.id, lra.id as attachment_id, lra.file_path, lr.reason
                          FROM leave_requests lr
                          LEFT JOIN leave_request_attachments lra ON lr.id = lra.leave_request_id
                          WHERE lr.user_id = ? AND lr.leave_type = 'medical'
                            AND (
                                (lr.reason = ?)
                                OR
                                (lr.reason = 'Medical checkup' AND lra.original_name = ?)
                            )
                          ORDER BY lr.id DESC");
    $stmt->execute([$empD, $demoReason, $demoOriginalName]);
    $existingRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($existingRecords) > 0) {
        $validRecord = array_shift($existingRecords); // Keep the first (newest) record

        // Remove duplicates
        foreach ($existingRecords as $duplicate) {
            if ($duplicate['file_path']) {
                $physicalPath = $storageBaseDir . '/' . $duplicate['file_path'];
                if (file_exists($physicalPath)) {
                    unlink($physicalPath);
                }
            }
            if ($duplicate['attachment_id']) {
                $delStmt = $db->prepare("DELETE FROM leave_request_attachments WHERE id = ?");
                $delStmt->execute([$duplicate['attachment_id']]);
            }
            $delStmt = $db->prepare("DELETE FROM leave_requests WHERE id = ?");
            $delStmt->execute([$duplicate['id']]);
            echo "Removed duplicate demo record #{$duplicate['id']}\n";
        }

        // Upgrade legacy record if necessary
        if ($validRecord['reason'] === 'Medical checkup') {
            $updStmt = $db->prepare("UPDATE leave_requests SET reason = ? WHERE id = ?");
            $updStmt->execute([$demoReason, $validRecord['id']]);
            echo "Upgraded legacy demo record #{$validRecord['id']} to canonical demo reason.\n";
        }

        // Check if valid record has attachment row and physical file
        if ($validRecord['attachment_id'] && $validRecord['file_path']) {
            $physicalPath = $storageBaseDir . '/' . $validRecord['file_path'];
            if (!file_exists($physicalPath)) {
                file_put_contents($physicalPath, $dummyPdfContent);
                echo "Recreated missing physical file for existing demo record #{$validRecord['id']}\n";
                // Update file size just in case
                $updStmt = $db->prepare("UPDATE leave_request_attachments SET file_size = ? WHERE id = ?");
                $updStmt->execute([filesize($physicalPath), $validRecord['attachment_id']]);
            } else {
                echo "Demo record #{$validRecord['id']} already exists and is valid.\n";
            }
        } else {
            // Missing attachment record for the valid record
            $sampleFileName = bin2hex(random_bytes(16)) . '.pdf';
            $sampleFilePath = $storageDir . '/' . $sampleFileName;
            file_put_contents($sampleFilePath, $dummyPdfContent);

            $stmt = $db->prepare("INSERT INTO leave_request_attachments (leave_request_id, file_path, original_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $validRecord['id'],
                'leave_attachments/' . $sampleFileName,
                $demoOriginalName,
                'application/pdf',
                filesize($sampleFilePath)
            ]);
            echo "Added missing attachment to existing demo record #{$validRecord['id']}\n";
        }
    } else {
        // Create new record
        $sampleFileName = bin2hex(random_bytes(16)) . '.pdf';
        $sampleFilePath = $storageDir . '/' . $sampleFileName;
        file_put_contents($sampleFilePath, $dummyPdfContent);
        echo "Created sample medical certificate: $sampleFileName\n";

        $today = date('Y-m-d');
        $stmt = $db->prepare("INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, duration_days, status, reason) VALUES (?, 'medical', ?, ?, 1, 'pending', ?)");
        $stmt->execute([$empD, $today, $today, $demoReason]);
        $leaveId = $db->lastInsertId();

        $stmt = $db->prepare("INSERT INTO leave_request_attachments (leave_request_id, file_path, original_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $leaveId,
            'leave_attachments/' . $sampleFileName,
            $demoOriginalName,
            'application/pdf',
            filesize($sampleFilePath)
        ]);
        echo "Created canonical demo medical leave request #$leaveId with attachment for Employee E.\n";
    }

    $db->commit();
    echo "Demo seed completed successfully.\n";

} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
