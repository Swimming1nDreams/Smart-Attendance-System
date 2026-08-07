<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Localization;
use App\Models\CompanyProfile;
use App\Models\WorkScheduleTemplate;
use DateTimeZone;
use PDO;
use Throwable;

final class CompanyProfileService
{
    public const DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'm/d/Y'];
    public const TIME_FORMATS = ['H:i', 'h:i A'];

    public function __construct(
        private PDO $connection,
        private CompanyProfile $profiles,
        private WorkScheduleTemplate $schedules,
        private ?AuditLogService $auditLog = null,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function normalizeInput(array $input, ?array $fallback = null): array
    {
        return [
            'company_name' => mb_substr(trim((string) ($input['company_name'] ?? ($fallback['company_name'] ?? ''))), 0, 150),
            'short_name' => $this->nullable(mb_substr(trim((string) ($input['short_name'] ?? ($fallback['short_name'] ?? ''))), 0, 50)),
            'email' => $this->nullable(mb_substr(trim((string) ($input['email'] ?? ($fallback['email'] ?? ''))), 0, 190)),
            'phone' => $this->nullable(mb_substr(trim((string) ($input['phone'] ?? ($fallback['phone'] ?? ''))), 0, 50)),
            'address' => $this->nullable(mb_substr(trim((string) ($input['address'] ?? ($fallback['address'] ?? ''))), 0, 1000)),
            'timezone' => trim((string) ($input['timezone'] ?? ($fallback['timezone'] ?? 'Asia/Yangon'))),
            'date_format' => trim((string) ($input['date_format'] ?? ($fallback['date_format'] ?? 'Y-m-d'))),
            'time_format' => trim((string) ($input['time_format'] ?? ($fallback['time_format'] ?? 'H:i'))),
            'default_work_schedule_id' => (int) ($input['default_work_schedule_id'] ?? ($fallback['default_work_schedule_id'] ?? 0)),
        ];
    }

    /** @param array<string, mixed> $input @return array{ok: bool, errors: array<string, list<string>>, data: array<string, mixed>} */
    public function update(array $input, ?int $actorUserId, array $auditContext = []): array
    {
        $profile = $this->profiles->findSingle();
        $data = $this->normalizeInput($input, $profile);
        $errors = $this->validate($data);

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'data' => $data];
        }

        $this->connection->beginTransaction();
        try {
            $current = $this->profiles->findSingleForUpdate();
            if ($current === null) {
                $this->connection->rollBack();
                return ['ok' => false, 'errors' => ['form' => [$this->t('company_profile.validation.missing_profile')]], 'data' => $data];
            }

            $data['updated_by'] = $actorUserId;
            $this->profiles->update((int) $current['id'], $data);
            $this->auditLog?->record($actorUserId, 'company_profile.updated', 'company_profile', (int) $current['id'], 'Company profile updated',
                $this->auditProfile($current), $this->auditProfile($data), $auditContext);

            if ((string) ($current['timezone'] ?? '') !== (string) $data['timezone']) {
                $this->auditLog?->record($actorUserId, 'company_profile.timezone_changed', 'company_profile', (int) $current['id'], 'Company timezone changed',
                    ['timezone' => $current['timezone'] ?? null], ['timezone' => $data['timezone']], $auditContext);
            }

            if ((int) ($current['default_work_schedule_id'] ?? 0) !== (int) $data['default_work_schedule_id']) {
                $this->auditLog?->record($actorUserId, 'company_profile.default_schedule_changed', 'company_profile', (int) $current['id'], 'Default work schedule changed',
                    ['default_work_schedule_id' => (int) ($current['default_work_schedule_id'] ?? 0)],
                    ['default_work_schedule_id' => (int) $data['default_work_schedule_id']], $auditContext);
            }

            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }

        return ['ok' => true, 'errors' => [], 'data' => $data];
    }

    /** @param array<string, mixed> $data @return array<string, list<string>> */
    private function validate(array $data): array
    {
        $errors = [];

        if ($data['company_name'] === '') {
            $errors['company_name'][] = $this->t('company_profile.validation.company_name_required');
        }

        if ($data['email'] !== null && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'][] = $this->t('company_profile.validation.email_invalid');
        }

        if (!in_array($data['timezone'], DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'][] = $this->t('company_profile.validation.timezone_invalid');
        }

        if (!in_array($data['date_format'], self::DATE_FORMATS, true)) {
            $errors['date_format'][] = $this->t('company_profile.validation.date_format_invalid');
        }

        if (!in_array($data['time_format'], self::TIME_FORMATS, true)) {
            $errors['time_format'][] = $this->t('company_profile.validation.time_format_invalid');
        }

        if ((int) $data['default_work_schedule_id'] <= 0 || $this->schedules->findActiveById((int) $data['default_work_schedule_id']) === null) {
            $errors['default_work_schedule_id'][] = $this->t('company_profile.validation.default_schedule_invalid');
        }

        return $errors;
    }

    /** @param array<string, mixed> $values */
    private function auditProfile(array $values): array
    {
        return [
            'company_name' => $values['company_name'] ?? null,
            'short_name' => $values['short_name'] ?? null,
            'email' => $values['email'] ?? null,
            'phone' => $values['phone'] ?? null,
            'address' => $values['address'] ?? null,
            'timezone' => $values['timezone'] ?? null,
            'date_format' => $values['date_format'] ?? null,
            'time_format' => $values['time_format'] ?? null,
            'default_work_schedule_id' => $values['default_work_schedule_id'] ?? null,
        ];
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function t(string $key): string
    {
        return Localization::current()->translate($key);
    }
}
