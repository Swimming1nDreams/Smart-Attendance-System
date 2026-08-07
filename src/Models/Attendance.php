<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\AttendancePolicyService;
use App\Services\DatabaseOperationException;
use DateTimeImmutable;
use PDO;
use PDOException;

class Attendance
{
    private PDO $connection;
    private AttendancePolicyService $policy;

    public function __construct(PDO $connection, ?AttendancePolicyService $policy = null)
    {
        $this->connection = $connection;
        $this->policy = $policy ?? new AttendancePolicyService();
    }

    public function findAll(?int $userId = null, string $search = '', ?string $fromDate = null, ?string $toDate = null, int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $sql = 'SELECT ar.*, u.name AS employee_name, u.email AS employee_email, u.staff_code, u.expected_daily_work_hours, u.overtime_eligible
                FROM attendance_records ar
                JOIN users u ON u.id = ar.user_id
                WHERE 1=1';
        $params = [];

        if ($userId !== null) {
            $sql .= ' AND ar.user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        if ($search !== '') {
            $sql .= ' AND (u.name LIKE :search_name OR u.email LIKE :search_email OR u.staff_code LIKE :search_staff_code)';
            $searchPattern = '%' . $search . '%';
            $params[':search_name'] = $searchPattern;
            $params[':search_email'] = $searchPattern;
            $params[':search_staff_code'] = $searchPattern;
        }

        if ($fromDate !== null) {
            $sql .= ' AND ar.attendance_date >= :from_date';
            $params[':from_date'] = $fromDate;
        }

        if ($toDate !== null) {
            $sql .= ' AND ar.attendance_date <= :to_date';
            $params[':to_date'] = $toDate;
        }

        $sql .= ' ORDER BY ar.attendance_date DESC, u.name ASC, ar.id DESC LIMIT :limit OFFSET :offset';

        $statement = $this->connection->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);

