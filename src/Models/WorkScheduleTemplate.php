<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

final class WorkScheduleTemplate
{
    public function __construct(private PDO $connection, private WorkScheduleDay $days)
    {
    }

    public function findAll(): array
    {
        $templates = $this->connection->query(
            'SELECT wst.*,
                    parent.name AS created_from_name,
                    assignment_counts.assignment_count,
                    CASE WHEN cp.default_work_schedule_id = wst.id THEN 1 ELSE 0 END AS is_company_default
             FROM work_schedule_templates wst
             LEFT JOIN work_schedule_templates parent ON parent.id = wst.created_from_template_id
             LEFT JOIN company_profiles cp ON cp.default_work_schedule_id = wst.id
             LEFT JOIN (
                SELECT work_schedule_template_id, COUNT(*) AS assignment_count
                FROM work_schedule_assignments
                GROUP BY work_schedule_template_id
             ) assignment_counts ON assignment_counts.work_schedule_template_id = wst.id
             ORDER BY wst.is_active DESC, wst.name ASC'
        )->fetchAll();

        return $this->attachDays($templates);
    }

    public function findActiveOptions(): array
    {
        return $this->connection->query(
            'SELECT id, name FROM work_schedule_templates WHERE is_active = 1 ORDER BY name ASC'
        )->fetchAll();
    }

    public function findActiveById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM work_schedule_templates WHERE id = :id AND is_active = 1 LIMIT 1');
        $statement->execute([':id' => $id]);
        $template = $statement->fetch();

        return $template === false ? null : $template;
    }

    public function findWithDays(int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT wst.*,
                    parent.name AS created_from_name,
                    assignment_counts.assignment_count,
                    CASE WHEN cp.default_work_schedule_id = wst.id THEN 1 ELSE 0 END AS is_company_default
             FROM work_schedule_templates wst
             LEFT JOIN work_schedule_templates parent ON parent.id = wst.created_from_template_id
             LEFT JOIN company_profiles cp ON cp.default_work_schedule_id = wst.id
             LEFT JOIN (
                SELECT work_schedule_template_id, COUNT(*) AS assignment_count
                FROM work_schedule_assignments
                GROUP BY work_schedule_template_id
             ) assignment_counts ON assignment_counts.work_schedule_template_id = wst.id
             WHERE wst.id = :id LIMIT 1'
        );
        $statement->execute([':id' => $id]);
        $template = $statement->fetch();
        if ($template === false) {
            return null;
        }

        $days = $this->days->findForTemplateIds([(int) $template['id']]);
        $template['days'] = $days[(int) $template['id']] ?? [];

        return $template;
    }

    /** @param array<string, mixed> $data @param list<array<string, mixed>> $days */
    public function create(array $data, array $days): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO work_schedule_templates
             (name, description, timezone, effective_from, effective_to, is_active, lifecycle_status, version_number, created_from_template_id, change_reason, created_by, updated_by)
             VALUES (:name, :description, :timezone, :effective_from, :effective_to, :is_active, :lifecycle_status, :version_number, :created_from_template_id, :change_reason, :created_by, :updated_by)'
        );
        $statement->execute([
            ':name' => $data['name'],
            ':description' => $data['description'],
            ':timezone' => $data['timezone'],
            ':effective_from' => $data['effective_from'],
            ':effective_to' => $data['effective_to'],
            ':is_active' => $data['is_active'],
            ':lifecycle_status' => $data['lifecycle_status'] ?? ((int) $data['is_active'] === 1 ? 'active' : 'draft'),
            ':version_number' => $data['version_number'] ?? 1,
            ':created_from_template_id' => $data['created_from_template_id'] ?? null,
            ':change_reason' => $data['change_reason'] ?? null,
            ':created_by' => $data['created_by'],
            ':updated_by' => $data['updated_by'],
        ]);

        $id = (int) $this->connection->lastInsertId();
        $this->days->replaceForTemplate($id, $days);

        return $id;
    }

    /** @param array<string, mixed> $data @param list<array<string, mixed>> $days */
    public function update(int $id, array $data, array $days): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE work_schedule_templates
             SET name = :name,
                 description = :description,
                 timezone = :timezone,
                 effective_from = :effective_from,
                 effective_to = :effective_to,
                 is_active = :is_active,
                 lifecycle_status = :lifecycle_status,
                 change_reason = :change_reason,
                 updated_by = :updated_by,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $updated = $statement->execute([
            ':name' => $data['name'],
            ':description' => $data['description'],
            ':timezone' => $data['timezone'],
            ':effective_from' => $data['effective_from'],
            ':effective_to' => $data['effective_to'],
            ':is_active' => $data['is_active'],
            ':lifecycle_status' => $data['lifecycle_status'] ?? ((int) $data['is_active'] === 1 ? 'active' : 'draft'),
            ':change_reason' => $data['change_reason'] ?? null,
            ':updated_by' => $data['updated_by'],
            ':id' => $id,
        ]);

        $this->days->replaceForTemplate($id, $days);

        return $updated;
    }

    public function setActive(int $id, bool $active, ?int $actorUserId): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE work_schedule_templates
             SET is_active = :is_active, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        return $statement->execute([
            ':is_active' => $active ? 1 : 0,
            ':updated_by' => $actorUserId,
            ':id' => $id,
        ]);
    }

    public function existsActiveByName(string $name, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM work_schedule_templates WHERE name = :name AND is_active = 1';
        if ($excludeId !== null) {
            $sql .= ' AND id != :id';
        }

        $statement = $this->connection->prepare($sql);
        $statement->bindValue(':name', $name, PDO::PARAM_STR);
        if ($excludeId !== null) {
            $statement->bindValue(':id', $excludeId, PDO::PARAM_INT);
        }
        $statement->execute();

        return (int) $statement->fetchColumn() > 0;
    }

    public function existsByName(string $name, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM work_schedule_templates WHERE name = :name';
        if ($excludeId !== null) {
            $sql .= ' AND id != :id';
        }

        $statement = $this->connection->prepare($sql);
        $statement->bindValue(':name', $name, PDO::PARAM_STR);
        if ($excludeId !== null) {
            $statement->bindValue(':id', $excludeId, PDO::PARAM_INT);
        }
        $statement->execute();

        return (int) $statement->fetchColumn() > 0;
    }

    public function isCompanyDefault(int $id): bool
    {
        $statement = $this->connection->prepare('SELECT COUNT(*) FROM company_profiles WHERE default_work_schedule_id = :id');
        $statement->execute([':id' => $id]);

        return (int) $statement->fetchColumn() > 0;
    }

    /** @param list<array<string, mixed>> $templates */
    private function attachDays(array $templates): array
    {
        $ids = array_map(static fn (array $template): int => (int) $template['id'], $templates);
        $days = $this->days->findForTemplateIds($ids);

        foreach ($templates as &$template) {
            $template['days'] = $days[(int) $template['id']] ?? [];
        }
        unset($template);

        return $templates;
    }
}
