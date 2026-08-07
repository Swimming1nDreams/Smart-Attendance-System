<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Localization;
use PDO;
use Throwable;

final class WorkScheduleAssignmentService
{
    private const SCOPES = ['company', 'department', 'employee'];

    public function __construct(
        private PDO $connection,
        private ?AuditLogService $auditLog = null,
    ) {
    }

    /** @return array{records: list<array<string, mixed>>, total: int, page: int, per_page: int, filters: array<string, mixed>} */
    public function list(array $input, int $page = 1, int $perPage = 20): array
    {
        $filters = $this->normalizeFilters($input);
        $page = max(1, $page);
        $perPage = in_array($perPage, [20, 50, 100], true) ? $perPage : 20;
        $where = [];
        $params = [];
        $this->applyFilters($filters, $where, $params);

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $count = $this->prepareAndFetchInt('SELECT COUNT(*) FROM work_schedule_assignments wsa' . $this->joins() . $whereSql, $params);

        $sql = 'SELECT wsa.*, wst.name AS policy_name, wst.lifecycle_status, d.name AS department_name,
                       u.name AS employee_name, u.email AS employee_email, u.staff_code
                FROM work_schedule_assignments wsa' . $this->joins() . $whereSql . '
                ORDER BY wsa.is_active DESC, wsa.effective_from DESC, wsa.id DESC
                LIMIT :limit OFFSET :offset';
        $statement = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        return [
            'records' => array_map(fn (array $row): array => $this->decorate($row), $statement->fetchAll()),
            'total' => $count,
            'page' => $page,
            'per_page' => $perPage,
            'filters' => $filters,
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function options(): array
    {
        return [
            'policies' => $this->connection->query("SELECT id, name FROM work_schedule_templates WHERE is_active = 1 AND lifecycle_status <> 'archived' ORDER BY name ASC")->fetchAll(),
            'departments' => $this->connection->query('SELECT id, name FROM departments ORDER BY name ASC')->fetchAll(),
            'employees' => $this->connection->query("SELECT id, name, email, staff_code, department_id FROM users WHERE role = 'employee' ORDER BY name ASC")->fetchAll(),
        ];
    }

    public function find(int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT wsa.*, wst.name AS policy_name, wst.lifecycle_status, d.name AS department_name,
                    u.name AS employee_name, u.email AS employee_email, u.staff_code
             FROM work_schedule_assignments wsa' . $this->joins() . '
             WHERE wsa.id = :id LIMIT 1'
        );
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $this->decorate($row);
    }

    /** @return array{ok: bool, errors: array<string, list<string>>, data: array<string, mixed>, id?: int} */
    public function create(array $input, ?int $actorUserId, array $auditContext = []): array
    {
        $data = $this->normalizeInput($input);
        $errors = $this->validate($data);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'data' => $data];
        }

        $this->connection->beginTransaction();
        try {
            $this->assertNoOverlap($data);
            $statement = $this->connection->prepare(
                'INSERT INTO work_schedule_assignments
                 (work_schedule_template_id, scope_type, scope_id, effective_from, effective_to, is_active, reason, created_by, updated_by)
                 VALUES (:policy_id, :scope_type, :scope_id, :effective_from, :effective_to, :is_active, :reason, :created_by, :updated_by)'
            );
            $statement->execute([
                ':policy_id' => (int) $data['work_schedule_template_id'],
                ':scope_type' => $data['scope_type'],
                ':scope_id' => $data['scope_id'],
                ':effective_from' => $data['effective_from'],
                ':effective_to' => $data['effective_to'],
                ':is_active' => (int) $data['is_active'],
                ':reason' => $data['reason'],
                ':created_by' => $actorUserId,
                ':updated_by' => $actorUserId,
            ]);
            $id = (int) $this->connection->lastInsertId();
            $this->auditLog?->record($actorUserId, 'work_schedule_assignment.created', 'work_schedule_assignment', $id, 'Workforce policy assignment created', null, $data, $auditContext);
            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            if ($exception instanceof AttendanceOperationException) {
                return ['ok' => false, 'errors' => ['form' => [$this->messageFor($exception->reason())]], 'data' => $data];
            }
            throw $exception;
        }

