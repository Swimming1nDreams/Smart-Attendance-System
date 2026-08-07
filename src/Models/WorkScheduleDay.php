<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

final class WorkScheduleDay
{
    public function __construct(private PDO $connection)
    {
    }

    /** @param list<int> $templateIds @return array<int, list<array<string, mixed>>> */
    public function findForTemplateIds(array $templateIds): array
    {
        $templateIds = array_values(array_unique(array_filter($templateIds, static fn (int $id): bool => $id > 0)));
        if ($templateIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($templateIds), '?'));
        $statement = $this->connection->prepare(
            'SELECT * FROM work_schedule_days
             WHERE work_schedule_template_id IN (' . $placeholders . ')
             ORDER BY work_schedule_template_id ASC, day_of_week ASC'
        );
        foreach ($templateIds as $index => $id) {
            $statement->bindValue($index + 1, $id, PDO::PARAM_INT);
        }
        $statement->execute();

        $grouped = [];
        foreach ($statement->fetchAll() as $row) {
            $grouped[(int) $row['work_schedule_template_id']][] = $row;
        }

        return $grouped;
    }

    /** @param list<array<string, mixed>> $days */
    public function replaceForTemplate(int $templateId, array $days): void
    {
        $delete = $this->connection->prepare('DELETE FROM work_schedule_days WHERE work_schedule_template_id = :template_id');
        $delete->execute([':template_id' => $templateId]);

        $insert = $this->connection->prepare(
            'INSERT INTO work_schedule_days
             (work_schedule_template_id, day_of_week, is_working_day, start_time, end_time, is_overnight, break_start_time, break_end_time, break_minutes, grace_minutes, expected_minutes)
             VALUES (:template_id, :day_of_week, :is_working_day, :start_time, :end_time, :is_overnight, :break_start_time, :break_end_time, :break_minutes, :grace_minutes, :expected_minutes)'
        );

        foreach ($days as $day) {
            $insert->execute([
                ':template_id' => $templateId,
                ':day_of_week' => $day['day_of_week'],
                ':is_working_day' => $day['is_working_day'],
                ':start_time' => $day['start_time'],
                ':end_time' => $day['end_time'],
                ':is_overnight' => $day['is_overnight'],
                ':break_start_time' => $day['break_start_time'] ?? null,
                ':break_end_time' => $day['break_end_time'] ?? null,
                ':break_minutes' => $day['break_minutes'],
                ':grace_minutes' => $day['grace_minutes'],
                ':expected_minutes' => $day['expected_minutes'],
            ]);
        }
    }
}
