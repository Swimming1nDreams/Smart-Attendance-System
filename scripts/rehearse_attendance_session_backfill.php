<?php

declare(strict_types=1);

use App\Autoloader;
use App\Core\Application;
use App\Models\AttendanceSession;
use App\Services\AttendanceCalculationService;
use App\Services\AttendanceIntervalAggregationService;
use App\Services\AttendanceSessionBackfillPlanner;
use App\Services\AttendanceSessionBackfillService;
use App\Services\WorkScheduleResolverService;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Autoloader.php';
Autoloader::register();

$apply = false;
$liveApply = false;
$limit = null;
$reportPath = null;
$liveConfirmation = null;
$expectedCandidates = null;
$backupFile = null;

foreach (array_slice($argv ?? [], 1) as $argument) {
    if ($argument === '--apply') {
        $apply = true;
        continue;
    }
    if ($argument === '--live-apply') {
        $liveApply = true;
        continue;
    }
    if (str_starts_with($argument, '--confirm-live=')) {
        $liveConfirmation = substr($argument, strlen('--confirm-live='));
        continue;
    }
    if (str_starts_with($argument, '--expected-candidates=')) {
        $value = substr($argument, strlen('--expected-candidates='));
        if (!ctype_digit($value)) {
            fwrite(STDERR, "--expected-candidates must be a non-negative integer.\n");
            exit(1);
        }
        $expectedCandidates = (int) $value;
        continue;
    }
    if (str_starts_with($argument, '--backup-file=')) {
        $backupFile = trim(substr($argument, strlen('--backup-file=')), " \t\n\r\0\x0B\"");
        if ($backupFile === '') {
            fwrite(STDERR, "--backup-file path cannot be empty.\n");
            exit(1);
        }
        continue;
    }
    if (str_starts_with($argument, '--limit=')) {
        $value = substr($argument, strlen('--limit='));
        if (!ctype_digit($value) || (int) $value <= 0) {
            fwrite(STDERR, "--limit must be a positive integer.\n");
            exit(1);
        }
        $limit = (int) $value;
        continue;
    }
    if (str_starts_with($argument, '--report=')) {
        $reportPath = trim(substr($argument, strlen('--report=')));
        if ($reportPath === '') {
            fwrite(STDERR, "--report path cannot be empty.\n");
            exit(1);
        }
        continue;
    }

    fwrite(STDERR, "Unknown option: {$argument}\nSupported: --apply, --live-apply, --confirm-live=PHRASE, --expected-candidates=N, --backup-file=PATH, --limit=N, --report=PATH\n");
    exit(1);
}

if ($apply && $liveApply) {
    fwrite(STDERR, "Choose only one mutating mode: --apply (rehearsal) OR --live-apply.\n");
    exit(1);
}
if ($liveApply && $limit !== null) {
    fwrite(STDERR, "--limit is forbidden with --live-apply; live backfill must validate the full dataset.\n");
    exit(1);
}
if ($liveApply) {
    if ($liveConfirmation === null || $liveConfirmation === '') {
        fwrite(STDERR, "--live-apply requires --confirm-live=BACKFILL_SMART_ATTENDANCE.\n");
        exit(1);
    }
    if ($expectedCandidates === null) {
        fwrite(STDERR, "--live-apply requires --expected-candidates=N from the latest read-only readiness report.\n");
        exit(1);
    }
    if ($backupFile === null) {
        fwrite(STDERR, "--live-apply requires --backup-file=PATH to a verified SQL backup.\n");
        exit(1);
    }
    if (!is_file($backupFile)) {
        fwrite(STDERR, "Backup file does not exist: {$backupFile}\n");
        exit(1);
    }
    $backupSize = filesize($backupFile);
    if ($backupSize === false || $backupSize < 1024) {
        fwrite(STDERR, "Backup file is too small or unreadable to be accepted as a live database backup: {$backupFile}\n");
        exit(1);
    }
    if (strtolower((string) pathinfo($backupFile, PATHINFO_EXTENSION)) !== 'sql') {
        fwrite(STDERR, "--backup-file must point to a .sql database backup.\n");
        exit(1);
    }
    $backupContents = file_get_contents($backupFile);
    if ($backupContents === false
        || !str_contains($backupContents, 'CREATE TABLE `attendance_records`')
        || !str_contains($backupContents, 'CREATE TABLE `attendance_sessions`')) {
        fwrite(STDERR, "Backup file does not look like a complete OAAS attendance database dump.\n");
        exit(1);
    }
}

$config = require __DIR__ . '/../config/config.php';
$timezone = (string) ($config['app']['timezone'] ?? 'Asia/Yangon');
date_default_timezone_set($timezone);

