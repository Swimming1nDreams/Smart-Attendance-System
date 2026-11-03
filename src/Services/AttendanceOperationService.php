<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AttendanceSession;
use App\Models\Notification;
use PDO;
use PDOException;
use Throwable;

final class AttendanceOperationService
{
    public const EARLY_DEPARTURE_REASONS = [
        'medical',
        'personal_emergency',
        'family_emergency',
        'supervisor_approved',
        'official_outside_work',
        'appointment',
        'transportation',
        'other_authorized',
    ];

    public function __construct(
        private PDO $connection,
        private AttendancePolicyService $policy,
        private ?AuditLogService $auditLog = null,
        private ?WorkScheduleResolverService $resolver = null,
        private ?AttendanceCalculationService $calculator = null,
        private ?Notification $notifications = null,
        private ?AttendanceSession $attendanceSessions = null,
    ) {
        $this->calculator ??= new AttendanceCalculationService();
    }

    /**
     * Build a lightweight optimistic-lock token from the record state an admin
     * reviewed before submitting an edit.
     *
     * @param array<string, mixed> $record
     */
    public static function freshnessToken(array $record): string
    {
        $fields = [
            'id',
            'user_id',
            'attendance_date',
            'clock_in_time',
            'clock_out_time',
            'working_hours',
            'status',
            'late_minutes',
            'remarks',
            'attendance_state',
            'is_missing_check_out',
            'regular_minutes',
            'early_departure_minutes',
            'early_departure_reason',
            'early_departure_note',
            'calculated_overtime_minutes',
            'updated_at',
        ];
        $payload = [];
        foreach ($fields as $field) {
            $payload[$field] = array_key_exists($field, $record) && $record[$field] !== null
                ? (string) $record[$field]
                : null;
        }

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function clockIn(int $userId, string $attendanceDate, string $clockInTime, ?int $actorUserId = null, array $auditContext = []): void
    {
        if ($this->resolver === null || $this->attendanceSessions === null) {
            $this->legacyClockIn($userId, $attendanceDate, $clockInTime, $actorUserId, $auditContext);
            return;
        }

        $this->transactional('clock_in_session', function () use ($userId, $attendanceDate, $clockInTime, $actorUserId, $auditContext): void {
            $employee = $this->lockEmployee($userId, true);
            if ($actorUserId === $userId) {
                $this->assertEmployeeProfileExists($userId, true);
            }
            $calculation = $this->deriveAuthoritativeValues($employee, $attendanceDate, $clockInTime, null, 'present');
            $status = (string) $calculation['status'];
            $lateMinutes = (int) $calculation['late_minutes'];
            if ($this->hasApprovedLeave($userId, $attendanceDate, $attendanceDate, true)) {
                throw new AttendanceOperationException('approved_leave_conflict');
            }

            $resolution = $this->resolver->resolve($userId, $attendanceDate, true);
            $specialDayBlockReason = $this->specialDayBlockReason($resolution);
            if ($specialDayBlockReason !== null) {
                throw new AttendanceOperationException($specialDayBlockReason);
            }

            $record = $this->lockAttendanceByUserAndDate($userId, $attendanceDate);
            $checkInAt = $this->combineBusinessDateTime($attendanceDate, $clockInTime);
            $asOf = $this->sessionAsOf($checkInAt, $resolution, 0);

            $openSession = $this->attendanceSessions->findOpenForEmployee($userId, true);
            if ($openSession !== null && !$this->isSessionStale($openSession, $userId, $asOf)) {
                throw new AttendanceOperationException('already_clocked_in');
            }

            if ($record !== null
                && $this->attendanceSessions->countByAttendanceId((int) $record['id']) === 0
                && !empty($record['clock_in_time'])) {
                // A legacy-only physical interval cannot safely be mixed with a
                // new child session until it has been reconciled/backfilled.
                throw new AttendanceOperationException('already_clocked_in');
            }

            if ($record === null) {
                $this->insertAttendance($userId, $attendanceDate, $clockInTime, null, 0.0, $status, $lateMinutes, null, $calculation);
                $record = $this->lockAttendanceById((int) $this->connection->lastInsertId());
                if ($record === null) {
                    throw new AttendanceOperationException('concurrent_update');
                }
            }

            $sessionId = $this->attendanceSessions->create((int) $record['id'], $checkInAt);
            $sessions = $this->attendanceSessions->findByAttendanceId((int) $record['id'], true);
            $asOf = $this->sessionAsOf($checkInAt, $resolution, 1);
            $calculation = $this->calculateSessionSummary($record, $employee, $resolution, $sessions, $asOf);
            $calculation = $this->applySessionOvertimeReviewState($record, $calculation, $resolution, (int) $record['id']);
            $this->persistSessionSummary($record, $calculation, null, null);
            $this->notifyPendingOvertime((int) $record['id'], $calculation);

            $this->auditSelfAttendance($actorUserId ?? $userId, 'attendance.checked_in', (int) $record['id'], 'Employee checked in', [
                'clock_in_time' => $record['clock_in_time'],
                'clock_out_time' => $record['clock_out_time'],
                'status' => $record['status'],
                'late_minutes' => (int) $record['late_minutes'],
            ], [
                'user_id' => $userId,
                'attendance_date' => $attendanceDate,
                'session_id' => $sessionId,
                'session_count' => (int) ($calculation['session_count'] ?? 0),
                'clock_in_time' => $calculation['clock_in_time'] ?? $clockInTime,
                'clock_out_time' => $calculation['clock_out_time'] ?? null,
                'status' => $calculation['status'] ?? null,
                'late_minutes' => (int) ($calculation['late_minutes'] ?? 0),
                'attendance_state' => $calculation['attendance_state'] ?? null,
                'is_missing_check_out' => $calculation['is_missing_check_out'] ?? null,
            ], $auditContext);
        });
    }

    /** @return array<string, mixed> */
    public function clockOut(
        int $userId,
        string $attendanceDate,
        string $clockOutTime,
        ?int $actorUserId = null,
        array $auditContext = [],
        ?string $earlyDepartureReason = null,
        ?string $earlyDepartureNote = null,
    ): array {
        if ($this->resolver === null || $this->attendanceSessions === null) {
            return $this->legacyClockOut(
                $userId,
                $attendanceDate,
                $clockOutTime,
                $actorUserId,
                $auditContext,
                $earlyDepartureReason,
                $earlyDepartureNote,
            );
        }

        return $this->transactional('clock_out_session', function () use ($userId, $attendanceDate, $clockOutTime, $actorUserId, $auditContext, $earlyDepartureReason, $earlyDepartureNote): array {
            $employee = $this->lockEmployee($userId, true);
            if ($actorUserId === $userId) {
                $this->assertEmployeeProfileExists($userId, true);
            }

            // We need resolution to determine timezone for the action's asOf
            $resolution = $this->resolver->resolve($userId, $attendanceDate, true);
            $actionDateTime = $attendanceDate . ' ' . substr($clockOutTime, 0, 8);
            $asOf = $this->sessionAsOf($actionDateTime, $resolution, 0);

            $openSession = $this->attendanceSessions->findOpenForEmployee($userId, true);
            if ($openSession !== null && $this->isSessionStale($openSession, $userId, $asOf)) {
                $openSession = null;
            }
            
            if ($openSession === null) {
                $legacyRecord = $this->lockAttendanceByUserAndDate($userId, $attendanceDate);
                if ($legacyRecord === null || empty($legacyRecord['clock_in_time'])) {
                    throw new AttendanceOperationException('clock_in_required');
                }
                if (!empty($legacyRecord['clock_out_time'])) {
                    throw new AttendanceOperationException('already_clocked_out');
                }
                if ($this->attendanceSessions->countByAttendanceId((int) $legacyRecord['id']) > 0) {
                    throw new AttendanceOperationException('already_clocked_out');
                }

                $legacyCheckInAt = trim((string) ($legacyRecord['check_in_at'] ?? ''));
                if ($legacyCheckInAt === '') {
                    $legacyCheckInAt = $this->combineBusinessDateTime(
                        (string) $legacyRecord['attendance_date'],
                        (string) $legacyRecord['clock_in_time'],
                    );
                }
                $sessionId = $this->attendanceSessions->create((int) $legacyRecord['id'], $legacyCheckInAt);
                $openSession = [
                    'id' => $sessionId,
                    'attendance_id' => (int) $legacyRecord['id'],
                    'check_in_at' => $legacyCheckInAt,
                    'check_out_at' => null,
                    'user_id' => $userId,
                    'attendance_date' => (string) $legacyRecord['attendance_date'],
                ];
            }

            $record = $this->lockAttendanceById((int) $openSession['attendance_id']);
            if ($record === null || (int) $record['user_id'] !== $userId) {
                throw new AttendanceOperationException('clock_in_required');
            }

            $businessDate = (string) $record['attendance_date'];
            // resolution was already computed above
            $checkOutAt = $this->resolveSessionCheckOutAt(
                $businessDate,
                $attendanceDate,
                (string) $openSession['check_in_at'],
                $clockOutTime,
                $resolution,
            );

            $sessions = $this->attendanceSessions->findByAttendanceId((int) $record['id'], true);
            $proposed = [];
            foreach ($sessions as $session) {
                if ((int) $session['id'] === (int) $openSession['id']) {
                    $session['check_out_at'] = $checkOutAt;
                }
                $proposed[] = $session;
            }
            $checkOutAsOf = $this->sessionAsOf($checkOutAt, $resolution);
            $calculation = $this->calculateSessionSummary($record, $employee, $resolution, $proposed, $checkOutAsOf);
            [$earlyDepartureReason, $earlyDepartureNote] = $this->validatedEarlyDepartureContext(
                $calculation,
                $earlyDepartureReason,
                $earlyDepartureNote,
            );

            if (!$this->attendanceSessions->close((int) $openSession['id'], $checkOutAt)) {
                throw new AttendanceOperationException('concurrent_update');
            }

            $calculation = $this->applySessionOvertimeReviewState($record, $calculation, $resolution, (int) $record['id']);
            $this->persistSessionSummary($record, $calculation, $earlyDepartureReason, $earlyDepartureNote);
            $this->notifyPendingOvertime((int) $record['id'], $calculation);

            $this->auditSelfAttendance($actorUserId ?? $userId, 'attendance.checked_out', (int) $record['id'], 'Employee checked out', [
                'clock_in_time' => $record['clock_in_time'],
                'clock_out_time' => $record['clock_out_time'],
                'working_hours' => $record['working_hours'],
                'status' => $record['status'],
                'late_minutes' => (int) $record['late_minutes'],
                'attendance_state' => $record['attendance_state'] ?? null,
                'is_missing_check_out' => $record['is_missing_check_out'] ?? null,
            ], [
                'user_id' => $userId,
                'attendance_date' => $businessDate,
                'session_id' => (int) $openSession['id'],
                'session_count' => (int) ($calculation['session_count'] ?? 0),
                'clock_in_time' => $calculation['clock_in_time'] ?? null,
                'clock_out_time' => $calculation['clock_out_time'] ?? $clockOutTime,
                'working_hours' => (float) ($calculation['working_hours'] ?? 0),
                'status' => $calculation['status'] ?? null,
                'late_minutes' => (int) ($calculation['late_minutes'] ?? 0),
                'attendance_state' => $calculation['attendance_state'] ?? null,
                'is_missing_check_out' => $calculation['is_missing_check_out'] ?? null,
                'regular_minutes' => $calculation['regular_minutes'] ?? null,
                'early_departure_minutes' => $calculation['early_departure_minutes'] ?? null,
                'early_departure_reason' => $earlyDepartureReason,
                'early_departure_note' => $earlyDepartureNote,
                'calculated_overtime_minutes' => $calculation['calculated_overtime_minutes'] ?? null,
            ], $auditContext);

            $calculation['early_departure_reason'] = $earlyDepartureReason;
            $calculation['early_departure_note'] = $earlyDepartureNote;

            return $calculation;
        });
    }

    /** @return array<string, mixed>|null */
    public function previewClockOut(int $userId, string $attendanceDate, string $clockOutTime): ?array
    {
        if ($this->resolver === null || $this->attendanceSessions === null) {
            return $this->legacyPreviewClockOut($userId, $attendanceDate, $clockOutTime);
        }

        $resolution = $this->resolver->resolve($userId, $attendanceDate, false);
        $actionDateTime = $attendanceDate . ' ' . substr($clockOutTime, 0, 8);
        $asOf = $this->sessionAsOf($actionDateTime, $resolution, 0);

        $openSession = $this->attendanceSessions->findOpenForEmployee($userId, false);
        if ($openSession !== null && $this->isSessionStale($openSession, $userId, $asOf)) {
            $openSession = null;
        }
        
        if ($openSession === null) {
            return $this->legacyPreviewClockOut($userId, $attendanceDate, $clockOutTime);
        }

        $record = $this->findAttendanceById((int) $openSession['attendance_id']);
        if ($record === null) {
            return null;
        }
        $employee = $this->findActiveEmployee($userId);
        if ($employee === null) {
            return null;
        }

        $businessDate = (string) $record['attendance_date'];
        $resolution = $this->resolver->resolve($userId, $businessDate, false);
        $checkOutAt = $this->resolveSessionCheckOutAt(
            $businessDate,
            $attendanceDate,
            (string) $openSession['check_in_at'],
            $clockOutTime,
            $resolution,
        );
        $sessions = $this->attendanceSessions->findByAttendanceId((int) $record['id']);
        $proposed = [];
        foreach ($sessions as $session) {
            if ((int) $session['id'] === (int) $openSession['id']) {
                $session['check_out_at'] = $checkOutAt;
            }
            $proposed[] = $session;
        }

        return $this->calculateSessionSummary(
            $record,
            $employee,
            $resolution,
            $proposed,
            $this->sessionAsOf($checkOutAt, $resolution),
        );
    }

    private function legacyClockIn(int $userId, string $attendanceDate, string $clockInTime, ?int $actorUserId = null, array $auditContext = []): void
    {
        $this->transactional('clock_in', function () use ($userId, $attendanceDate, $clockInTime, $actorUserId, $auditContext): void {
            $employee = $this->lockEmployee($userId, true);
            if ($actorUserId === $userId) {
                $this->assertEmployeeProfileExists($userId, true);
            }
            $calculation = $this->deriveAuthoritativeValues($employee, $attendanceDate, $clockInTime, null, 'present');
            $status = (string) $calculation['status'];

            if ($this->hasApprovedLeave($userId, $attendanceDate, $attendanceDate, true)) {
                throw new AttendanceOperationException('approved_leave_conflict');
            }

            $record = $this->lockAttendanceByUserAndDate($userId, $attendanceDate);
            if ($record !== null && $record['clock_in_time'] !== null) {
                throw new AttendanceOperationException('already_clocked_in');
            }

            $lateMinutes = (int) $calculation['late_minutes'];
            if ($record === null) {
                $this->insertAttendance($userId, $attendanceDate, $clockInTime, null, 0.0, $status, $lateMinutes, null, $calculation);
                $id = (int) $this->connection->lastInsertId();
                $this->auditSelfAttendance($actorUserId ?? $userId, 'attendance.checked_in', $id, 'Employee checked in', null, [
                    'user_id' => $userId,
                    'attendance_date' => $attendanceDate,
                    'clock_in_time' => $clockInTime,
                    'status' => $status,
                    'late_minutes' => $lateMinutes,
                    'attendance_state' => $calculation['attendance_state'] ?? null,
                    'is_missing_check_out' => $calculation['is_missing_check_out'] ?? null,
                ], $auditContext);
                return;
            }

            $statement = $this->connection->prepare(
                'UPDATE attendance_records
                 SET clock_in_time = :clock_in_time,
                     status = :status,
                     late_minutes = :late_minutes,
                     working_hours = 0,
                     check_in_at = :check_in_at,
                     check_out_at = NULL,
                     business_date = :business_date,
                     day_type = :day_type,
                     attendance_state = :attendance_state,
                     is_late = :is_late,
                     is_early_departure = :is_early_departure,
                     is_missing_check_out = :is_missing_check_out,
                     is_manual_override = :is_manual_override,
                     is_overnight_attendance = :is_overnight_attendance,
                     regular_minutes = :regular_minutes,
                     early_departure_minutes = :early_departure_minutes,
                     early_departure_reason = NULL,
                     early_departure_note = NULL,
                     early_overtime_minutes = :early_overtime_minutes,
                     after_hours_overtime_minutes = :after_hours_overtime_minutes,
                     non_working_day_overtime_minutes = :non_working_day_overtime_minutes,
                     holiday_overtime_minutes = :holiday_overtime_minutes,
                     raw_calculated_overtime_minutes = :raw_calculated_overtime_minutes,
                     calculated_overtime_minutes = :calculated_overtime_minutes,
                     approved_overtime_minutes = :approved_overtime_minutes,
                     overtime_approval_status = :overtime_approval_status,
                     resolved_policy_version_id = :resolved_policy_version_id,
                     resolved_assignment_id = :resolved_assignment_id,
                     calculation_version = :calculation_version,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND clock_in_time IS NULL'
            );
            $params = $this->v3Params($calculation) + [
                ':clock_in_time' => $clockInTime,
                ':status' => $status,
                ':late_minutes' => $lateMinutes,
                ':id' => (int) $record['id'],
            ];
            $statement->execute($params);
            if ($statement->rowCount() !== 1) {
                throw new AttendanceOperationException('concurrent_update');
            }
            $this->notifyPendingOvertime((int) $record['id'], $calculation);
            $this->auditSelfAttendance($actorUserId ?? $userId, 'attendance.checked_in', (int) $record['id'], 'Employee checked in', [
                'clock_in_time' => $record['clock_in_time'],
                'status' => $record['status'],
                'late_minutes' => (int) $record['late_minutes'],
            ], [
                'user_id' => $userId,
                'attendance_date' => $attendanceDate,
                'clock_in_time' => $clockInTime,
                'status' => $status,
                'late_minutes' => $lateMinutes,
                'attendance_state' => $calculation['attendance_state'] ?? null,
                'is_missing_check_out' => $calculation['is_missing_check_out'] ?? null,
            ], $auditContext);
        });
    }

    /** @return array<string, mixed> */
    private function legacyClockOut(
        int $userId,
        string $attendanceDate,
        string $clockOutTime,
        ?int $actorUserId = null,
        array $auditContext = [],
        ?string $earlyDepartureReason = null,
        ?string $earlyDepartureNote = null,
    ): array
    {
        return $this->transactional('clock_out', function () use ($userId, $attendanceDate, $clockOutTime, $actorUserId, $auditContext, $earlyDepartureReason, $earlyDepartureNote): array {
            $employee = $this->lockEmployee($userId, true);
            if ($actorUserId === $userId) {
                $this->assertEmployeeProfileExists($userId, true);
            }
            $record = $this->lockAttendanceByUserAndDate($userId, $attendanceDate);
            if ($record === null || $record['clock_in_time'] === null) {
                throw new AttendanceOperationException('clock_in_required');
            }
            if ($record['clock_out_time'] !== null) {
                throw new AttendanceOperationException('already_clocked_out');
            }

            $calculation = $this->deriveAuthoritativeValues($employee, $attendanceDate, (string) $record['clock_in_time'], $clockOutTime, (string) $record['status']);
            [$earlyDepartureReason, $earlyDepartureNote] = $this->validatedEarlyDepartureContext(
                $calculation,
                $earlyDepartureReason,
                $earlyDepartureNote,
            );
            $status = (string) $calculation['status'];
            $lateMinutes = (int) $calculation['late_minutes'];
            $statement = $this->connection->prepare(
                'UPDATE attendance_records
                 SET clock_out_time = :clock_out_time,
                     working_hours = :working_hours,
                     status = :status,
                     late_minutes = :late_minutes,
                     check_in_at = :check_in_at,
                     check_out_at = :check_out_at,
                     business_date = :business_date,
                     day_type = :day_type,
                     attendance_state = :attendance_state,
                     is_late = :is_late,
                     is_early_departure = :is_early_departure,
                     is_missing_check_out = :is_missing_check_out,
                     is_manual_override = :is_manual_override,
                     is_overnight_attendance = :is_overnight_attendance,
                     regular_minutes = :regular_minutes,
                     early_departure_minutes = :early_departure_minutes,
                     early_departure_reason = :early_departure_reason,
                     early_departure_note = :early_departure_note,
                     early_overtime_minutes = :early_overtime_minutes,
                     after_hours_overtime_minutes = :after_hours_overtime_minutes,
                     non_working_day_overtime_minutes = :non_working_day_overtime_minutes,
                     holiday_overtime_minutes = :holiday_overtime_minutes,
                     raw_calculated_overtime_minutes = :raw_calculated_overtime_minutes,
                     calculated_overtime_minutes = :calculated_overtime_minutes,
                     approved_overtime_minutes = :approved_overtime_minutes,
                     overtime_approval_status = :overtime_approval_status,
                     resolved_policy_version_id = :resolved_policy_version_id,
                     resolved_assignment_id = :resolved_assignment_id,
                     calculation_version = :calculation_version,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND clock_out_time IS NULL'
            );
            $params = $this->v3Params($calculation) + [
                ':clock_out_time' => $clockOutTime,
                ':working_hours' => (float) $calculation['working_hours'],
                ':status' => $status,
                ':late_minutes' => $lateMinutes,
                ':early_departure_reason' => $earlyDepartureReason,
                ':early_departure_note' => $earlyDepartureNote,
                ':id' => (int) $record['id'],
            ];
            $statement->execute($params);
            if ($statement->rowCount() !== 1) {
                throw new AttendanceOperationException('concurrent_update');
            }
            $this->auditSelfAttendance($actorUserId ?? $userId, 'attendance.checked_out', (int) $record['id'], 'Employee checked out', [
                'clock_in_time' => $record['clock_in_time'],
                'clock_out_time' => $record['clock_out_time'],
                'working_hours' => $record['working_hours'],
                'status' => $record['status'],
                'late_minutes' => (int) $record['late_minutes'],
                'attendance_state' => $record['attendance_state'] ?? null,
                'is_missing_check_out' => $record['is_missing_check_out'] ?? null,
            ], [
                'user_id' => $userId,
                'attendance_date' => $attendanceDate,
                'clock_in_time' => $record['clock_in_time'],
                'clock_out_time' => $clockOutTime,
                'working_hours' => (float) $calculation['working_hours'],
                'status' => $status,
                'late_minutes' => $lateMinutes,
                'attendance_state' => $calculation['attendance_state'] ?? null,
                'is_missing_check_out' => $calculation['is_missing_check_out'] ?? null,
                'regular_minutes' => $calculation['regular_minutes'] ?? null,
                'early_departure_minutes' => $calculation['early_departure_minutes'] ?? null,
                'early_departure_reason' => $earlyDepartureReason,
                'early_departure_note' => $earlyDepartureNote,
                'calculated_overtime_minutes' => $calculation['calculated_overtime_minutes'] ?? null,
            ], $auditContext);

            $calculation['early_departure_reason'] = $earlyDepartureReason;
            $calculation['early_departure_note'] = $earlyDepartureNote;

            return $calculation;
        });
    }

    /** @return array<string, mixed>|null */
    private function legacyPreviewClockOut(int $userId, string $attendanceDate, string $clockOutTime): ?array
    {
        if ($this->resolver === null) {
            return null;
        }

        $employeeStatement = $this->connection->prepare(
            "SELECT id, role, status, department_id, expected_daily_work_hours, overtime_eligible
             FROM users WHERE id = :id AND role = 'employee' AND status = 'active' LIMIT 1"
        );
        $employeeStatement->execute([':id' => $userId]);
        $employee = $employeeStatement->fetch();
        if ($employee === false) {
            return null;
        }

        $recordStatement = $this->connection->prepare(
            'SELECT clock_in_time, clock_out_time, status FROM attendance_records
             WHERE user_id = :user_id AND attendance_date = :attendance_date LIMIT 1'
        );
        $recordStatement->execute([':user_id' => $userId, ':attendance_date' => $attendanceDate]);
        $record = $recordStatement->fetch();
        if ($record === false || empty($record['clock_in_time']) || !empty($record['clock_out_time'])) {
            return null;
        }

        return $this->deriveAuthoritativeValues(
            $employee,
            $attendanceDate,
            (string) $record['clock_in_time'],
            $clockOutTime,
            (string) $record['status'],
            false,
        );
    }

    public function hasOpenSessionForEmployee(int $userId, ?\DateTimeImmutable $asOf = null): ?bool
    {
        if ($this->resolver === null || $this->attendanceSessions === null) {
            return null;
        }

        $session = $this->attendanceSessions->findOpenForEmployee($userId, false);
        if ($session === null) {
            return false;
        }

        return !$this->isSessionStale($session, $userId, $asOf);
    }

    public function selfClockInBlockReason(int $userId, string $attendanceDate, ?array $todayRecord = null, bool $hasApprovedLeave = false): ?string
    {
        if (!$this->isActiveEmployee($userId)) {
            return 'employee_invalid';
        }

        if (!$this->employeeProfileExists($userId, false)) {
            return 'employee_profile_missing';
        }

        if ($this->attendanceSessions !== null && $this->resolver !== null) {
            $openSession = $this->attendanceSessions->findOpenForEmployee($userId);
            if ($openSession !== null && !$this->isSessionStale($openSession, $userId)) {
                return 'already_clocked_in';
            }

            if ($todayRecord !== null
                && !empty($todayRecord['clock_in_time'])
                && $this->attendanceSessions->countByAttendanceId((int) ($todayRecord['id'] ?? 0)) === 0) {
                return 'already_clocked_in';
            }
        } elseif ($todayRecord !== null && !empty($todayRecord['clock_in_time'])) {
            return 'already_clocked_in';
        }

        if ($hasApprovedLeave || $this->hasApprovedLeave($userId, $attendanceDate, $attendanceDate, false)) {
            return 'approved_leave_conflict';
        }

        if ($this->resolver === null) {
            return null;
        }

        try {
            $resolution = $this->resolver->resolve($userId, $attendanceDate, false);
        } catch (AttendanceOperationException $exception) {
            return $exception->reason();
        }

        return $this->specialDayBlockReason($resolution);
    }

    /** @return array<string, mixed>|null */
    private function findAttendanceById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM attendance_records WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $record = $statement->fetch();

        return $record === false ? null : $record;
    }

