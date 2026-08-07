<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

final class AuditLogService
{
    private const SENSITIVE_KEY_PATTERN = '/password(?!_changed)|hash|verification_code|verification_token|reset_token|remember_token|secret|session_id|session_token|csrf|cookie|authorization|smtp|credential/i';

    public function __construct(private PDO $connection)
    {
    }

    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     * @param array<string, mixed> $context
     */
    public function record(
        ?int $actorUserId,
        string $action,
        string $entityType,
        string|int|null $entityId = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $context = [],
        bool $critical = true,
    ): bool {
        try {
            $statement = $this->connection->prepare(
                'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, description, old_values, new_values, ip_address, user_agent, created_at)
                 VALUES (:actor_user_id, :action, :entity_type, :entity_id, :description, :old_values, :new_values, :ip_address, :user_agent, NOW())'
            );
            $statement->execute([
                ':actor_user_id' => $actorUserId,
                ':action' => mb_substr(trim($action), 0, 100),
                ':entity_type' => mb_substr(trim($entityType), 0, 100),
                ':entity_id' => $entityId === null ? null : mb_substr((string) $entityId, 0, 100),
                ':description' => $description === null ? null : mb_substr(trim($description), 0, 255),
                ':old_values' => $this->encode($oldValues),
                ':new_values' => $this->encode($newValues),
                ':ip_address' => $this->normalizeIp($context['ip_address'] ?? null),
                ':user_agent' => $this->normalizeUserAgent($context['user_agent'] ?? null),
            ]);

            return true;
        } catch (Throwable $exception) {
            if ($critical) {
                throw $exception;
            }
            error_log('Best-effort audit event failed: ' . $exception::class);
            return false;
        }
    }

    /** @param array<string, mixed> $server */
    public function requestContext(array $server): array
    {
        return [
            'ip_address' => trim((string) ($server['REMOTE_ADDR'] ?? '')),
            'user_agent' => trim((string) ($server['HTTP_USER_AGENT'] ?? '')),
        ];
    }

    /** @param array<string, mixed>|null $values */
    public function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }
        $safe = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            if (preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1) {
                $safe[$key] = '[REDACTED]';
                continue;
            }
            $safe[$key] = is_array($value) ? $this->redact($value) : $this->normalizeScalar($value);
        }

        return $safe;
    }

    private function encode(?array $values): ?string
    {
        if ($values === null) {
            return null;
        }
        return json_encode($this->redact($values), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function normalizeScalar(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            return mb_substr($value, 0, 2000);
        }
        return (string) $value;
    }

    private function normalizeIp(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' && filter_var($value, FILTER_VALIDATE_IP) ? mb_substr($value, 0, 45) : null;
    }

    private function normalizeUserAgent(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
