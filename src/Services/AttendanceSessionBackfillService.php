<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AttendanceSession;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Phase 4 migration rehearsal for legacy attendance rows.
 *
 * This service never updates attendance_records or overtime_decisions. It can
 * insert one completed child session for an eligible legacy daily row. Rehearsal
 * apply remains clone-only; the canonical smart_attendance database is writable
 * only through the explicit guarded live-backfill entry point after full readiness
 * validation, exact confirmation, and expected-candidate verification.
 */
final class AttendanceSessionBackfillService
{
    public const LIVE_DATABASE_NAME = 'smart_attendance';
    public const LIVE_CONFIRMATION = 'BACKFILL_SMART_ATTENDANCE';

    private AttendanceSessionBackfillValidator $validator;

    /** @var list<string> */
    private const SEMANTIC_PARITY_KEYS = [
        'attendance_state',
        'late_minutes',
        'is_late',
        'is_early_departure',
        'is_missing_check_out',
        'is_overnight_attendance',
        'scheduled_overlap_minutes',
        'out_of_schedule_work_minutes',
        'working_hours',
        'regular_minutes',
        'early_departure_minutes',
        'early_overtime_minutes',
        'after_hours_overtime_minutes',
        'non_working_day_overtime_minutes',
        'holiday_overtime_minutes',
        'raw_calculated_overtime_minutes',
        'calculated_overtime_minutes',
        'approved_overtime_minutes',
        'overtime_approval_status',
    ];

    /** @var list<string> */
    private const PERSISTED_COMPARISON_KEYS = [
        'attendance_state',
        'late_minutes',
        'is_late',
        'is_early_departure',
        'is_missing_check_out',
        'is_overnight_attendance',
        'working_hours',
        'regular_minutes',
        'early_departure_minutes',
        'early_overtime_minutes',
        'after_hours_overtime_minutes',
        'non_working_day_overtime_minutes',
        'holiday_overtime_minutes',
        'raw_calculated_overtime_minutes',
        'calculated_overtime_minutes',
    ];

