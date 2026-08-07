<?php

declare(strict_types=1);

namespace App\Services;

final class AttendanceNetworkAccessService
{
    private bool $enabled;
    private bool $configurationValid = true;

    /** @var array<string, true> */
    private array $allowedAddresses = [];

    /** @param array{enabled?: mixed, allowed_ips?: mixed} $config */
    public function __construct(array $config = [], private ?AuditLogService $auditLog = null)
    {
        $this->enabled = $this->normalizeEnabled($config['enabled'] ?? false);

        $allowedIps = $config['allowed_ips'] ?? [];
        if (is_string($allowedIps)) {
            $allowedIps = explode(',', $allowedIps);
        }
        if (!is_array($allowedIps)) {
            $this->configurationValid = false;
            return;
        }

        foreach ($allowedIps as $allowedIp) {
            if (!is_scalar($allowedIp)) {
                $this->configurationValid = false;
                continue;
            }

            $allowedIp = trim((string) $allowedIp);
            if ($allowedIp === '') {
                continue;
            }

            $canonical = $this->canonicalAddress($allowedIp);
            if ($canonical === null) {
                $this->configurationValid = false;
                continue;
            }

            $this->allowedAddresses[$canonical] = true;
        }
    }

    public function isAllowed(string $remoteAddress): bool
    {
        if (!$this->enabled) {
            return true;
        }

        if (!$this->configurationValid || $this->allowedAddresses === []) {
            return false;
        }

        $canonical = $this->canonicalAddress($remoteAddress);

        return $canonical !== null && isset($this->allowedAddresses[$canonical]);
    }

    /** @param array<string, mixed> $auditContext */
    public function authorizeSelfAttendance(string $remoteAddress, int $userId, string $operation, array $auditContext = []): bool
    {
        if ($this->isAllowed($remoteAddress)) {
            return true;
        }

        $operation = in_array($operation, ['clock_in', 'clock_out'], true) ? $operation : 'self_attendance';
        $auditContext['ip_address'] = trim($remoteAddress);
        $this->auditLog?->record(
            $userId,
            'attendance.network_access_denied',
            'user',
            $userId,
            'Employee self-attendance blocked outside approved office network',
            null,
            ['operation' => $operation],
            $auditContext,
            false,
        );

        return false;
    }

    private function normalizeEnabled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return is_string($value)
            && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function canonicalAddress(string $ipAddress): ?string
    {
        $ipAddress = trim($ipAddress);
        if ($ipAddress === '' || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ipAddress);

        return $packed === false ? null : bin2hex($packed);
    }
}
