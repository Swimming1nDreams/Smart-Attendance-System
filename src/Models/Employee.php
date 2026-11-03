<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

use PDOException;

class Employee
{
    private PDO $connection;

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    public function findAll(string $search = '', ?int $departmentId = null, ?int $positionId = null, ?string $status = null, int $page = 1, int $perPage = 20, ?string $role = null): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $sql = 'SELECT u.id, u.name, u.email, u.role, u.status, u.session_version, u.email_verified_at, u.email_verification_expires_at, u.created_at, u.department_id, u.position_id, u.staff_code, u.employment_type, u.join_date, u.expected_daily_work_hours, u.overtime_eligible, d.name AS department_name, p.name AS position_name FROM users u LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN positions p ON p.id = u.position_id WHERE 1=1';
        $params = [];

        if ($search !== '') {
            $sql .= ' AND (u.name LIKE :search_name OR u.email LIKE :search_email OR u.staff_code LIKE :search_staff_code)';
            $searchPattern = '%' . $search . '%';
            $params[':search_name'] = $searchPattern;
            $params[':search_email'] = $searchPattern;
            $params[':search_staff_code'] = $searchPattern;
        }

        if ($departmentId !== null) {
            $sql .= ' AND u.department_id = :department_id';
            $params[':department_id'] = $departmentId;
        }

        if ($positionId !== null) {
            $sql .= ' AND u.position_id = :position_id';
            $params[':position_id'] = $positionId;
        }

        if ($status !== null && $status !== '') {
            $sql .= ' AND u.status = :status';
            $params[':status'] = $status;
        }

        if ($role !== null && $role !== '') {
            $sql .= ' AND u.role = :role';
            $params[':role'] = $role;
        }

        $sql .= ' ORDER BY u.created_at DESC, u.id DESC LIMIT :limit OFFSET :offset';