    public function __construct(
        private PDO $connection,
        private AttendanceSession $sessions,
        private AttendanceSessionBackfillPlanner $planner,
        private WorkScheduleResolverService $resolver,
        private AttendanceCalculationService $calculator,
        ?AttendanceSessionBackfillValidator $validator = null,
    ) {
        $this->validator = $validator ?? new AttendanceSessionBackfillValidator();
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(?int $limit = null): array
    {
        $databaseName = $this->databaseName();
        $records = $this->loadLegacyRecords($limit);
        $attendanceIds = array_values(array_filter(array_map(
            static fn (array $record): int => (int) ($record['id'] ?? 0),
            $records,
        ), static fn (int $id): bool => $id > 0));
        $existingSessions = $this->sessions->findByAttendanceIds($attendanceIds);
        $timezone = new DateTimeZone(date_default_timezone_get());

        $items = [];
        $resolutionRequests = [];
        foreach ($records as $record) {
            $attendanceId = (int) ($record['id'] ?? 0);
            $plan = $this->planner->plan($record, $existingSessions[$attendanceId] ?? [], $timezone);
            $item = array_replace($plan, [
                'employee_name' => (string) ($record['employee_name'] ?? ''),
                'staff_code' => (string) ($record['staff_code'] ?? ''),
                'semantic_mismatches' => [],
                'persisted_mismatches' => [],
                'reviewed_legacy_drift' => [],
                'unexplained_persisted_drift' => [],
                'resolution_error' => null,
            ]);
            $items[$attendanceId] = $item;

            if (in_array((string) $plan['classification'], ['candidate', 'already_backfilled'], true)) {
                $resolutionRequests[] = [
                    'employee_id' => (int) ($record['user_id'] ?? 0),
                    'business_date' => (string) ($plan['business_date'] ?? $record['attendance_date'] ?? ''),
                ];
            }
        }

        [$resolutions, $resolutionErrors] = $this->resolveRequests($resolutionRequests);

        foreach ($records as $record) {
            $attendanceId = (int) ($record['id'] ?? 0);
            if (!isset($items[$attendanceId])) {
                continue;
            }
            $classification = (string) ($items[$attendanceId]['classification'] ?? '');
            if (!in_array($classification, ['candidate', 'already_backfilled'], true)) {
                continue;
            }

            $key = $this->resolutionKey((int) ($record['user_id'] ?? 0), (string) ($items[$attendanceId]['business_date'] ?? ''));
            $resolution = $resolutions[$key] ?? null;
            if (!is_array($resolution)) {
                $items[$attendanceId]['resolution_error'] = $resolutionErrors[$key] ?? 'policy_resolution_failed';
                continue;
            }

            $policyTimezone = $this->timezoneForResolution($resolution);
            $replanned = $this->planner->plan($record, $existingSessions[$attendanceId] ?? [], $policyTimezone);
            foreach (['classification', 'source', 'check_in_at', 'check_out_at', 'duration_minutes', 'issues'] as $field) {
                $items[$attendanceId][$field] = $replanned[$field] ?? null;
            }
            if (!in_array((string) ($replanned['classification'] ?? ''), ['candidate', 'already_backfilled'], true)) {
                continue;
            }

            try {
                $legacy = $this->calculator->calculate(
                    $resolution,
                    $record,
                    (string) $replanned['check_in_at'],
                    (string) $replanned['check_out_at'],
                    (int) ($record['is_manual_override'] ?? 0) === 1,
                );
                $asOf = (new DateTimeImmutable((string) $replanned['check_out_at'], $policyTimezone))->modify('+1 day');
                $sessionCalculation = $this->calculator->calculateFromSessions(
                    $resolution,
                    $record,
                    [[
                        'attendance_id' => $attendanceId,
                        'check_in_at' => (string) $replanned['check_in_at'],
                        'check_out_at' => (string) $replanned['check_out_at'],
                    ]],
                    (int) ($record['is_manual_override'] ?? 0) === 1,
                    $asOf,
                );
                $items[$attendanceId]['semantic_mismatches'] = $this->compareMaps($legacy, $sessionCalculation, self::SEMANTIC_PARITY_KEYS, false);
                $items[$attendanceId]['persisted_mismatches'] = $this->persistedMismatches($record, $sessionCalculation, $replanned);
                $drift = $this->validator->classifyPersistedDrift(
                    $record,
                    $sessionCalculation,
                    $replanned,
                    $resolution,
                    $items[$attendanceId]['persisted_mismatches'],
                );
                $items[$attendanceId]['reviewed_legacy_drift'] = $drift['reviewed_legacy_drift'];
                $items[$attendanceId]['unexplained_persisted_drift'] = $drift['unexplained_persisted_drift'];
            } catch (Throwable $exception) {
                $items[$attendanceId]['resolution_error'] = 'calculation_failed: ' . $exception->getMessage();
            }
        }

        $items = array_values($items);
        $counts = $this->countItems($items);
        $safeToRehearse = $counts['manual_review'] === 0
            && $counts['existing_session_conflict'] === 0
            && $counts['resolution_errors'] === 0
            && $counts['semantic_mismatch_records'] === 0;
        $readyForLiveBackfill = $safeToRehearse && $this->validator->isReadyForLiveBackfill($counts);

        return [
            'database' => $databaseName,
            'mode' => 'analysis',
            'records_scanned' => count($records),
            'limit' => $limit,
            'counts' => $counts,
            'safe_to_rehearse' => $safeToRehearse,
            'ready_for_live_backfill' => $readyForLiveBackfill,
            'items' => $items,
        ];
    }

    /**
     * Insert child rows only on a disposable rehearsal/test database.
     *
     * @return array<string, mixed>
     */
    public function applyRehearsal(?int $limit = null): array
    {
        $databaseName = $this->databaseName();
        if (!self::isSafeRehearsalDatabaseName($databaseName)) {
            throw new RuntimeException(sprintf(
                'Refusing Phase 4 rehearsal apply on database "%s". Use a cloned database whose name contains test, backfill, rehearsal, clone, or sandbox.',
                $databaseName,
            ));
        }

        return $this->applyTransactional(
            mode: 'rehearsal_apply',
            limit: $limit,
            requireLiveReadiness: false,
            expectedCandidates: null,
        );
    }

    /**
     * Perform the one-time validated backfill on the canonical development database.
     *
     * This remains intentionally hard to invoke: the exact database name, an exact
     * confirmation phrase, a full-dataset readiness pass, and the expected candidate
     * count must all match before a transaction can insert any child sessions.
     *
     * @return array<string, mixed>
     */
    public function applyLive(string $confirmation, int $expectedCandidates): array
    {
        $databaseName = $this->databaseName();
        if (!self::isLiveDatabaseName($databaseName)) {
            throw new RuntimeException(sprintf(
                'Refusing live attendance-session backfill on database "%s". Expected exactly "%s".',
                $databaseName,
                self::LIVE_DATABASE_NAME,
            ));
        }
        if (!hash_equals(self::LIVE_CONFIRMATION, trim($confirmation))) {
            throw new RuntimeException('Live backfill confirmation phrase is invalid.');
        }
        if ($expectedCandidates < 0) {
            throw new RuntimeException('Expected live candidate count cannot be negative.');
        }

        return $this->applyTransactional(
            mode: 'live_apply',
            limit: null,
            requireLiveReadiness: true,
            expectedCandidates: $expectedCandidates,
        );
    }

    public static function isSafeRehearsalDatabaseName(string $databaseName): bool
    {
        $normalized = strtolower(trim($databaseName));
        if ($normalized === '' || $normalized === self::LIVE_DATABASE_NAME) {
            return false;
        }

        return preg_match('/(?:test|backfill|rehearsal|clone|sandbox)/', $normalized) === 1;
    }

    public static function isLiveDatabaseName(string $databaseName): bool
    {
        return strtolower(trim($databaseName)) === self::LIVE_DATABASE_NAME;
    }

    /**
     * @return array<string, mixed>
     */
    private function applyTransactional(
        string $mode,
        ?int $limit,
        bool $requireLiveReadiness,
        ?int $expectedCandidates,
    ): array {
        if ($this->connection->inTransaction()) {
            throw new RuntimeException('Attendance-session backfill cannot start inside an existing transaction.');
        }

        $this->connection->beginTransaction();
        try {
            // Freeze the existing attendance/session/decision rows for the duration of
            // preflight, insertion, parity checks, and postflight validation.
            $this->lockRows('attendance_records');
            $this->lockRows('attendance_sessions');
            $this->lockRows('overtime_decisions');

            $analysis = $this->analyze($limit);
            $preflightKey = $requireLiveReadiness ? 'ready_for_live_backfill' : 'safe_to_rehearse';
            if (($analysis[$preflightKey] ?? false) !== true) {
                throw new RuntimeException($requireLiveReadiness
                    ? 'Live backfill preflight is not ready. Resolve every blocking or unexplained drift condition first.'
                    : 'Backfill rehearsal preflight is not safe. Resolve manual-review, session-conflict, policy, or semantic-parity issues first.');
            }

            $candidates = array_values(array_filter(
                (array) ($analysis['items'] ?? []),
                static fn (array $item): bool => (string) ($item['classification'] ?? '') === 'candidate',
            ));

            if ($expectedCandidates !== null && count($candidates) !== $expectedCandidates) {
                throw new RuntimeException(sprintf(
                    'Live backfill candidate-count guard failed: expected %d, current analysis found %d.',
                    $expectedCandidates,
                    count($candidates),
                ));
            }

            if ($requireLiveReadiness) {
                $alreadyBackfilled = (int) (($analysis['counts']['already_backfilled'] ?? 0));
                $sessionRows = $this->tableCount('attendance_sessions');
                if ($sessionRows !== $alreadyBackfilled) {
                    throw new RuntimeException(sprintf(
                        'Unexpected attendance_sessions rows exist: table has %d rows but only %d exact legacy matches were validated.',
                        $sessionRows,
                        $alreadyBackfilled,
                    ));
                }
            }

            $candidateIds = array_values(array_map(
                static fn (array $item): int => (int) $item['attendance_id'],
                $candidates,
            ));
            $parentsBefore = $this->snapshotAllRows('attendance_records');
            $decisionsBefore = $this->snapshotAllRows('overtime_decisions');
            $parentCountBefore = count($parentsBefore);
            $decisionCountBefore = count($decisionsBefore);
            $sessionCountBefore = $this->tableCount('attendance_sessions');

            foreach ($candidates as $candidate) {
                $attendanceId = (int) $candidate['attendance_id'];
                $lock = $this->connection->prepare('SELECT id FROM attendance_records WHERE id = :id FOR UPDATE');
                $lock->execute([':id' => $attendanceId]);
                if ($lock->fetchColumn() === false) {
                    throw new RuntimeException(sprintf('Attendance parent %d disappeared during backfill.', $attendanceId));
                }
                if ($this->sessions->countByAttendanceId($attendanceId) !== 0) {
                    throw new RuntimeException(sprintf('Attendance parent %d gained a child session during backfill.', $attendanceId));
                }

                $sessionId = $this->sessions->createCompleted(
                    $attendanceId,
                    (string) $candidate['check_in_at'],
                    (string) $candidate['check_out_at'],
                );
                if ($sessionId <= 0) {
                    throw new RuntimeException(sprintf('Failed to create backfill session for attendance %d.', $attendanceId));
                }
            }

            foreach ($candidates as $candidate) {
                $attendanceId = (int) $candidate['attendance_id'];
                $rows = $this->sessions->findByAttendanceId($attendanceId);
                if (count($rows) !== 1) {
                    throw new RuntimeException(sprintf('Attendance %d does not have exactly one validated backfilled session.', $attendanceId));
                }
                if (
                    trim((string) ($rows[0]['check_in_at'] ?? '')) !== (string) $candidate['check_in_at']
                    || trim((string) ($rows[0]['check_out_at'] ?? '')) !== (string) $candidate['check_out_at']
                ) {
                    throw new RuntimeException(sprintf('Attendance %d interval validation failed after backfill insert.', $attendanceId));
                }
            }

            if ($parentCountBefore !== $this->tableCount('attendance_records')) {
                throw new RuntimeException('attendance_records row count changed during attendance-session backfill.');
            }
            if ($decisionCountBefore !== $this->tableCount('overtime_decisions')) {
                throw new RuntimeException('overtime_decisions row count changed during attendance-session backfill.');
            }
            if ($parentsBefore !== $this->snapshotAllRows('attendance_records')) {
                throw new RuntimeException('A daily attendance parent changed during attendance-session backfill.');
            }
            if ($decisionsBefore !== $this->snapshotAllRows('overtime_decisions')) {
                throw new RuntimeException('An overtime decision changed during attendance-session backfill.');
            }
            if ($this->tableCount('attendance_sessions') !== $sessionCountBefore + count($candidates)) {
                throw new RuntimeException('attendance_sessions row count did not increase by exactly the validated candidate count.');
            }

            // Analyze again before commit while the inserted sessions are still inside
            // the same transaction. A bad postflight rolls everything back.
            $post = $this->analyze($limit);
            if ((int) (($post['counts']['candidate'] ?? 0)) !== 0) {
                throw new RuntimeException('Post-backfill validation still found eligible legacy candidates.');
            }
            if ((int) (($post['counts']['semantic_mismatch_records'] ?? 0)) !== 0) {
                throw new RuntimeException('Post-backfill semantic parity failed.');
            }
            if ((int) (($post['counts']['unexplained_persisted_drift_records'] ?? 0)) !== 0) {
                throw new RuntimeException('Post-backfill validation found unexplained persisted drift.');
            }
            if ($requireLiveReadiness && ($post['ready_for_live_backfill'] ?? false) !== true) {
                throw new RuntimeException('Post-backfill live-readiness validation failed.');
            }
            if (!$requireLiveReadiness && ($post['safe_to_rehearse'] ?? false) !== true) {
                throw new RuntimeException('Post-backfill rehearsal validation failed.');
            }

            $this->connection->commit();

            $analysis['mode'] = $mode;
            $analysis['applied_count'] = count($candidates);
            $analysis['post_analysis'] = $post;
            $analysis['committed'] = true;
            $analysis['candidate_ids'] = $candidateIds;

            return $analysis;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    private function loadLegacyRecords(?int $limit): array
    {
        $sql = 'SELECT ar.*, u.name AS employee_name, u.staff_code, u.overtime_eligible
                FROM attendance_records ar
                JOIN users u ON u.id = ar.user_id
                ORDER BY ar.id ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit);
        }

        return $this->connection->query($sql)->fetchAll();
    }

    /**
     * @param list<array{employee_id:int,business_date:string}> $requests
     * @return array{0:array<string,array<string,mixed>>,1:array<string,string>}
     */
    private function resolveRequests(array $requests): array
    {
        if ($requests === []) {
            return [[], []];
        }

        try {
            return [$this->resolver->resolveMany($requests), []];
        } catch (Throwable) {
            // Fall back to individual resolution so the report identifies the
            // exact historical rows that need manual review.
        }

        $resolved = [];
        $errors = [];
        foreach ($requests as $request) {
            $employeeId = (int) $request['employee_id'];
            $businessDate = (string) $request['business_date'];
            $key = $this->resolutionKey($employeeId, $businessDate);
            if (isset($resolved[$key]) || isset($errors[$key])) {
                continue;
            }
            try {
                $resolved[$key] = $this->resolver->resolve($employeeId, $businessDate);
            } catch (Throwable $exception) {
                $errors[$key] = $exception->getMessage();
            }
        }

        return [$resolved, $errors];
    }

    /**
     * @param array<string, mixed> $persisted
     * @param array<string, mixed> $calculated
     * @param array<string, mixed> $plan
     * @return array<string, array{persisted:mixed,session:mixed}>
     */
    private function persistedMismatches(array $persisted, array $calculated, array $plan): array
    {
        $mismatches = $this->compareMaps($persisted, $calculated, self::PERSISTED_COMPARISON_KEYS, true);

        foreach ([
            'check_in_at' => $plan['check_in_at'] ?? null,
            'check_out_at' => $plan['check_out_at'] ?? null,
            'clock_in_time' => $this->timePart($plan['check_in_at'] ?? null),
            'clock_out_time' => $this->timePart($plan['check_out_at'] ?? null),
        ] as $key => $sessionValue) {
            $persistedValue = $persisted[$key] ?? null;
            if ($persistedValue === null || trim((string) $persistedValue) === '') {
                continue;
            }
            if (trim((string) $persistedValue) !== trim((string) $sessionValue)) {
                $mismatches[$key] = [
                    'persisted' => $persistedValue,
                    'session' => $sessionValue,
                ];
            }
        }

        if ((int) ($calculated['is_early_departure'] ?? 0) !== 1) {
            foreach (['early_departure_reason', 'early_departure_note'] as $contextKey) {
                $persistedValue = $persisted[$contextKey] ?? null;
                if ($persistedValue !== null && trim((string) $persistedValue) !== '') {
                    $mismatches[$contextKey] = [
                        'persisted' => $persistedValue,
                        'session' => null,
                    ];
                }
            }
        }

        return $mismatches;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @param list<string> $keys
     * @return array<string, array{persisted:mixed,session:mixed}>
     */
    private function compareMaps(array $left, array $right, array $keys, bool $skipNullLeft): array
    {
        $mismatches = [];
        foreach ($keys as $key) {
            $leftValue = $left[$key] ?? null;
            $rightValue = $right[$key] ?? null;
            if ($skipNullLeft && $leftValue === null) {
                continue;
            }
            if (!$this->valuesEquivalent($leftValue, $rightValue, $key === 'working_hours')) {
                $mismatches[$key] = [
                    'persisted' => $leftValue,
                    'session' => $rightValue,
                ];
            }
        }

        return $mismatches;
    }

    private function valuesEquivalent(mixed $left, mixed $right, bool $floatTolerance): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }
        if ($floatTolerance && is_numeric($left) && is_numeric($right)) {
            return abs((float) $left - (float) $right) <= 0.011;
        }
        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left === (float) $right;
        }

