<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Autoloader.php';
\App\Autoloader::register();

$config = require __DIR__ . '/../config/config.php';
$app = new \App\Core\Application($config);
$db = $app->getDatabase()->getConnection();
$storageDir = __DIR__ . '/../storage/private/leave_attachments';

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

    // 1. Create a sample PDF file in the storage directory
    if (!is_dir($storageDir)) {
        if (!mkdir($storageDir, 0750, true)) {
            throw new RuntimeException("Failed to create storage directory.");
        }
    }

    $sampleFileName = bin2hex(random_bytes(16)) . '.pdf';
    $sampleFilePath = $storageDir . '/' . $sampleFileName;
    
    // Create a dummy PDF content (just text pretending to be PDF for testing purposes, or minimal valid PDF)
    $dummyPdfContent = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> >>\nendobj\n4 0 obj\n<< /Length 51 >>\nstream\nBT\n/F1 24 Tf\n100 700 Td\n(Medical Certificate) Tj\nET\nendstream\nendobj\nxref\n0 5\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \n0000000253 00000 n \ntrailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n355\n%%EOF\n";
    file_put_contents($sampleFilePath, $dummyPdfContent);
    echo "Created sample medical certificate: $sampleFileName\n";

    // 2. Insert a Medical Leave Request
    $today = date('Y-m-d');
    $stmt = $db->prepare("INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, duration_days, status, reason) VALUES (?, 'medical', ?, ?, 1, 'pending', 'Medical checkup')");
    $stmt->execute([$empD, $today, $today]);
    $leaveId = $db->lastInsertId();

    // 3. Insert the attachment record
    $stmt = $db->prepare("INSERT INTO leave_request_attachments (leave_request_id, file_path, original_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $leaveId,
        'leave_attachments/' . $sampleFileName,
        'medical_certificate.pdf',
        'application/pdf',
        filesize($sampleFilePath)
    ]);

    echo "Created medical leave request #$leaveId with attachment for Employee D.\n";

    $db->commit();
    echo "Demo seed completed successfully.\n";

} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