        return ['ok' => true, 'errors' => [], 'data' => $data, 'id' => $id];
    }

    /** @return array{ok: bool, errors: array<string, list<string>>, data: array<string, mixed>} */
    public function update(int $id, array $input, ?int $actorUserId, array $auditContext = []): array
    {
        $current = $this->find($id);
        if ($current === null) {
            return ['ok' => false, 'errors' => ['form' => [$this->messageFor('not_found')]], 'data' => $this->normalizeInput($input)];
        }
        if ($this->isHistorical($current)) {
            return ['ok' => false, 'errors' => ['form' => [$this->messageFor('historical_locked')]], 'data' => $this->normalizeInput($input, $current)];
        }

        $data = $this->normalizeInput($input, $current);
        $errors = $this->validate($data);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'data' => $data];
        }

        $this->connection->beginTransaction();
        try {
            $locked = $this->lockById($id);
            if ($locked === null) {
                throw new AttendanceOperationException('not_found');
            }
            if ($this->isHistorical($locked)) {
                throw new AttendanceOperationException('historical_locked');
            }
            $this->assertNoOverlap($data, $id);
            $statement = $this->connection->prepare(
                'UPDATE work_schedule_assignments
                 SET work_schedule_template_id = :policy_id, scope_type = :scope_type, scope_id = :scope_id,
                     effective_from = :effective_from, effective_to = :effective_to, is_active = :is_active,
                     reason = :reason, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $statement->execute([
                ':policy_id' => (int) $data['work_schedule_template_id'],
                ':scope_type' => $data['scope_type'],
                ':scope_id' => $data['scope_id'],
                ':effective_from' => $data['effective_from'],
                ':effective_to' => $data['effective_to'],
                ':is_active' => (int) $data['is_active'],
                ':reason' => $data['reason'],
                ':updated_by' => $actorUserId,
                ':id' => $id,
            ]);
            $this->auditLog?->record($actorUserId, 'work_schedule_assignment.updated', 'work_schedule_assignment', $id, 'Workforce policy assignment updated', $this->auditValues($locked), $data, $auditContext);
            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            if ($exception instanceof AttendanceOperationException) {
                return ['ok' => false, 'errors' => ['form' => [$this->messageFor($exception->reason())]], 'data' => $data];
            }
            throw $exception;
        }

        return ['ok' => true, 'errors' => [], 'data' => $data];
    }

    public function end(int $id, string $effectiveTo, ?int $actorUserId, array $auditContext = []): bool
    {
        $effectiveTo = trim($effectiveTo) !== '' ? trim($effectiveTo) : date('Y-m-d');
        if (!$this->validDate($effectiveTo)) {
            throw new AttendanceOperationException('invalid_date');
        }

        $this->connection->beginTransaction();
        try {
            $current = $this->lockById($id);
            if ($current === null) {
                throw new AttendanceOperationException('not_found');
            }
            $statement = $this->connection->prepare('UPDATE work_schedule_assignments SET effective_to = :effective_to, is_active = 0, updated_by = :actor, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $statement->execute([':effective_to' => $effectiveTo, ':actor' => $actorUserId, ':id' => $id]);
            $this->auditLog?->record($actorUserId, 'work_schedule_assignment.ended', 'work_schedule_assignment', $id, 'Workforce policy assignment ended', $this->auditValues($current), ['effective_to' => $effectiveTo, 'is_active' => 0], $auditContext);
            $this->connection->commit();
            return true;
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    public function removeEmployeeOverride(int $employeeId, ?int $actorUserId, array $auditContext = []): int
    {
        $today = date('Y-m-d');
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                "SELECT * FROM work_schedule_assignments
                 WHERE scope_type = 'employee' AND scope_id = :employee_id AND is_active = 1
                   AND effective_from <= :today_from AND (effective_to IS NULL OR effective_to >= :today_to)
                 FOR UPDATE"
            );
            $statement->execute([':employee_id' => $employeeId, ':today_from' => $today, ':today_to' => $today]);
            $rows = $statement->fetchAll();
            foreach ($rows as $row) {
                $this->connection->prepare('UPDATE work_schedule_assignments SET effective_to = :effective_to, is_active = 0, updated_by = :actor, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                    ->execute([':effective_to' => $today, ':actor' => $actorUserId, ':id' => (int) $row['id']]);
                $this->auditLog?->record($actorUserId, 'work_schedule_assignment.override_removed', 'work_schedule_assignment', (int) $row['id'], 'Employee policy override removed', $this->auditValues($row), ['effective_to' => $today, 'is_active' => 0], $auditContext);
            }
            $this->connection->commit();
            return count($rows);
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function normalizeInput(array $input, ?array $fallback = null): array
    {
        $scope = (string) ($input['scope_type'] ?? ($fallback['scope_type'] ?? 'company'));
        $scopeId = null;
        if ($scope === 'department') {
            $scopeId = $this->optionalInt($input['department_id'] ?? ($fallback['scope_id'] ?? null));
        } elseif ($scope === 'employee') {
            $scopeId = $this->optionalInt($input['employee_id'] ?? ($fallback['scope_id'] ?? null));
        }

        return [
            'scope_type' => in_array($scope, self::SCOPES, true) ? $scope : 'company',
            'scope_id' => $scopeId,
            'work_schedule_template_id' => $this->optionalInt($input['work_schedule_template_id'] ?? ($fallback['work_schedule_template_id'] ?? null)),
            'department_id' => $scope === 'department' ? $scopeId : null,
            'employee_id' => $scope === 'employee' ? $scopeId : null,
            'effective_from' => trim((string) ($input['effective_from'] ?? ($fallback['effective_from'] ?? date('Y-m-d')))),
            'effective_to' => $this->nullable(trim((string) ($input['effective_to'] ?? ($fallback['effective_to'] ?? '')))),
            'reason' => $this->nullable(mb_substr(trim((string) ($input['reason'] ?? ($fallback['reason'] ?? ''))), 0, 255)),
            'is_active' => isset($input['is_active']) || ($fallback !== null && (int) ($fallback['is_active'] ?? 0) === 1) ? 1 : 0,
        ];
    }

    /** @return array<string, list<string>> */
    private function validate(array $data): array
    {
        $errors = [];
        if (!in_array($data['scope_type'], self::SCOPES, true)) {
            $errors['scope_type'][] = $this->messageFor('scope_invalid');
        }
        if ($data['scope_type'] !== 'company' && (int) ($data['scope_id'] ?? 0) <= 0) {
            $errors['scope_id'][] = $this->messageFor('target_required');
        }
        if ((int) ($data['work_schedule_template_id'] ?? 0) <= 0 || !$this->policyIsAssignable((int) $data['work_schedule_template_id'])) {
            $errors['work_schedule_template_id'][] = $this->messageFor('policy_invalid');
        }
        if (!$this->validDate((string) $data['effective_from'])) {
            $errors['effective_from'][] = $this->messageFor('effective_from_invalid');
        }
        if ($data['effective_to'] !== null && (!$this->validDate((string) $data['effective_to']) || (string) $data['effective_to'] < (string) $data['effective_from'])) {
            $errors['effective_to'][] = $this->messageFor('effective_to_invalid');
        }
        if ($data['scope_type'] === 'department' && !$this->exists('departments', (int) $data['scope_id'])) {
            $errors['department_id'][] = $this->messageFor('department_invalid');
        }
        if ($data['scope_type'] === 'employee' && !$this->employeeExists((int) $data['scope_id'])) {
            $errors['employee_id'][] = $this->messageFor('employee_invalid');
        }

        return $errors;
    }

    private function assertNoOverlap(array $data, ?int $excludeId = null): void
    {
        if ((int) $data['is_active'] !== 1) {
            return;
        }
        $sql = 'SELECT id FROM work_schedule_assignments
                WHERE scope_type = :scope_type
                  AND is_active = 1
                  AND effective_from <= COALESCE(:new_to, "9999-12-31")
                  AND COALESCE(effective_to, "9999-12-31") >= :new_from';
        $params = [
            ':scope_type' => $data['scope_type'],
            ':new_to' => $data['effective_to'],
            ':new_from' => $data['effective_from'],
        ];
        if ($data['scope_type'] === 'company') {
            $sql .= ' AND scope_id IS NULL';
        } else {
            $sql .= ' AND scope_id = :scope_id';
            $params[':scope_id'] = $data['scope_id'];
        }
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }
        $sql .= ' LIMIT 1 FOR UPDATE';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        if ($statement->fetchColumn() !== false) {
            throw new AttendanceOperationException('overlap');
        }
    }

    private function normalizeFilters(array $input): array
    {
        return [
            'scope_type' => in_array((string) ($input['scope_type'] ?? ''), self::SCOPES, true) ? (string) $input['scope_type'] : '',
            'department_id' => $this->optionalInt($input['department_id'] ?? null),
            'employee_id' => $this->optionalInt($input['employee_id'] ?? null),
            'policy_id' => $this->optionalInt($input['policy_id'] ?? null),
            'status' => in_array((string) ($input['status'] ?? ''), ['active', 'historical', 'future', 'inactive'], true) ? (string) $input['status'] : '',
            'from' => $this->validDate((string) ($input['from'] ?? '')) ? (string) $input['from'] : '',
            'to' => $this->validDate((string) ($input['to'] ?? '')) ? (string) $input['to'] : '',
        ];
    }

    private function applyFilters(array $filters, array &$where, array &$params): void
    {
        if ($filters['scope_type'] !== '') {
            $where[] = 'wsa.scope_type = :filter_scope';
            $params[':filter_scope'] = $filters['scope_type'];
        }
        if ($filters['department_id'] !== null) {
            $where[] = "wsa.scope_type = 'department' AND wsa.scope_id = :filter_department";
            $params[':filter_department'] = $filters['department_id'];
        }
        if ($filters['employee_id'] !== null) {
            $where[] = "wsa.scope_type = 'employee' AND wsa.scope_id = :filter_employee";
            $params[':filter_employee'] = $filters['employee_id'];
        }
        if ($filters['policy_id'] !== null) {
            $where[] = 'wsa.work_schedule_template_id = :filter_policy';
            $params[':filter_policy'] = $filters['policy_id'];
        }
        if ($filters['from'] !== '') {
            $where[] = 'COALESCE(wsa.effective_to, "9999-12-31") >= :filter_from';
            $params[':filter_from'] = $filters['from'];
        }
        if ($filters['to'] !== '') {
            $where[] = 'wsa.effective_from <= :filter_to';
            $params[':filter_to'] = $filters['to'];
        }
        if ($filters['status'] !== '') {
            $today = date('Y-m-d');
            if ($filters['status'] === 'active') {
                $where[] = 'wsa.is_active = 1 AND wsa.effective_from <= :today_status_from AND (wsa.effective_to IS NULL OR wsa.effective_to >= :today_status_to)';
                $params[':today_status_from'] = $today;
                $params[':today_status_to'] = $today;
            } elseif ($filters['status'] === 'future') {
                $where[] = 'wsa.is_active = 1 AND wsa.effective_from > :today_status_future';
                $params[':today_status_future'] = $today;
            } elseif ($filters['status'] === 'historical') {
                $where[] = 'wsa.effective_to IS NOT NULL AND wsa.effective_to < :today_status_historical';
                $params[':today_status_historical'] = $today;
            } else {
                $where[] = 'wsa.is_active = 0';
            }
        }
    }

    private function joins(): string
    {
        return ' JOIN work_schedule_templates wst ON wst.id = wsa.work_schedule_template_id
                 LEFT JOIN departments d ON wsa.scope_type = "department" AND d.id = wsa.scope_id
                 LEFT JOIN users u ON wsa.scope_type = "employee" AND u.id = wsa.scope_id';
    }

    private function decorate(array $row): array
    {
        $today = date('Y-m-d');
        $isActiveNow = (int) ($row['is_active'] ?? 0) === 1
            && (string) ($row['effective_from'] ?? '') <= $today
            && (empty($row['effective_to']) || (string) $row['effective_to'] >= $today);
        $isFuture = (int) ($row['is_active'] ?? 0) === 1 && (string) ($row['effective_from'] ?? '') > $today;
        $row['period_status'] = $isActiveNow ? 'active' : ($isFuture ? 'future' : ((int) ($row['is_active'] ?? 0) === 1 ? 'historical' : 'inactive'));
        $row['priority'] = match ((string) ($row['scope_type'] ?? '')) {
            'employee' => 1,
            'department' => 2,
            default => 3,
        };

        return $row;
    }

    private function policyIsAssignable(int $id): bool
    {
        $statement = $this->connection->prepare("SELECT COUNT(*) FROM work_schedule_templates WHERE id = :id AND is_active = 1 AND lifecycle_status <> 'archived'");
        $statement->execute([':id' => $id]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function exists(string $table, int $id): bool
    {
        $statement = $this->connection->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE id = :id');
        $statement->execute([':id' => $id]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function employeeExists(int $id): bool
    {
        $statement = $this->connection->prepare("SELECT COUNT(*) FROM users WHERE id = :id AND role = 'employee'");
        $statement->execute([':id' => $id]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function lockById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM work_schedule_assignments WHERE id = :id LIMIT 1 FOR UPDATE');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    private function isHistorical(array $assignment): bool
    {
        $effectiveTo = trim((string) ($assignment['effective_to'] ?? ''));

        return $effectiveTo !== '' && $effectiveTo < date('Y-m-d');
    }

    private function prepareAndFetchInt(string $sql, array $params): int
    {
        $statement = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    private function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return $parsed === false ? null : (int) $parsed;
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function validDate(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function auditValues(array $assignment): array
    {
        return [
            'scope_type' => $assignment['scope_type'] ?? null,
            'scope_id' => $assignment['scope_id'] ?? null,
            'work_schedule_template_id' => $assignment['work_schedule_template_id'] ?? null,
            'effective_from' => $assignment['effective_from'] ?? null,
            'effective_to' => $assignment['effective_to'] ?? null,
            'is_active' => isset($assignment['is_active']) ? (int) $assignment['is_active'] : null,
            'reason' => $assignment['reason'] ?? null,
        ];
    }

    private function messageFor(string $reason): string
    {
        return Localization::current()->translate('workforce_policy.assignments.validation.' . $reason);
    }

    private function rollBack(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
    }
}