try {
    $app = new Application($config);
    $pdo = $app->getDatabase()->getConnection();
    $sessions = new AttendanceSession($pdo);
    $calculator = new AttendanceCalculationService(new AttendanceIntervalAggregationService());
    $service = new AttendanceSessionBackfillService(
        $pdo,
        $sessions,
        new AttendanceSessionBackfillPlanner(),
        new WorkScheduleResolverService($pdo),
        $calculator,
    );

    echo "==============================================\n";
    echo " OAAS Attendance Session Backfill — Phase 4\n";
    echo "==============================================\n";
    $modeLabel = $liveApply ? 'GUARDED LIVE APPLY' : ($apply ? 'REHEARSAL APPLY' : 'READ-ONLY ANALYSIS');
    echo 'Mode: ' . $modeLabel . "\n";
    if ($liveApply) {
        echo "Safety: exact database + confirmation phrase + verified SQL backup + expected candidate count are required.\n";
        echo 'Verified backup: ' . $backupFile . ' (' . (int) filesize((string) $backupFile) . " bytes)\n\n";
    } else {
        echo "Safety: --apply remains restricted to cloned rehearsal/test databases.\n\n";
    }

    $result = $liveApply
        ? $service->applyLive((string) $liveConfirmation, (int) $expectedCandidates)
        : ($apply ? $service->applyRehearsal($limit) : $service->analyze($limit));

    $mutating = $apply || $liveApply;
    $summary = $mutating && isset($result['post_analysis']) && is_array($result['post_analysis'])
        ? $result['post_analysis']
        : $result;
    $counts = (array) ($summary['counts'] ?? []);

    echo 'Database: ' . (string) ($result['database'] ?? $summary['database'] ?? '') . "\n";
    echo 'Records scanned: ' . (int) ($summary['records_scanned'] ?? 0) . "\n";
    if ($apply) {
        echo 'Sessions inserted in rehearsal: ' . (int) ($result['applied_count'] ?? 0) . "\n";
    } elseif ($liveApply) {
        echo 'Sessions inserted in live backfill: ' . (int) ($result['applied_count'] ?? 0) . "\n";
    }
    foreach ([
        'candidate' => 'Eligible legacy rows not yet backfilled',
        'already_backfilled' => 'Already backfilled exact matches',
        'no_physical_session' => 'Rows with no physical session',
        'skipped_incomplete' => 'Incomplete rows intentionally skipped',
        'manual_review' => 'Rows requiring manual review',
        'existing_session_conflict' => 'Existing-session conflicts',
        'resolution_errors' => 'Policy/calculation resolution errors',
        'semantic_mismatch_records' => 'Semantic mismatch records',
        'persisted_mismatch_records' => 'Persisted mismatch records',
        'reviewed_legacy_drift_records' => 'Reviewed legacy drift records',
        'unexplained_persisted_drift_records' => 'Unexplained persisted drift records',
    ] as $key => $label) {
        echo $label . ': ' . (int) ($counts[$key] ?? 0) . "\n";
    }

    echo 'Safe for rehearsal apply: ' . (($summary['safe_to_rehearse'] ?? false) ? 'YES' : 'NO') . "\n";
    echo 'Ready for live backfill: ' . (($summary['ready_for_live_backfill'] ?? false) ? 'YES' : 'NO') . "\n";

    $problemItems = array_values(array_filter(
        (array) ($summary['items'] ?? []),
        static fn (array $item): bool => in_array((string) ($item['classification'] ?? ''), ['manual_review', 'existing_session_conflict'], true)
            || !empty($item['resolution_error'])
            || !empty($item['semantic_mismatches'])
            || !empty($item['persisted_mismatches'])
            || !empty($item['reviewed_legacy_drift'])
            || !empty($item['unexplained_persisted_drift']),
    ));
    if ($problemItems !== []) {
        echo "\nFirst review items:\n";
        foreach (array_slice($problemItems, 0, 20) as $item) {
            echo sprintf(
                "  - Attendance #%d / %s / %s: %s",
                (int) ($item['attendance_id'] ?? 0),
                (string) ($item['staff_code'] ?? ''),
                (string) ($item['attendance_date'] ?? ''),
                (string) ($item['classification'] ?? ''),
            );
            $details = [];
            if (!empty($item['issues'])) {
                $details[] = 'issues=' . implode(',', (array) $item['issues']);
            }
            if (!empty($item['resolution_error'])) {
                $details[] = 'resolution=' . (string) $item['resolution_error'];
            }
            if (!empty($item['semantic_mismatches'])) {
                $details[] = 'semantic=' . implode(',', array_keys((array) $item['semantic_mismatches']));
            }
            if (!empty($item['persisted_mismatches'])) {
                $details[] = 'persisted=' . implode(',', array_keys((array) $item['persisted_mismatches']));
            }
            if (!empty($item['reviewed_legacy_drift'])) {
                $details[] = 'reviewed_legacy=' . implode(',', array_keys((array) $item['reviewed_legacy_drift']));
            }
            if (!empty($item['unexplained_persisted_drift'])) {
                $details[] = 'unexplained_persisted=' . implode(',', array_keys((array) $item['unexplained_persisted_drift']));
            }
            echo $details === [] ? "\n" : ' [' . implode('; ', $details) . "]\n";
        }
    }

    if ($reportPath === null) {
        $reportDirectory = __DIR__ . '/../storage/generated-reports';
        if (!is_dir($reportDirectory) && !mkdir($reportDirectory, 0775, true) && !is_dir($reportDirectory)) {
            throw new RuntimeException('Could not create generated report directory.');
        }
        $reportPath = $reportDirectory . '/attendance-session-backfill-' . date('Ymd-His') . '.json';
    }

    $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false || file_put_contents($reportPath, $encoded . PHP_EOL) === false) {
        throw new RuntimeException('Could not write the backfill rehearsal report.');
    }

    echo "\nDetailed report: {$reportPath}\n";
    if (!$mutating) {
        echo "No database rows were changed.\n";
    } elseif ($liveApply) {
        echo "Live backfill committed successfully. Only validated attendance_sessions rows were inserted; daily parents and overtime decisions were verified unchanged before commit.\n";
    } else {
        echo "Only attendance_sessions rows were inserted on the rehearsal database. Daily parents and overtime decisions were verified unchanged.\n";
    }

    $success = $liveApply
        ? (($summary['ready_for_live_backfill'] ?? false) === true)
        : (($summary['safe_to_rehearse'] ?? false) === true);
    exit($success ? 0 : 2);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Phase 4 attendance-session backfill failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