    /** @return array<string, mixed>|null */
    private function findActiveEmployee(int $userId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT id, role, status, department_id, expected_daily_work_hours, overtime_eligible
             FROM users WHERE id = :id AND role = 'employee' AND status = 'active' LIMIT 1"
        );
        $statement->execute([':id' => $userId]);
        $employee = $statement->fetch();

        return $employee === false ? null : $employee;
    }

    /** @param array<string, mixed> $session */
    private function isSessionStale(array $session, int $userId, ?\DateTimeImmutable $asOf = null): bool
    {
        if ($this->resolver === null) {
            return false;
        }

        try {
            $resolution = $this->resolver->resolve($userId, (string) $session['attendance_date'], false);
            $maximumMinutes = max(1, min(1440, (int) (($resolution['attendance_policy'] ?? [])['maximum_attendance_minutes'] ?? 960)));
            
            $timezoneName = trim((string) ($resolution['timezone'] ?? ''));
            $timezone = $timezoneName !== '' && in_array($timezoneName, \DateTimeZone::listIdentifiers(), true)
                ? new \DateTimeZone($timezoneName)
                : new \DateTimeZone(date_default_timezone_get());
                
            $checkIn = new \DateTimeImmutable((string) $session['check_in_at'], $timezone);
            $staleThreshold = $checkIn->modify('+' . $maximumMinutes . ' minutes');
            
            $compareTime = $asOf ?? new \DateTimeImmutable('now', $timezone);
            return $compareTime >= $staleThreshold;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $resolution */
    private function sessionAsOf(string $timestamp, array $resolution, int $seconds = 0): \DateTimeImmutable
    {
        $timezoneName = trim((string) ($resolution['timezone'] ?? ''));
        $timezone = $timezoneName !== '' && in_array($timezoneName, \DateTimeZone::listIdentifiers(), true)
            ? new \DateTimeZone($timezoneName)
            : new \DateTimeZone(date_default_timezone_get());
        $asOf = new \DateTimeImmutable($timestamp, $timezone);

        return $seconds === 0 ? $asOf : $asOf->modify('+' . $seconds . ' seconds');
    }

    /** @param array<string, mixed> $resolution */
    private function resolveSessionCheckOutAt(
        string $businessDate,
        string $actionDate,
        string $checkInAt,
        string $clockOutTime,
        array $resolution,
    ): string {
        $timezoneName = trim((string) ($resolution['timezone'] ?? ''));
        $timezone = $timezoneName !== '' && in_array($timezoneName, \DateTimeZone::listIdentifiers(), true)
            ? new \DateTimeZone($timezoneName)
            : new \DateTimeZone(date_default_timezone_get());
        $checkIn = new \DateTimeImmutable($checkInAt, $timezone);
        $candidate = new \DateTimeImmutable($actionDate . ' ' . substr($clockOutTime, 0, 8), $timezone);

        if ($candidate <= $checkIn && $actionDate === $businessDate && (bool) ($resolution['is_overnight'] ?? false)) {
            $attendancePolicy = (array) ($resolution['attendance_policy'] ?? []);
            if ((int) ($attendancePolicy['allow_overnight_attendance'] ?? 0) !== 1) {
                throw new AttendanceOperationException('overnight_not_allowed');
            }
            $candidate = $candidate->modify('+1 day');
        }
        if ($candidate <= $checkIn) {
            throw new AttendanceOperationException('clock_out_before_clock_in');
        }

        return $candidate->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $employee
     * @param array<string, mixed> $resolution
     * @param list<array<string, mixed>> $sessions
     * @return array<string, mixed>
     */
    private function calculateSessionSummary(
        array $record,
        array $employee,
        array $resolution,
        array $sessions,
        ?\DateTimeImmutable $asOf = null,
    ): array {
        try {
            $calculation = $this->calculator->calculateFromSessions(
                $resolution,
                $employee,
                $sessions,
                (int) ($record['is_manual_override'] ?? 0) === 1,
                $asOf,
            );
        } catch (AttendancePolicyException $exception) {
            throw new AttendanceOperationException($exception->getMessage());
        }

        $firstCheckInAt = trim((string) ($calculation['first_check_in_at'] ?? ''));
        $lastCheckOutAt = trim((string) ($calculation['last_check_out_at'] ?? ''));
        $hasOpenSession = (bool) ($calculation['has_open_session'] ?? false);

        $calculation['status'] = $this->legacyStatus($resolution, (string) $calculation['attendance_state']);
        $calculation['check_in_at'] = $firstCheckInAt !== '' ? $firstCheckInAt : null;
        $calculation['check_out_at'] = !$hasOpenSession && $lastCheckOutAt !== '' ? $lastCheckOutAt : null;
        $calculation['clock_in_time'] = $firstCheckInAt !== '' ? substr($firstCheckInAt, 11, 8) : null;
        $calculation['clock_out_time'] = !$hasOpenSession && $lastCheckOutAt !== '' ? substr($lastCheckOutAt, 11, 8) : null;
        $calculation['business_date'] = (string) $record['attendance_date'];
        $calculation['day_type'] = (string) $resolution['day_type'];
        $calculation['resolved_policy_version_id'] = (int) $resolution['policy_version_id'];
        $calculation['resolved_assignment_id'] = $resolution['assignment_id'] ?? null;
        $calculation['scheduled_start_at'] = $resolution['scheduled_start_at'] ?? null;
        $calculation['scheduled_end_at'] = $resolution['scheduled_end_at'] ?? null;
        $calculation['timezone'] = $resolution['timezone'] ?? null;
        $calculation['early_departure_grace_minutes'] = max(
            0,
            (int) (((array) ($resolution['attendance_policy'] ?? []))['early_departure_grace_minutes'] ?? 0),
        );

        return $calculation;
    }

    /**
     * Preserve a reviewed overtime decision when its candidate remains final and
     * unchanged. If a later session makes the day active again or changes a
     * positive approval-required candidate, reset it transactionally to pending.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $calculation
     * @param array<string, mixed> $resolution
     * @return array<string, mixed>
     */
    private function applySessionOvertimeReviewState(array $record, array $calculation, array $resolution, int $attendanceId): array
    {
        $oldStatus = trim((string) ($record['overtime_approval_status'] ?? ''));
        $reviewed = in_array($oldStatus, ['approved', 'rejected'], true);
        if (!$reviewed) {
            return $calculation;
        }

        $oldCalculated = $record['calculated_overtime_minutes'] === null
            ? null
            : (int) $record['calculated_overtime_minutes'];
        $newCalculated = ($calculation['calculated_overtime_minutes'] ?? null) === null
            ? null
            : (int) $calculation['calculated_overtime_minutes'];
        $approvalRequired = (int) (((array) ($resolution['overtime_policy'] ?? []))['approval_required'] ?? 0) === 1;
        $hasOpenSession = (bool) ($calculation['has_open_session'] ?? false);

        if ($approvalRequired && $newCalculated !== null && $newCalculated > 0 && ($hasOpenSession || $oldCalculated !== $newCalculated)) {
            $previousApproved = max(0, (int) ($record['approved_overtime_minutes'] ?? 0));
            $reason = 'System recalculation after attendance session change.';
            $this->connection->prepare(
                'INSERT INTO overtime_decisions
                 (attendance_id, decision, calculated_minutes, previous_approved_minutes, approved_minutes, reason, decided_by, decided_at)
                 VALUES (:attendance_id, :decision, :calculated_minutes, :previous_approved_minutes, 0, :reason, NULL, :decided_at)'
            )->execute([
                ':attendance_id' => $attendanceId,
                ':decision' => 'reset_to_pending',
                ':calculated_minutes' => $newCalculated,
                ':previous_approved_minutes' => $previousApproved,
                ':reason' => $reason,
                ':decided_at' => date('Y-m-d H:i:s'),
            ]);
            $this->auditLog?->record(null, 'overtime.reset_to_pending', 'attendance_record', $attendanceId, 'Overtime decision reset after attendance session recalculation', [
                'calculated_overtime_minutes' => $oldCalculated,
                'approved_overtime_minutes' => $previousApproved,
                'overtime_approval_status' => $oldStatus,
            ], [
                'calculated_overtime_minutes' => $newCalculated,
                'approved_overtime_minutes' => 0,
                'overtime_approval_status' => 'pending',
                'reason' => $reason,
            ]);
            $calculation['approved_overtime_minutes'] = 0;
            $calculation['overtime_approval_status'] = 'pending';

            return $calculation;
        }

        if (!$hasOpenSession && $oldCalculated === $newCalculated) {
            $calculation['approved_overtime_minutes'] = $record['approved_overtime_minutes'] === null
                ? null
                : (int) $record['approved_overtime_minutes'];
            $calculation['overtime_approval_status'] = $oldStatus;
        }

        return $calculation;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $calculation
     */
    private function persistSessionSummary(array $record, array $calculation, ?string $earlyDepartureReason, ?string $earlyDepartureNote): void
    {
        $hasEarlyDepartureCandidate = (int) ($calculation['is_early_departure'] ?? 0) === 1
            || ((int) ($calculation['early_departure_provisional'] ?? 0) === 1
                && (int) ($calculation['early_departure_candidate_minutes'] ?? 0) > 0);
        if (!$hasEarlyDepartureCandidate) {
            $earlyDepartureReason = null;
            $earlyDepartureNote = null;
        }

        $statement = $this->connection->prepare(
            'UPDATE attendance_records
             SET clock_in_time = :clock_in_time,
                 clock_out_time = :clock_out_time,
                 working_hours = :working_hours,
                 status = :status,
                 late_minutes = :late_minutes,
                 check_in_at = :check_in_at,
                 check_out_at = :check_out_at,
                 business_date = :business_date,
                 day_type = :day_type,
                 attendance_state = :attendance_state,
                 is_late = :is_late,
                 is_early_departure = :is_early_departure,
                 is_missing_check_out = :is_missing_check_out,
                 is_manual_override = :is_manual_override,
                 is_overnight_attendance = :is_overnight_attendance,
                 regular_minutes = :regular_minutes,
                 early_departure_minutes = :early_departure_minutes,
                 early_departure_reason = :early_departure_reason,
                 early_departure_note = :early_departure_note,
                 early_overtime_minutes = :early_overtime_minutes,
                 after_hours_overtime_minutes = :after_hours_overtime_minutes,
                 non_working_day_overtime_minutes = :non_working_day_overtime_minutes,
                 holiday_overtime_minutes = :holiday_overtime_minutes,
                 raw_calculated_overtime_minutes = :raw_calculated_overtime_minutes,
                 calculated_overtime_minutes = :calculated_overtime_minutes,
                 approved_overtime_minutes = :approved_overtime_minutes,
                 overtime_approval_status = :overtime_approval_status,
                 resolved_policy_version_id = :resolved_policy_version_id,
                 resolved_assignment_id = :resolved_assignment_id,
                 calculation_version = :calculation_version,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $params = $this->v3Params($calculation) + [
            ':clock_in_time' => $calculation['clock_in_time'] ?? null,
            ':clock_out_time' => $calculation['clock_out_time'] ?? null,
            ':working_hours' => (float) ($calculation['working_hours'] ?? 0),
            ':status' => (string) ($calculation['status'] ?? 'present'),
            ':late_minutes' => (int) ($calculation['late_minutes'] ?? 0),
            ':early_departure_reason' => $earlyDepartureReason,
            ':early_departure_note' => $earlyDepartureNote,
            ':id' => (int) $record['id'],
        ];
        $statement->execute($params);
    }

    public function createRecord(
        int $userId,
        string $attendanceDate,
        ?string $clockInTime,
        ?string $clockOutTime,
        string $requestedStatus,
        ?string $remarks,
        ?int $actorUserId = null,
        array $auditContext = [],
    ): void {
        $this->transactional('create_attendance', function () use ($userId, $attendanceDate, $clockInTime, $clockOutTime, $requestedStatus, $remarks, $actorUserId, $auditContext): void {
            $employee = $this->lockEmployee($userId, true);
            $this->assertManagerCanManageEmployee($actorUserId, $employee);
            if ($this->lockAttendanceByUserAndDate($userId, $attendanceDate) !== null) {
                throw new AttendanceOperationException('duplicate');
            }

            $calculation = $this->deriveAuthoritativeValues($employee, $attendanceDate, $clockInTime, $clockOutTime, $requestedStatus);
            $status = (string) $calculation['status'];
            $this->assertNoApprovedLeaveConflict($userId, $attendanceDate, $status);
            $workingHours = (float) $calculation['working_hours'];
            $lateMinutes = (int) $calculation['late_minutes'];
            $this->insertAttendance($userId, $attendanceDate, $clockInTime, $clockOutTime, $workingHours, $status, $lateMinutes, $remarks, $calculation);
            $id = (int) $this->connection->lastInsertId();
            $this->notifyPendingOvertime($id, $calculation);
            $this->auditLog?->record($actorUserId, 'attendance.created', 'attendance_record', $id, 'Authorized manager created attendance record', null, [
                'user_id' => $userId, 'attendance_date' => $attendanceDate, 'clock_in_time' => $clockInTime,
                'clock_out_time' => $clockOutTime, 'status' => $status, 'late_minutes' => $lateMinutes, 'remarks' => $remarks,
            ], $auditContext);
        });
    }

    public function updateRecord(
        int $id,
        int $userId,
        string $attendanceDate,
        ?string $clockInTime,
        ?string $clockOutTime,
        string $requestedStatus,
        ?string $remarks,
        ?int $actorUserId = null,
        array $auditContext = [],
        ?string $expectedFreshnessToken = null,
    ): void {
        $this->transactional('update_attendance', function () use ($id, $userId, $attendanceDate, $clockInTime, $clockOutTime, $requestedStatus, $remarks, $actorUserId, $auditContext, $expectedFreshnessToken): void {
            $current = $this->lockAttendanceById($id);
            if ($current === null) {
                throw new AttendanceOperationException('not_found');
            }
            if ($expectedFreshnessToken !== null && $expectedFreshnessToken !== '' && !hash_equals($expectedFreshnessToken, self::freshnessToken($current))) {
                throw new AttendanceOperationException('concurrent_update');
            }

            $currentEmployee = $this->lockEmployee((int) $current['user_id'], false);
            $this->assertManagerCanManageEmployee($actorUserId, $currentEmployee);
            $employee = $this->lockEmployee($userId, $userId !== (int) $current['user_id']);
            $this->assertManagerCanManageEmployee($actorUserId, $employee);
            $duplicate = $this->lockAttendanceByUserAndDate($userId, $attendanceDate);
            if ($duplicate !== null && (int) $duplicate['id'] !== $id) {
                throw new AttendanceOperationException('duplicate');
            }

            $calculation = $this->deriveAuthoritativeValues($employee, $attendanceDate, $clockInTime, $clockOutTime, $requestedStatus);
            $status = (string) $calculation['status'];
            $this->assertNoApprovedLeaveConflict($userId, $attendanceDate, $status);
            $workingHours = (float) $calculation['working_hours'];
            $lateMinutes = (int) $calculation['late_minutes'];

            $statement = $this->connection->prepare(
                'UPDATE attendance_records
                 SET user_id = :user_id,
                     attendance_date = :attendance_date,
                     clock_in_time = :clock_in_time,
                     clock_out_time = :clock_out_time,
                     working_hours = :working_hours,
                     status = :status,
                     late_minutes = :late_minutes,
                     remarks = :remarks,
                     check_in_at = :check_in_at,
                     check_out_at = :check_out_at,
                     business_date = :business_date,
                     day_type = :day_type,
                     attendance_state = :attendance_state,
                     is_late = :is_late,
                     is_early_departure = :is_early_departure,
                     is_missing_check_out = :is_missing_check_out,
                     is_manual_override = :is_manual_override,
                     is_overnight_attendance = :is_overnight_attendance,
                     regular_minutes = :regular_minutes,
                     early_departure_minutes = :early_departure_minutes,
                     early_overtime_minutes = :early_overtime_minutes,
                     after_hours_overtime_minutes = :after_hours_overtime_minutes,
                     non_working_day_overtime_minutes = :non_working_day_overtime_minutes,
                     holiday_overtime_minutes = :holiday_overtime_minutes,
                     raw_calculated_overtime_minutes = :raw_calculated_overtime_minutes,
                     calculated_overtime_minutes = :calculated_overtime_minutes,
                     approved_overtime_minutes = :approved_overtime_minutes,
                     overtime_approval_status = :overtime_approval_status,
                     resolved_policy_version_id = :resolved_policy_version_id,
                     resolved_assignment_id = :resolved_assignment_id,
                     calculation_version = :calculation_version,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $params = $this->v3Params($calculation) + [
                ':user_id' => $userId,
                ':attendance_date' => $attendanceDate,
                ':clock_in_time' => $clockInTime,
                ':clock_out_time' => $clockOutTime,
                ':working_hours' => $workingHours,
                ':status' => $status,
                ':late_minutes' => $lateMinutes,
                ':remarks' => $remarks,
                ':id' => $id,
            ];
            $statement->execute($params);
            $this->notifyPendingOvertime($id, $calculation);
            $this->auditLog?->record($actorUserId, 'attendance.updated', 'attendance_record', $id, 'Authorized manager edited attendance record', [
                'user_id' => (int) $current['user_id'], 'attendance_date' => $current['attendance_date'],
                'clock_in_time' => $current['clock_in_time'], 'clock_out_time' => $current['clock_out_time'],
                'status' => $current['status'], 'late_minutes' => (int) $current['late_minutes'], 'remarks' => $current['remarks'],
            ], [
                'user_id' => $userId, 'attendance_date' => $attendanceDate, 'clock_in_time' => $clockInTime,
                'clock_out_time' => $clockOutTime, 'status' => $status, 'late_minutes' => $lateMinutes, 'remarks' => $remarks,
            ], $auditContext);
        });
    }

    /** @return array{0: float, 1: int} */
    private function deriveStoredValues(?string $clockInTime, ?string $clockOutTime, string $status): array
    {
        try {
            $actualMinutes = $this->policy->actualWorkMinutes($clockInTime, $clockOutTime);
            $lateMinutes = $this->policy->calculateLateMinutes($clockInTime, $status);
        } catch (AttendancePolicyException $exception) {
            throw new AttendanceOperationException($exception->getMessage());
        }

        return [$this->policy->workingHours($actualMinutes), $lateMinutes];
    }

    private function insertAttendance(int $userId, string $date, ?string $clockIn, ?string $clockOut, float $hours, string $status, int $lateMinutes, ?string $remarks, array $calculation = []): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO attendance_records
             (user_id, attendance_date, clock_in_time, clock_out_time, working_hours, status, late_minutes, remarks,
              check_in_at, check_out_at, business_date, day_type, attendance_state, is_late, is_early_departure,
              is_missing_check_out, is_manual_override, is_overnight_attendance, regular_minutes, early_departure_minutes,
              early_overtime_minutes, after_hours_overtime_minutes, non_working_day_overtime_minutes, holiday_overtime_minutes,
              raw_calculated_overtime_minutes, calculated_overtime_minutes, approved_overtime_minutes, overtime_approval_status,
              resolved_policy_version_id, resolved_assignment_id, calculation_version)
             VALUES
             (:user_id, :attendance_date, :clock_in_time, :clock_out_time, :working_hours, :status, :late_minutes, :remarks,
              :check_in_at, :check_out_at, :business_date, :day_type, :attendance_state, :is_late, :is_early_departure,
              :is_missing_check_out, :is_manual_override, :is_overnight_attendance, :regular_minutes, :early_departure_minutes,
              :early_overtime_minutes, :after_hours_overtime_minutes, :non_working_day_overtime_minutes, :holiday_overtime_minutes,
              :raw_calculated_overtime_minutes, :calculated_overtime_minutes, :approved_overtime_minutes, :overtime_approval_status,
              :resolved_policy_version_id, :resolved_assignment_id, :calculation_version)'
        );
        $statement->execute($this->v3Params($calculation) + [
            ':user_id' => $userId,
            ':attendance_date' => $date,
            ':clock_in_time' => $clockIn,
            ':clock_out_time' => $clockOut,
            ':working_hours' => $hours,
            ':status' => $status,
            ':late_minutes' => $lateMinutes,
            ':remarks' => $remarks,
        ]);
    }

    private function lockEmployee(int $userId, bool $requireActive): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, role, status, department_id, expected_daily_work_hours, overtime_eligible FROM users WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $statement->execute([':id' => $userId]);
        $employee = $statement->fetch();
        if ($employee === false || $employee['role'] !== 'employee' || ($requireActive && $employee['status'] !== 'active')) {
            throw new AttendanceOperationException('employee_invalid');
        }

        return $employee;
    }

    /** @param array<string, mixed> $employee */
    private function assertManagerCanManageEmployee(?int $actorUserId, array $employee): void
    {
        // Older service-level callers omit an actor; HTTP mutation controllers always provide one.
        if ($actorUserId === null) {
            return;
        }

        $statement = $this->connection->prepare(
            'SELECT id, role, status, department_id FROM users WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $statement->execute([':id' => $actorUserId]);
        $actor = $statement->fetch();

        if ($actor === false || ($actor['status'] ?? '') !== 'active') {
            throw new AttendanceOperationException('authorization_denied');
        }

        if (($actor['role'] ?? '') === 'admin') {
            return;
        }

        $actorDepartmentId = isset($actor['department_id']) ? (int) $actor['department_id'] : 0;
        $employeeDepartmentId = isset($employee['department_id']) ? (int) $employee['department_id'] : 0;
        if (
            ($actor['role'] ?? '') === 'department_head'
            && ($employee['role'] ?? '') === 'employee'
            && (int) ($actor['id'] ?? 0) !== (int) ($employee['id'] ?? 0)
            && $actorDepartmentId > 0
            && $actorDepartmentId === $employeeDepartmentId
        ) {
            return;
        }

        throw new AttendanceOperationException('authorization_denied');
    }

    private function isActiveEmployee(int $userId): bool
    {
        $statement = $this->connection->prepare(
            "SELECT id FROM users WHERE id = :id AND role = 'employee' AND status = 'active' LIMIT 1"
        );
        $statement->execute([':id' => $userId]);

        return $statement->fetchColumn() !== false;
    }

    private function assertEmployeeProfileExists(int $userId, bool $lock): void
    {
        if (!$this->employeeProfileExists($userId, $lock)) {
            throw new AttendanceOperationException('employee_profile_missing');
        }
    }

    private function employeeProfileExists(int $userId, bool $lock): bool
    {
        $sql = 'SELECT id FROM employee_profiles WHERE user_id = :user_id LIMIT 1';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute([':user_id' => $userId]);

        return $statement->fetchColumn() !== false;
    }

    private function lockAttendanceById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM attendance_records WHERE id = :id LIMIT 1 FOR UPDATE');
        $statement->execute([':id' => $id]);
        $record = $statement->fetch();

        return $record === false ? null : $record;
    }

    private function lockAttendanceByUserAndDate(int $userId, string $date): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM attendance_records WHERE user_id = :user_id AND attendance_date = :attendance_date LIMIT 1 FOR UPDATE'
        );
        $statement->execute([':user_id' => $userId, ':attendance_date' => $date]);
        $record = $statement->fetch();

        return $record === false ? null : $record;
    }

    private function assertNoApprovedLeaveConflict(int $userId, string $date, string $status): void
    {
        if (in_array($status, ['present', 'absent'], true) && $this->hasApprovedLeave($userId, $date, $date, true)) {
            throw new AttendanceOperationException('approved_leave_conflict');
        }
    }

    private function hasApprovedLeave(int $userId, string $fromDate, string $toDate, bool $lock): bool
    {
        $sql = 'SELECT id FROM leave_requests WHERE user_id = :user_id AND status = :status AND start_date <= :to_date AND end_date >= :from_date LIMIT 1';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute([
            ':user_id' => $userId,
            ':status' => 'approved',
            ':from_date' => $fromDate,
            ':to_date' => $toDate,
        ]);

        return $statement->fetchColumn() !== false;
    }

    private function resolveStatus(string $attendanceDate, string $requestedStatus, ?string $clockInTime = null, ?string $clockOutTime = null): string
    {
        $holiday = $this->connection->prepare('SELECT id FROM holidays WHERE holiday_date = :attendance_date LIMIT 1 FOR UPDATE');
        $holiday->execute([':attendance_date' => $attendanceDate]);
        $isHoliday = $holiday->fetchColumn() !== false;

        return $this->policy->deriveStatus($attendanceDate, $requestedStatus, $clockInTime, $clockOutTime, $isHoliday);
    }

    /**
     * @param array<string, mixed> $employee
     * @return array<string, mixed>
     */
    private function deriveAuthoritativeValues(array $employee, string $attendanceDate, ?string $clockInTime, ?string $clockOutTime, string $requestedStatus, bool $lockResolution = true): array
    {
        if ($this->resolver === null) {
            $status = $this->resolveStatus($attendanceDate, $requestedStatus, $clockInTime, $clockOutTime);
            [$workingHours, $lateMinutes] = $this->deriveStoredValues($clockInTime, $clockOutTime, $status);

            return [
                'status' => $status,
                'late_minutes' => $lateMinutes,
                'working_hours' => $workingHours,
            ];
        }

        $hasClockTime = ($clockInTime !== null && $clockInTime !== '') || ($clockOutTime !== null && $clockOutTime !== '');
        if ($requestedStatus === 'present' && !$hasClockTime) {
            throw new AttendanceOperationException('present_requires_time');
        }

        $resolution = $this->resolver->resolve((int) $employee['id'], $attendanceDate, $lockResolution);
        $attendancePolicy = (array) ($resolution['attendance_policy'] ?? []);
        $specialDayBlockReason = $this->specialDayBlockReason($resolution);
        if ($hasClockTime && $specialDayBlockReason !== null) {
            throw new AttendanceOperationException($specialDayBlockReason);
        }

        $checkInAt = $clockInTime !== null && $clockInTime !== '' ? $this->combineBusinessDateTime($attendanceDate, $clockInTime) : null;
        $checkOutAt = null;
        if ($clockOutTime !== null && $clockOutTime !== '') {
            $checkOutAt = $this->combineBusinessDateTime($attendanceDate, $clockOutTime);
            if ($clockInTime !== null && $clockInTime !== '' && $clockOutTime < $clockInTime && (bool) ($resolution['is_overnight'] ?? false)) {
                if ((int) ($attendancePolicy['allow_overnight_attendance'] ?? 0) !== 1) {
                    throw new AttendanceOperationException('overnight_not_allowed');
                }
                $checkOutAt = (new \DateTimeImmutable($checkOutAt))->modify('+1 day')->format('Y-m-d H:i:s');
            }
        }

        try {
            $calculation = $this->calculator->calculate($resolution, $employee, $checkInAt, $checkOutAt);
        } catch (AttendancePolicyException $exception) {
            throw new AttendanceOperationException($exception->getMessage());
        }

        $legacyStatus = $this->legacyStatus($resolution, (string) $calculation['attendance_state']);
        $calculation['status'] = $legacyStatus;
        $calculation['check_in_at'] = $checkInAt;
        $calculation['check_out_at'] = $checkOutAt;
        $calculation['business_date'] = $attendanceDate;
        $calculation['day_type'] = (string) $resolution['day_type'];
        $calculation['resolved_policy_version_id'] = (int) $resolution['policy_version_id'];
        $calculation['resolved_assignment_id'] = $resolution['assignment_id'] ?? null;
        $calculation['scheduled_start_at'] = $resolution['scheduled_start_at'] ?? null;
        $calculation['scheduled_end_at'] = $resolution['scheduled_end_at'] ?? null;
        $calculation['timezone'] = $resolution['timezone'] ?? null;
        $calculation['early_departure_grace_minutes'] = max(
            0,
            (int) ($attendancePolicy['early_departure_grace_minutes'] ?? 0),
        );

        return $calculation;
    }

    /** @param array<string, mixed> $calculation @return array{0: ?string, 1: ?string} */
    private function validatedEarlyDepartureContext(array $calculation, ?string $reason, ?string $note): array
    {
        $earlyCandidateMinutes = max(
            (int) ($calculation['early_departure_minutes'] ?? 0),
            (int) ($calculation['early_departure_candidate_minutes'] ?? 0),
        );
        if ($earlyCandidateMinutes <= 0) {
            return [null, null];
        }

        $reason = trim((string) $reason);
        $note = trim((string) $note);
        if ($reason === '') {
            throw new AttendanceOperationException('early_departure_reason_required');
        }
        if (!in_array($reason, self::EARLY_DEPARTURE_REASONS, true)) {
            throw new AttendanceOperationException('early_departure_reason_invalid');
        }
        if (mb_strlen($note) > 500) {
            throw new AttendanceOperationException('early_departure_note_too_long');
        }
        if ($reason === 'other_authorized' && $note === '') {
            throw new AttendanceOperationException('early_departure_note_required');
        }

        return [$reason, $note === '' ? null : $note];
    }

    /** @param array<string, mixed> $resolution */
    private function legacyStatus(array $resolution, string $attendanceState): string
    {
        if (($resolution['day_type'] ?? '') === 'holiday' && in_array($attendanceState, ['present', 'incomplete'], true)) {
            return 'holiday';
        }
        if (($resolution['day_type'] ?? '') === 'non_working_day' && in_array($attendanceState, ['present', 'incomplete'], true)) {
            return 'weekend';
        }

        return $attendanceState === 'absent' ? 'absent' : 'present';
    }

    /** @param array<string, mixed> $resolution */
    private function specialDayBlockReason(array $resolution): ?string
    {
        $attendancePolicy = (array) ($resolution['attendance_policy'] ?? []);

        if (($resolution['day_type'] ?? '') === 'holiday' && (int) ($attendancePolicy['allow_holiday_check_in'] ?? 1) !== 1) {
            return 'holiday_blocked';
        }

        if (($resolution['day_type'] ?? '') === 'non_working_day' && (int) ($attendancePolicy['allow_non_working_day_check_in'] ?? 1) !== 1) {
            return 'non_working_day_blocked';
        }

        return null;
    }

    private function combineBusinessDateTime(string $attendanceDate, string $time): string
    {
        return $attendanceDate . ' ' . substr($time, 0, 8);
    }

    /** @param array<string, mixed> $calculation */
    private function v3Params(array $calculation): array
    {
        return [
            ':check_in_at' => $calculation['check_in_at'] ?? null,
            ':check_out_at' => $calculation['check_out_at'] ?? null,
            ':business_date' => $calculation['business_date'] ?? null,
            ':day_type' => $calculation['day_type'] ?? null,
            ':attendance_state' => $calculation['attendance_state'] ?? null,
            ':is_late' => (int) ($calculation['is_late'] ?? 0),
            ':is_early_departure' => (int) ($calculation['is_early_departure'] ?? 0),
            ':is_missing_check_out' => (int) ($calculation['is_missing_check_out'] ?? 0),
            ':is_manual_override' => (int) ($calculation['is_manual_override'] ?? 0),
            ':is_overnight_attendance' => (int) ($calculation['is_overnight_attendance'] ?? 0),
            ':regular_minutes' => $calculation['regular_minutes'] ?? null,
            ':early_departure_minutes' => (int) ($calculation['early_departure_minutes'] ?? 0),
            ':early_overtime_minutes' => (int) ($calculation['early_overtime_minutes'] ?? 0),
            ':after_hours_overtime_minutes' => (int) ($calculation['after_hours_overtime_minutes'] ?? 0),
            ':non_working_day_overtime_minutes' => (int) ($calculation['non_working_day_overtime_minutes'] ?? 0),
            ':holiday_overtime_minutes' => (int) ($calculation['holiday_overtime_minutes'] ?? 0),
            ':raw_calculated_overtime_minutes' => $calculation['raw_calculated_overtime_minutes'] ?? null,
            ':calculated_overtime_minutes' => $calculation['calculated_overtime_minutes'] ?? null,
            ':approved_overtime_minutes' => $calculation['approved_overtime_minutes'] ?? null,
            ':overtime_approval_status' => $calculation['overtime_approval_status'] ?? null,
            ':resolved_policy_version_id' => $calculation['resolved_policy_version_id'] ?? null,
            ':resolved_assignment_id' => $calculation['resolved_assignment_id'] ?? null,
            ':calculation_version' => $calculation['calculation_version'] ?? null,
        ];
    }

    /** @param array<string, mixed> $calculation */
    private function notifyPendingOvertime(int $attendanceId, array $calculation): void
    {
        if ($this->notifications === null || (string) ($calculation['overtime_approval_status'] ?? '') !== 'pending' || (int) ($calculation['calculated_overtime_minutes'] ?? 0) <= 0) {
            return;
        }
        $link = '/admin/overtime/show?id=' . $attendanceId;
        $admins = $this->connection->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll();
        foreach ($admins as $admin) {
            $adminId = (int) $admin['id'];
            $statement = $this->connection->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND type = :type AND link_url = :link');
            $statement->execute([':user_id' => $adminId, ':type' => 'overtime_pending', ':link' => $link]);
            if ((int) $statement->fetchColumn() > 0) {
                continue;
            }
            $this->notifications->create($adminId, 'overtime_pending', 'notifications.events.overtime_pending.title', 'notifications.events.overtime_pending.message', $link, null, null);
        }
    }

    /** @param array<string, mixed>|null $oldValues @param array<string, mixed>|null $newValues @param array<string, mixed> $context */
    private function auditSelfAttendance(?int $actorUserId, string $action, int $attendanceId, string $description, ?array $oldValues, ?array $newValues, array $context): void
    {
        $this->auditLog?->record($actorUserId, $action, 'attendance_record', $attendanceId, $description, $oldValues, $newValues, $context);
    }

    private function transactional(string $operation, callable $callback): mixed
    {
        $this->connection->beginTransaction();
        try {
            $result = $callback();
            $this->connection->commit();

            return $result;
        } catch (PDOException $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            if ($exception->getCode() === '23000' && str_contains($exception->getMessage(), 'Duplicate entry')) {
                throw new AttendanceOperationException('duplicate');
            }
            throw DatabaseOperationException::forOperation(self::class, $operation, $exception);
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }
}