        return (string) $left === (string) $right;
    }

    /** @param list<array<string, mixed>> $items @return array<string, int> */
    private function countItems(array $items): array
    {
        $counts = [
            'candidate' => 0,
            'already_backfilled' => 0,
            'no_physical_session' => 0,
            'skipped_incomplete' => 0,
            'manual_review' => 0,
            'existing_session_conflict' => 0,
            'resolution_errors' => 0,
            'semantic_mismatch_records' => 0,
        ];
        foreach ($items as $item) {
            $classification = (string) ($item['classification'] ?? 'manual_review');
            if (array_key_exists($classification, $counts)) {
                $counts[$classification]++;
            } else {
                $counts['manual_review']++;
            }
            if (!empty($item['resolution_error'])) {
                $counts['resolution_errors']++;
            }
            if (!empty($item['semantic_mismatches'])) {
                $counts['semantic_mismatch_records']++;
            }
        }

        return array_replace($counts, $this->validator->countPersistedDrift($items));
    }

    /** @param array<string, mixed> $resolution */
    private function timezoneForResolution(array $resolution): DateTimeZone
    {
        $name = trim((string) ($resolution['timezone'] ?? ''));
        if ($name !== '' && in_array($name, DateTimeZone::listIdentifiers(), true)) {
            return new DateTimeZone($name);
        }

        return new DateTimeZone(date_default_timezone_get());
    }

    private function resolutionKey(int $employeeId, string $businessDate): string
    {
        return $employeeId . '|' . $businessDate;
    }

    private function databaseName(): string
    {
        $name = $this->connection->query('SELECT DATABASE()')->fetchColumn();
        return $name === false ? '' : (string) $name;
    }

    private function timePart(mixed $dateTime): ?string
    {
        $value = trim((string) $dateTime);
        return $value === '' || strlen($value) < 19 ? null : substr($value, 11, 8);
    }

    /** @param list<int> $attendanceIds @return array<int, array<string, mixed>> */
    private function snapshotParents(array $attendanceIds): array
    {
        return $this->snapshotRows('attendance_records', 'id', $attendanceIds);
    }

    /** @param list<int> $attendanceIds @return list<array<string, mixed>> */
    private function snapshotOvertimeDecisions(array $attendanceIds): array
    {
        if ($attendanceIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($attendanceIds), '?'));
        $statement = $this->connection->prepare(
            'SELECT * FROM overtime_decisions WHERE attendance_id IN (' . $placeholders . ') ORDER BY id ASC'
        );
        $statement->execute($attendanceIds);
        return $statement->fetchAll();
    }

    /** @param list<int> $ids @return array<int, array<string, mixed>> */
    private function snapshotRows(string $table, string $idColumn, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->connection->prepare(
            sprintf('SELECT * FROM %s WHERE %s IN (%s) ORDER BY %s ASC', $table, $idColumn, $placeholders, $idColumn)
        );
        $statement->execute($ids);
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[(int) $row[$idColumn]] = $row;
        }
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function snapshotAllRows(string $table): array
    {
        return $this->connection->query('SELECT * FROM ' . $table . ' ORDER BY id ASC')->fetchAll();
    }

    private function lockRows(string $table): void
    {
        $statement = $this->connection->query('SELECT id FROM ' . $table . ' ORDER BY id ASC FOR UPDATE');
        $statement->fetchAll();
    }

    private function tableCount(string $table): int
    {
        return (int) $this->connection->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