        try {
            $statement->execute();

            return $statement->fetchAll();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'findAll', $exception);
        }
    }

    public function countAll(?int $userId = null, string $search = '', ?string $fromDate = null, ?string $toDate = null): int
    {
        $sql = 'SELECT COUNT(*) FROM attendance_records ar JOIN users u ON u.id = ar.user_id WHERE 1=1';
        $params = [];

        if ($userId !== null) {
            $sql .= ' AND ar.user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        if ($search !== '') {
            $sql .= ' AND (u.name LIKE :search_name OR u.email LIKE :search_email OR u.staff_code LIKE :search_staff_code)';
            $searchPattern = '%' . $search . '%';
            $params[':search_name'] = $searchPattern;
            $params[':search_email'] = $searchPattern;
            $params[':search_staff_code'] = $searchPattern;
        }

        if ($fromDate !== null) {
            $sql .= ' AND ar.attendance_date >= :from_date';
            $params[':from_date'] = $fromDate;
        }

        if ($toDate !== null) {
            $sql .= ' AND ar.attendance_date <= :to_date';
            $params[':to_date'] = $toDate;
        }

        $statement = $this->connection->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        try {
            $statement->execute();
            return (int) $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'countAll', $exception);
        }
    }

    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT ar.*, u.name AS employee_name, u.email AS employee_email, u.staff_code, u.expected_daily_work_hours, u.overtime_eligible FROM attendance_records ar JOIN users u ON u.id = ar.user_id WHERE ar.id = :id LIMIT 1'
        );
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        try {
            $statement->execute();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'findById', $exception);
        }

        $attendance = $statement->fetch();

        return $attendance === false ? null : $attendance;
    }

    public function findByUserIdAndDate(int $userId, string $attendanceDate): ?array
    {
        $statement = $this->connection->prepare('SELECT ar.*, u.expected_daily_work_hours, u.overtime_eligible FROM attendance_records ar JOIN users u ON u.id = ar.user_id WHERE ar.user_id = :user_id AND ar.attendance_date = :attendance_date LIMIT 1');
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':attendance_date', $attendanceDate, PDO::PARAM_STR);

        try {
            $statement->execute();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'findByUserIdAndDate', $exception);
        }

        $attendance = $statement->fetch();

        return $attendance === false ? null : $attendance;
    }

    public function existsByUserAndDate(int $userId, string $attendanceDate, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM attendance_records WHERE user_id = :user_id AND attendance_date = :attendance_date';

        if ($excludeId !== null) {
            $sql .= ' AND id != :id';
        }

        $statement = $this->connection->prepare($sql);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':attendance_date', $attendanceDate, PDO::PARAM_STR);

        if ($excludeId !== null) {
            $statement->bindValue(':id', $excludeId, PDO::PARAM_INT);
        }

        try {
            $statement->execute();
            return (int) $statement->fetchColumn() > 0;
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'existsByUserAndDate', $exception);
        }
    }

    public function create(int $userId, string $attendanceDate, ?string $checkInTime, ?string $checkOutTime, float $workingHours, string $status, int $lateMinutes, ?string $remarks): bool
    {
        $statement = $this->connection->prepare('INSERT INTO attendance_records (user_id, attendance_date, clock_in_time, clock_out_time, working_hours, status, late_minutes, remarks) VALUES (:user_id, :attendance_date, :clock_in_time, :clock_out_time, :working_hours, :status, :late_minutes, :remarks)');
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':attendance_date', $attendanceDate, PDO::PARAM_STR);
        $statement->bindValue(':clock_in_time', $checkInTime, $checkInTime === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':clock_out_time', $checkOutTime, $checkOutTime === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':working_hours', $workingHours, PDO::PARAM_STR);
        $statement->bindValue(':status', $status, PDO::PARAM_STR);
        $statement->bindValue(':late_minutes', $lateMinutes, PDO::PARAM_INT);
        $statement->bindValue(':remarks', $remarks, $remarks === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

        try {
            return $statement->execute();
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000' && str_contains($exception->getMessage(), 'Duplicate entry')) {
                return false;
            }
            throw DatabaseOperationException::forOperation(self::class, 'create', $exception);
        }
    }

    public function update(int $id, int $userId, string $attendanceDate, ?string $checkInTime, ?string $checkOutTime, float $workingHours, string $status, int $lateMinutes, ?string $remarks): bool
    {
        $statement = $this->connection->prepare('UPDATE attendance_records SET user_id = :user_id, attendance_date = :attendance_date, clock_in_time = :clock_in_time, clock_out_time = :clock_out_time, working_hours = :working_hours, status = :status, late_minutes = :late_minutes, remarks = :remarks, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':attendance_date', $attendanceDate, PDO::PARAM_STR);
        $statement->bindValue(':clock_in_time', $checkInTime, $checkInTime === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':clock_out_time', $checkOutTime, $checkOutTime === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':working_hours', $workingHours, PDO::PARAM_STR);
        $statement->bindValue(':status', $status, PDO::PARAM_STR);
        $statement->bindValue(':late_minutes', $lateMinutes, PDO::PARAM_INT);
        $statement->bindValue(':remarks', $remarks, $remarks === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        try {
            return $statement->execute();
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000' && str_contains($exception->getMessage(), 'Duplicate entry')) {
                return false;
            }
            throw DatabaseOperationException::forOperation(self::class, 'update', $exception);
        }
    }

    public function delete(int $id): bool
    {
        $statement = $this->connection->prepare('DELETE FROM attendance_records WHERE id = :id');
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        try {
            return $statement->execute();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'delete', $exception);
        }
    }

    public function markClockIn(int $id, string $clockInTime, string $status): bool
    {
        $statement = $this->connection->prepare('UPDATE attendance_records SET clock_in_time = :clock_in_time, status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->bindValue(':clock_in_time', $clockInTime, PDO::PARAM_STR);
        $statement->bindValue(':status', $status, PDO::PARAM_STR);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        try {
            return $statement->execute();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'markClockIn', $exception);
        }
    }

    public function markClockOut(int $id, string $checkOutTime, float $workingHours, int $lateMinutes): bool
    {
        $statement = $this->connection->prepare('UPDATE attendance_records SET clock_out_time = :clock_out_time, working_hours = :working_hours, late_minutes = :late_minutes, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->bindValue(':clock_out_time', $checkOutTime, PDO::PARAM_STR);
        $statement->bindValue(':working_hours', $workingHours, PDO::PARAM_STR);
        $statement->bindValue(':late_minutes', $lateMinutes, PDO::PARAM_INT);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        try {
            return $statement->execute();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'markClockOut', $exception);
        }
    }

    public function findAttendanceSummaryReportRecords(?string $fromDate = null, ?string $toDate = null, ?int $departmentId = null, ?int $employeeId = null, int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $sql = 'SELECT ar.*,
                    u.name AS employee_name, u.email AS employee_email, u.staff_code, u.expected_daily_work_hours, u.overtime_eligible, d.name AS department_name
                FROM attendance_records ar
                JOIN users u ON u.id = ar.user_id
                LEFT JOIN departments d ON d.id = u.department_id
                WHERE u.role = :role';
        $params = [':role' => 'employee'];

        $this->applyAttendanceSummaryReportFilters($sql, $params, $fromDate, $toDate, $departmentId, $employeeId);

        $sql .= ' ORDER BY ar.attendance_date DESC, u.name ASC LIMIT :limit OFFSET :offset';
        $statement = $this->connection->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);

        try {
            $statement->execute();
            return $statement->fetchAll();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'findAttendanceSummaryReportRecords', $exception);
        }
    }

    public function countAttendanceSummaryReportRecords(?string $fromDate = null, ?string $toDate = null, ?int $departmentId = null, ?int $employeeId = null): int
    {
        $sql = 'SELECT COUNT(*)
                FROM attendance_records ar
                JOIN users u ON u.id = ar.user_id
                WHERE u.role = :role';
        $params = [':role' => 'employee'];

        $this->applyAttendanceSummaryReportFilters($sql, $params, $fromDate, $toDate, $departmentId, $employeeId);
        $statement = $this->connection->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        try {
            $statement->execute();
            return (int) $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'countAttendanceSummaryReportRecords', $exception);
        }
    }

    public function getAttendanceSummaryReportTotals(?string $fromDate = null, ?string $toDate = null, ?int $departmentId = null, ?int $employeeId = null): array
    {
        $sql = 'SELECT
                    COUNT(*) AS total_records,
                    SUM(CASE WHEN ar.status = :present_status THEN 1 ELSE 0 END) AS present_count,
                    SUM(CASE WHEN ar.late_minutes > 0 THEN 1 ELSE 0 END) AS late_count,
                    SUM(CASE WHEN ar.status = :absent_status THEN 1 ELSE 0 END) AS absent_count,
                    COALESCE(SUM(CASE
                        WHEN ar.clock_in_time IS NOT NULL AND ar.clock_out_time IS NOT NULL
                        THEN TIMESTAMPDIFF(MINUTE, ar.clock_in_time, ar.clock_out_time)
                        ELSE 0
                    END), 0) AS total_work_minutes
                FROM attendance_records ar
                JOIN users u ON u.id = ar.user_id
                WHERE u.role = :role';
        $params = [
            ':present_status' => 'present',
            ':absent_status' => 'absent',
            ':role' => 'employee',
        ];

        $this->applyAttendanceSummaryReportFilters($sql, $params, $fromDate, $toDate, $departmentId, $employeeId);
        $statement = $this->connection->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        try {
            $statement->execute();
            $totals = $statement->fetch();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'getAttendanceSummaryReportTotals', $exception);
        }

        if ($totals === false) {
            return [
                'total_records' => 0,
                'present_count' => 0,
                'late_count' => 0,
                'absent_count' => 0,
                'total_work_minutes' => 0,
                'total_overtime_minutes' => 0,
            ];
        }

        return [
            'total_records' => (int) ($totals['total_records'] ?? 0),
            'present_count' => (int) ($totals['present_count'] ?? 0),
            'late_count' => (int) ($totals['late_count'] ?? 0),
            'absent_count' => (int) ($totals['absent_count'] ?? 0),
            'total_work_minutes' => (int) ($totals['total_work_minutes'] ?? 0),
            'total_overtime_minutes' => 0,
        ];
    }

    public function findLateAbsentExceptionReportRecords(?string $fromDate = null, ?string $toDate = null, ?int $departmentId = null, ?int $employeeId = null, string $exceptionType = 'all', int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $sql = 'SELECT ar.*, u.name AS employee_name, u.email AS employee_email, u.staff_code, u.expected_daily_work_hours, u.overtime_eligible, d.name AS department_name
                FROM attendance_records ar
                JOIN users u ON u.id = ar.user_id
                LEFT JOIN departments d ON d.id = u.department_id
                WHERE u.role = :role';
        $params = [':role' => 'employee'];

        $this->applyLateAbsentExceptionReportFilters($sql, $params, $fromDate, $toDate, $departmentId, $employeeId, $exceptionType);

        $sql .= ' ORDER BY ar.attendance_date DESC, u.name ASC LIMIT :limit OFFSET :offset';
        $statement = $this->connection->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);

        try {
            $statement->execute();
            return $statement->fetchAll();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'findLateAbsentExceptionReportRecords', $exception);
        }
    }

    public function countLateAbsentExceptionReportRecords(?string $fromDate = null, ?string $toDate = null, ?int $departmentId = null, ?int $employeeId = null, string $exceptionType = 'all'): int
    {
        $sql = 'SELECT COUNT(*)
                FROM attendance_records ar
                JOIN users u ON u.id = ar.user_id
                WHERE u.role = :role';
        $params = [':role' => 'employee'];

        $this->applyLateAbsentExceptionReportFilters($sql, $params, $fromDate, $toDate, $departmentId, $employeeId, $exceptionType);
        $statement = $this->connection->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        try {
            $statement->execute();
            return (int) $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'countLateAbsentExceptionReportRecords', $exception);
        }
    }

    public function getDailySummary(string $attendanceDate): array
    {
        $statement = $this->connection->prepare(
            'SELECT
                COUNT(*) AS total_records,
                SUM(CASE WHEN clock_in_time IS NOT NULL THEN 1 ELSE 0 END) AS clocked_in_count,
                SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) AS late_count,
                SUM(CASE WHEN status = :absent_status THEN 1 ELSE 0 END) AS absent_count,
                SUM(CASE WHEN clock_in_time IS NOT NULL AND clock_out_time IS NULL THEN 1 ELSE 0 END) AS missing_clock_out_count
             FROM attendance_records
             WHERE attendance_date = :attendance_date'
        );
        $statement->bindValue(':attendance_date', $attendanceDate, PDO::PARAM_STR);
        $statement->bindValue(':absent_status', 'absent', PDO::PARAM_STR);

        try {
            $statement->execute();
            $summary = $statement->fetch();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'getDailySummary', $exception);
        }

        if ($summary === false) {
            return [
                'total_records' => 0,
                'clocked_in_count' => 0,
                'late_count' => 0,
                'absent_count' => 0,
                'missing_clock_out_count' => 0,
            ];
        }

        return [
            'total_records' => (int) ($summary['total_records'] ?? 0),
            'clocked_in_count' => (int) ($summary['clocked_in_count'] ?? 0),
            'late_count' => (int) ($summary['late_count'] ?? 0),
            'absent_count' => (int) ($summary['absent_count'] ?? 0),
            'missing_clock_out_count' => (int) ($summary['missing_clock_out_count'] ?? 0),
        ];
    }

    public function findLateArrivalsByDate(string $attendanceDate, int $limit = 5): array
    {
        $statement = $this->connection->prepare(
            'SELECT ar.id, ar.user_id, ar.attendance_date, ar.clock_in_time, ar.clock_out_time, ar.status, ar.late_minutes, u.name AS employee_name
             FROM attendance_records ar
             JOIN users u ON u.id = ar.user_id
             WHERE ar.attendance_date = :attendance_date AND ar.late_minutes > 0
             ORDER BY ar.late_minutes DESC, ar.clock_in_time DESC
             LIMIT :limit'
        );
        $statement->bindValue(':attendance_date', $attendanceDate, PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);

        try {
            $statement->execute();
            return $statement->fetchAll();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'findLateArrivalsByDate', $exception);
        }
    }

    public function findMissingClockOutsByDate(string $attendanceDate, int $limit = 5): array
    {
        $statement = $this->connection->prepare(
            'SELECT ar.id, ar.user_id, ar.attendance_date, ar.clock_in_time, ar.status, u.name AS employee_name
             FROM attendance_records ar
             JOIN users u ON u.id = ar.user_id
             WHERE ar.attendance_date = :attendance_date AND ar.clock_in_time IS NOT NULL AND ar.clock_out_time IS NULL
             ORDER BY ar.clock_in_time ASC
             LIMIT :limit'
        );
        $statement->bindValue(':attendance_date', $attendanceDate, PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);

        try {
            $statement->execute();
            return $statement->fetchAll();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'findMissingClockOutsByDate', $exception);
        }
    }

    public function findRecentActivity(int $limit = 8): array
    {
        $statement = $this->connection->prepare(
            'SELECT ar.id, ar.user_id, ar.attendance_date, ar.clock_in_time, ar.clock_out_time, ar.status, ar.late_minutes, u.name AS employee_name
             FROM attendance_records ar
             JOIN users u ON u.id = ar.user_id
             ORDER BY ar.attendance_date DESC, COALESCE(ar.clock_in_time, "00:00:00") DESC, u.name ASC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);

        try {
            $statement->execute();
            return $statement->fetchAll();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'findRecentActivity', $exception);
        }
    }

    public function getRecentDailyTrend(string $endDate, int $days = 7): array
    {
        $days = max(1, min(31, $days));
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate) ?: new DateTimeImmutable($endDate);
        $start = $end->modify('-' . ($days - 1) . ' days');

        $statement = $this->connection->prepare(
            'SELECT attendance_date,
                    SUM(CASE WHEN clock_in_time IS NOT NULL THEN 1 ELSE 0 END) AS clocked_in_count,
                    SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) AS late_count,
                    SUM(CASE WHEN status = :absent_status THEN 1 ELSE 0 END) AS absent_count
             FROM attendance_records
             WHERE attendance_date BETWEEN :start_date AND :end_date
             GROUP BY attendance_date
             ORDER BY attendance_date ASC'
        );
        $statement->bindValue(':absent_status', 'absent', PDO::PARAM_STR);
        $statement->bindValue(':start_date', $start->format('Y-m-d'), PDO::PARAM_STR);
        $statement->bindValue(':end_date', $end->format('Y-m-d'), PDO::PARAM_STR);

        try {
            $statement->execute();
            $records = $statement->fetchAll();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'getRecentDailyTrend', $exception);
        }

        $byDate = [];
        foreach ($records as $record) {
            $byDate[(string) $record['attendance_date']] = $record;
        }

        $trend = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $date = $start->modify('+' . $offset . ' days')->format('Y-m-d');
            $record = $byDate[$date] ?? [];
            $trend[] = [
                'attendance_date' => $date,
                'clocked_in_count' => (int) ($record['clocked_in_count'] ?? 0),
                'late_count' => (int) ($record['late_count'] ?? 0),
                'absent_count' => (int) ($record['absent_count'] ?? 0),
            ];
        }

        return $trend;
    }

    public function getDepartmentDailySummary(string $attendanceDate, int $limit = 8): array
    {
        $statement = $this->connection->prepare(
            'SELECT COALESCE(d.name, :no_department) AS department_name,
                    COUNT(u.id) AS active_count,
                    SUM(CASE WHEN ar.clock_in_time IS NOT NULL THEN 1 ELSE 0 END) AS clocked_in_count,
                    SUM(CASE WHEN ar.late_minutes > 0 THEN 1 ELSE 0 END) AS late_count,
                    SUM(CASE WHEN ar.status = :absent_status THEN 1 ELSE 0 END) AS absent_count
             FROM users u
             LEFT JOIN departments d ON d.id = u.department_id
             LEFT JOIN attendance_records ar ON ar.user_id = u.id AND ar.attendance_date = :attendance_date
             WHERE u.role = :role AND u.status = :status
             GROUP BY d.id, d.name
             ORDER BY clocked_in_count DESC, department_name ASC
             LIMIT :limit'
        );
        $statement->bindValue(':no_department', 'No Department', PDO::PARAM_STR);
        $statement->bindValue(':absent_status', 'absent', PDO::PARAM_STR);
        $statement->bindValue(':attendance_date', $attendanceDate, PDO::PARAM_STR);
        $statement->bindValue(':role', 'employee', PDO::PARAM_STR);
        $statement->bindValue(':status', 'active', PDO::PARAM_STR);
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);

        try {
            $statement->execute();
            $rows = $statement->fetchAll();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'getDepartmentDailySummary', $exception);
        }

        return array_map(static fn (array $row): array => [
            'department_name' => (string) ($row['department_name'] ?? 'No Department'),
            'active_count' => (int) ($row['active_count'] ?? 0),
            'clocked_in_count' => (int) ($row['clocked_in_count'] ?? 0),
            'late_count' => (int) ($row['late_count'] ?? 0),
            'absent_count' => (int) ($row['absent_count'] ?? 0),
        ], $rows);
    }

    public function calculateLateMinutes(string $checkInTime): int
    {
        return $this->policy->calculateLateMinutes($checkInTime);
    }

    public function calculateWorkingHours(string $checkInTime, string $checkOutTime): float
    {
        return $this->policy->workingHours($this->policy->actualWorkMinutes($checkInTime, $checkOutTime));
    }

    /** @param list<int> $userIds */
    public function findForUsersInRange(array $userIds, string $fromDate, string $toDate): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        if ($userIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $statement = $this->connection->prepare(
            "SELECT ar.*, u.expected_daily_work_hours, u.overtime_eligible
             FROM attendance_records ar
             JOIN users u ON u.id = ar.user_id
             WHERE ar.user_id IN ($placeholders) AND ar.attendance_date BETWEEN ? AND ?
             ORDER BY ar.user_id ASC, ar.attendance_date ASC"
        );
        $statement->execute([...$userIds, $fromDate, $toDate]);

        return $statement->fetchAll();
    }

    public function calculateOvertimeMinutes(string $status, mixed $workingHours, string $attendanceDate, bool $isHoliday = false, bool $hasApprovedLeave = false, mixed $expectedHours = null, bool $overtimeEligible = true, ?string $clockInTime = null, ?string $clockOutTime = null): ?int
    {
        $actualMinutes = $clockInTime !== null && $clockOutTime !== null
            ? $this->policy->actualWorkMinutes($clockInTime, $clockOutTime)
            : null;

        return $this->policy->calculateOvertimeMinutes(
            $status,
            $actualMinutes,
            $expectedHours,
            $attendanceDate,
            $isHoliday,
            $hasApprovedLeave,
            $overtimeEligible,
            $clockInTime,
            $clockOutTime
        );
    }

    private function applyAttendanceSummaryReportFilters(string &$sql, array &$params, ?string $fromDate, ?string $toDate, ?int $departmentId, ?int $employeeId): void
    {
        if ($fromDate !== null) {
            $sql .= ' AND ar.attendance_date >= :from_date';
            $params[':from_date'] = $fromDate;
        }

        if ($toDate !== null) {
            $sql .= ' AND ar.attendance_date <= :to_date';
            $params[':to_date'] = $toDate;
        }

        if ($departmentId !== null) {
            $sql .= ' AND u.department_id = :department_id';
            $params[':department_id'] = $departmentId;
        }

        if ($employeeId !== null) {
            $sql .= ' AND ar.user_id = :employee_id';
            $params[':employee_id'] = $employeeId;
        }
    }

    private function applyLateAbsentExceptionReportFilters(string &$sql, array &$params, ?string $fromDate, ?string $toDate, ?int $departmentId, ?int $employeeId, string $exceptionType): void
    {
        $this->applyAttendanceSummaryReportFilters($sql, $params, $fromDate, $toDate, $departmentId, $employeeId);

        if ($exceptionType === 'late') {
            $sql .= ' AND ar.late_minutes > 0';
            return;
        }

        if ($exceptionType === 'absent') {
            $sql .= ' AND ar.status = :exception_absent_status';
            $params[':exception_absent_status'] = 'absent';
            return;
        }

        $sql .= ' AND (ar.late_minutes > 0 OR ar.status = :exception_absent_status)';
        $params[':exception_absent_status'] = 'absent';
    }
}