        $statement = $this->connection->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);

        try {
            $statement->execute();
            return $statement->fetchAll();
        } catch (PDOException) {
            return [];
        }
    }

    public function countAll(string $search = '', ?int $departmentId = null, ?int $positionId = null, ?string $status = null, ?string $role = null): int
    {
        $sql = 'SELECT COUNT(*) FROM users u WHERE 1=1';
        $params = [];

        if ($search !== '') {
            $sql .= ' AND (u.name LIKE :search_name OR u.email LIKE :search_email OR u.staff_code LIKE :search_staff_code)';
            $searchPattern = '%' . $search . '%';
            $params[':search_name'] = $searchPattern;
            $params[':search_email'] = $searchPattern;
            $params[':search_staff_code'] = $searchPattern;
        }

        if ($departmentId !== null) {
            $sql .= ' AND u.department_id = :department_id';
            $params[':department_id'] = $departmentId;
        }

        if ($positionId !== null) {
            $sql .= ' AND u.position_id = :position_id';
            $params[':position_id'] = $positionId;
        }

        if ($status !== null && $status !== '') {
            $sql .= ' AND u.status = :status';
            $params[':status'] = $status;
        }

        if ($role !== null && $role !== '') {
            $sql .= ' AND u.role = :role';
            $params[':role'] = $role;
        }

        $statement = $this->connection->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        try {
            $statement->execute();
            return (int) $statement->fetchColumn();
        } catch (PDOException) {
            return 0;
        }
    }

    public function countByRoleAndStatus(string $role, ?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE role = :role';
        $statement = $this->connection->prepare($sql . ($status !== null && $status !== '' ? ' AND status = :status' : ''));
        $statement->bindValue(':role', $role, PDO::PARAM_STR);

        if ($status !== null && $status !== '') {
            $statement->bindValue(':status', $status, PDO::PARAM_STR);
        }

        try {
            $statement->execute();
            return (int) $statement->fetchColumn();
        } catch (PDOException) {
            return 0;
        }
    }

    public function findByRoleAndStatus(string $role, string $status, int $limit = 5): array
    {
        $statement = $this->connection->prepare(
            'SELECT u.id, u.name, u.email, u.role, u.status, u.department_id, d.name AS department_name, p.name AS position_name
             FROM users u
             LEFT JOIN departments d ON d.id = u.department_id
             LEFT JOIN positions p ON p.id = u.position_id
             WHERE u.role = :role AND u.status = :status
             ORDER BY u.created_at DESC
             LIMIT :limit'
        );
        $statement->bindValue(':role', $role, PDO::PARAM_STR);
        $statement->bindValue(':status', $status, PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);

        try {
            $statement->execute();
            return $statement->fetchAll();
        } catch (PDOException) {
            return [];
        }
    }

    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT u.id, u.name, u.email, u.role, u.status, u.session_version, u.email_verified_at, u.email_verification_expires_at, u.email_verification_sent_at, u.email_verification_attempts, u.department_id, u.position_id, u.staff_code, u.employment_type, u.join_date, u.expected_daily_work_hours, u.overtime_eligible, u.created_at, d.name AS department_name, p.name AS position_name FROM users u LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN positions p ON p.id = u.position_id WHERE u.id = :id LIMIT 1');
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        try {
            $statement->execute();
        } catch (PDOException) {
            return null;
        }

        $employee = $statement->fetch();
        return $employee === false ? null : $employee;
    }

    public function findAnalyticsScope(?int $departmentId = null): array
    {
        $sql = 'SELECT u.id, u.name, u.email, u.staff_code, u.role, u.status, u.department_id, u.position_id,
                       u.expected_daily_work_hours, u.overtime_eligible,
                       d.name AS department_name, p.name AS position_name
                FROM users u
                LEFT JOIN departments d ON d.id = u.department_id
                LEFT JOIN positions p ON p.id = u.position_id
                WHERE u.role = :role AND u.status = :status';
        if ($departmentId !== null) {
            $sql .= ' AND u.department_id = :department_id';
        }
        $sql .= ' ORDER BY u.name ASC, u.id ASC';
        $statement = $this->connection->prepare($sql);
        $statement->bindValue(':role', 'employee', PDO::PARAM_STR);
        $statement->bindValue(':status', 'active', PDO::PARAM_STR);
        if ($departmentId !== null) {
            $statement->bindValue(':department_id', $departmentId, PDO::PARAM_INT);
        }
        $statement->execute();

        return $statement->fetchAll();
    }

    public function findReportEmployees(?int $departmentId = null): array
    {
        $sql = 'SELECT u.id, u.name, u.email, u.staff_code, u.status, u.department_id,
                    d.name AS department_name, p.name AS position_name
             FROM users u
             LEFT JOIN departments d ON d.id = u.department_id
             LEFT JOIN positions p ON p.id = u.position_id
             WHERE u.role = :role';
        
        $params = [':role' => 'employee'];
        if ($departmentId !== null) {
            $sql .= ' AND u.department_id = :department_id';
            $params[':department_id'] = $departmentId;
        }
        
        $sql .= ' ORDER BY u.name ASC, u.id ASC';

        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function lockById(int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, name, email, role, department_id, position_id, status, session_version, staff_code,
                    employment_type, join_date, expected_daily_work_hours, overtime_eligible
             FROM users WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();
        $employee = $statement->fetch();

        return $employee === false ? null : $employee;
    }

    /** @return list<int> */
    public function lockActiveAdministratorIds(): array
    {
        $statement = $this->connection->query(
            "SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id FOR UPDATE"
        );

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function existsByEmail(string $email, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE email = :email';

        if ($excludeId !== null) {
            $sql .= ' AND id != :id';
        }

        $statement = $this->connection->prepare($sql);
        $statement->bindValue(':email', $email, PDO::PARAM_STR);

        if ($excludeId !== null) {
            $statement->bindValue(':id', $excludeId, PDO::PARAM_INT);
        }

        try {
            $statement->execute();
            return (int) $statement->fetchColumn() > 0;
        } catch (PDOException) {
            return true;
        }
    }

    public function existsByStaffCode(string $staffCode, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE staff_code = :staff_code';

        if ($excludeId !== null) {
            $sql .= ' AND id != :id';
        }

        $statement = $this->connection->prepare($sql);
        $statement->bindValue(':staff_code', $staffCode, PDO::PARAM_STR);

        if ($excludeId !== null) {
            $statement->bindValue(':id', $excludeId, PDO::PARAM_INT);
        }

        try {
            $statement->execute();
            return (int) $statement->fetchColumn() > 0;
        } catch (PDOException) {
            return true;
        }
    }

    public function create(string $name, string $email, string $password, string $role, ?int $departmentId, ?int $positionId, string $status, ?string $staffCode, string $employmentType, ?string $joinDate, float $expectedDailyWorkHours, bool $overtimeEligible): int
    {
        $statement = $this->connection->prepare('INSERT INTO users (name, email, password, role, department_id, position_id, status, staff_code, employment_type, join_date, expected_daily_work_hours, overtime_eligible, email_verified_at) VALUES (:name, :email, :password, :role, :department_id, :position_id, :status, :staff_code, :employment_type, :join_date, :expected_daily_work_hours, :overtime_eligible, NULL)');
        $statement->bindValue(':name', $name, PDO::PARAM_STR);
        $statement->bindValue(':email', $email, PDO::PARAM_STR);
        $statement->bindValue(':password', password_hash($password, PASSWORD_DEFAULT), PDO::PARAM_STR);
        $statement->bindValue(':role', $role, PDO::PARAM_STR);
        $statement->bindValue(':department_id', $departmentId, $departmentId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':position_id', $positionId, $positionId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':status', $status, PDO::PARAM_STR);
        $statement->bindValue(':staff_code', $staffCode, $staffCode === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':employment_type', $employmentType, PDO::PARAM_STR);
        $statement->bindValue(':join_date', $joinDate, $joinDate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':expected_daily_work_hours', $expectedDailyWorkHours);
        $statement->bindValue(':overtime_eligible', $overtimeEligible ? 1 : 0, PDO::PARAM_INT);

        try {
            $statement->execute();
            return (int) $this->connection->lastInsertId();
        } catch (PDOException) {
            return 0;
        }
    }

    public function update(int $id, string $name, string $email, string $role, ?int $departmentId, ?int $positionId, string $status, ?string $staffCode, string $employmentType, ?string $joinDate, float $expectedDailyWorkHours, bool $overtimeEligible, ?string $password = null, bool $incrementSessionVersion = false): bool
    {
        $assignments = [
            'name = :name',
            'email = :email',
            'role = :role',
            'department_id = :department_id',
            'position_id = :position_id',
            'status = :status',
            'staff_code = :staff_code',
            'employment_type = :employment_type',
            'join_date = :join_date',
            'expected_daily_work_hours = :expected_daily_work_hours',
            'overtime_eligible = :overtime_eligible',
        ];

        if ($password !== null && $password !== '') {
            $assignments[] = 'password = :password';
        }

        if ($incrementSessionVersion) {
            $assignments[] = 'session_version = session_version + 1';
        }

        $statement = $this->connection->prepare('UPDATE users SET ' . implode(', ', $assignments) . ' WHERE id = :id');

        if ($password !== null && $password !== '') {
            $statement->bindValue(':password', password_hash($password, PASSWORD_DEFAULT), PDO::PARAM_STR);
        }

        $statement->bindValue(':name', $name, PDO::PARAM_STR);
        $statement->bindValue(':email', $email, PDO::PARAM_STR);
        $statement->bindValue(':role', $role, PDO::PARAM_STR);
        $statement->bindValue(':department_id', $departmentId, $departmentId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':position_id', $positionId, $positionId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':status', $status, PDO::PARAM_STR);
        $statement->bindValue(':staff_code', $staffCode, $staffCode === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':employment_type', $employmentType, PDO::PARAM_STR);
        $statement->bindValue(':join_date', $joinDate, $joinDate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':expected_daily_work_hours', $expectedDailyWorkHours);
        $statement->bindValue(':overtime_eligible', $overtimeEligible ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        try {
            return $statement->execute();
        } catch (PDOException) {
            return false;
        }
    }

    public function deactivate(int $id): bool
    {
        $statement = $this->connection->prepare("UPDATE users SET status = :status, session_version = session_version + 1 WHERE id = :id AND status <> 'inactive'");
        $statement->bindValue(':status', 'inactive', PDO::PARAM_STR);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        try {
            $statement->execute();
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    public function resetPassword(int $id, string $password): bool
    {
        $statement = $this->connection->prepare('UPDATE users SET password = :password, session_version = session_version + 1 WHERE id = :id');
        $statement->bindValue(':password', password_hash($password, PASSWORD_DEFAULT), PDO::PARAM_STR);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        try {
            return $statement->execute();
        } catch (PDOException) {
            return false;
        }
    }
}
