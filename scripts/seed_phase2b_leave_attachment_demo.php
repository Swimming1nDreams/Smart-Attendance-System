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

try {
    $db->beginTransaction();

    echo "Validating Phase 2B Leave Attachment Demo Data...\n";

    // Resolve the approved-leave scenario employee without changing the authoritative user row.
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND role = 'employee' FOR UPDATE");
    $stmt->execute(['may.thu.aung@silveroakhr.com']);
    $empE = $stmt->fetchColumn();

    if (!$empE) {
        throw new RuntimeException('May Thu Aung was not found. Run the Phase 2A seed first.');
    }

    $storageRoot = realpath($storageDir);
    if ($storageRoot === false || !is_dir($storageRoot)) {
        throw new RuntimeException('Private leave attachment storage is missing. Upload a real attachment through the application first.');
    }
    $storageBoundary = rtrim($storageRoot, "\\/") . DIRECTORY_SEPARATOR;

    $demoReason = 'OAAS Phase 2B Demo - Medical checkup';
    $stmt = $db->prepare(
        "SELECT id
         FROM leave_requests
         WHERE user_id = ? AND leave_type = 'medical' AND reason = ?
         ORDER BY id
         FOR UPDATE"
    );
    $stmt->execute([(int) $empE, $demoReason]);
    $leaveIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if ($leaveIds === []) {
        throw new RuntimeException(
            'No canonical Phase 2B leave request exists. Create the leave and upload a real supporting document through the application.'
        );
    }

    $attachmentCount = 0;
    foreach ($leaveIds as $leaveId) {
        // Query attachments separately so multiple attachments never duplicate or delete the parent leave row.
        $stmt = $db->prepare(
            "SELECT id, file_path, original_name, mime_type, file_size
             FROM leave_request_attachments
             WHERE leave_request_id = ?
             ORDER BY id
             FOR UPDATE"
        );
        $stmt->execute([$leaveId]);
        $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($attachments === []) {
            throw new RuntimeException(
                "Canonical leave request #{$leaveId} has no attachment metadata. Upload a real supporting document through the application."
            );
        }

        foreach ($attachments as $attachment) {
            $attachmentId = (int) $attachment['id'];
            $relativePath = str_replace('\\', '/', trim((string) $attachment['file_path']));
            if (preg_match('#\Aleave_attachments/[A-Za-z0-9._-]+\z#D', $relativePath) !== 1) {
                throw new RuntimeException("Attachment #{$attachmentId} has an unsafe storage path.");
            }

            $extension = strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION));
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'pdf'], true)) {
                throw new RuntimeException("Attachment #{$attachmentId} has an unsupported file extension.");
            }

            $physicalPath = realpath($storageBaseDir . '/' . $relativePath);
            if (
                $physicalPath === false
                || strncasecmp($physicalPath, $storageBoundary, strlen($storageBoundary)) !== 0
                || !is_file($physicalPath)
                || !is_readable($physicalPath)
            ) {
                throw new RuntimeException(
                    "Attachment #{$attachmentId} is missing or unreadable. Upload the real supporting document again; no synthetic file was created."
                );
            }

            $attachmentCount++;
            echo "Validated attachment #{$attachmentId} for leave request #{$leaveId}: {$attachment['original_name']}\n";
        }
    }

    $db->commit();
    echo "Validated {$attachmentCount} real attachment(s) across " . count($leaveIds) . " canonical leave request(s).\n";
    echo "No database rows or files were created, changed, or deleted.\n";
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Phase 2B validation failed: {$e->getMessage()}\n");
    exit(1);
}
