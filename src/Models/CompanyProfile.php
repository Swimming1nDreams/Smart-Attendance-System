<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

final class CompanyProfile
{
    public function __construct(private PDO $connection)
    {
    }

    public function findSingle(): ?array
    {
        $statement = $this->connection->query(
            'SELECT cp.*, wst.name AS default_schedule_name
             FROM company_profiles cp
             LEFT JOIN work_schedule_templates wst ON wst.id = cp.default_work_schedule_id
             ORDER BY cp.id ASC LIMIT 1'
        );
        $profile = $statement->fetch();

        return $profile === false ? null : $profile;
    }

    public function findSingleForUpdate(): ?array
    {
        $statement = $this->connection->query('SELECT * FROM company_profiles ORDER BY id ASC LIMIT 1 FOR UPDATE');
        $profile = $statement->fetch();

        return $profile === false ? null : $profile;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE company_profiles
             SET company_name = :company_name,
                 short_name = :short_name,
                 email = :email,
                 phone = :phone,
                 address = :address,
                 timezone = :timezone,
                 date_format = :date_format,
                 time_format = :time_format,
                 default_work_schedule_id = :default_work_schedule_id,
                 updated_by = :updated_by,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        return $statement->execute([
            ':company_name' => $data['company_name'],
            ':short_name' => $data['short_name'],
            ':email' => $data['email'],
            ':phone' => $data['phone'],
            ':address' => $data['address'],
            ':timezone' => $data['timezone'],
            ':date_format' => $data['date_format'],
            ':time_format' => $data['time_format'],
            ':default_work_schedule_id' => $data['default_work_schedule_id'],
            ':updated_by' => $data['updated_by'],
            ':id' => $id,
        ]);
    }
}
