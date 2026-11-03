<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class DemoAccountRefreshService
{
    private const DEFAULT_DOMAIN = 'silveroakhr.com';
    private const ADDRESSES = [
        'No. 24, Inya View Street, Kamayut Township, Yangon',
        'No. 18, Shwe Hintha Lane, Hlaing Township, Yangon',
        'No. 41, Myitta Garden Road, Tamwe Township, Yangon',
        'No. 12, Pyay Garden Lane, Sanchaung Township, Yangon',
        'No. 33, Golden Valley Street, Bahan Township, Yangon',
        'No. 27, Thiri Avenue, Yankin Township, Yangon',
        'No. 56, Pinlon Park Road, Mayangone Township, Yangon',
        'No. 15, Waizayantar Lane, Thingangyun Township, Yangon',
        'No. 38, Shwe Taung Tan Street, Lanmadaw Township, Yangon',
        'No. 21, Bo Myat Htun Lane, Pazundaung Township, Yangon',
        'No. 45, Ahlone Garden Road, Ahlone Township, Yangon',
        'No. 30, Kandawgyi View Lane, Mingalar Taung Nyunt Township, Yangon',
        'No. 19, University Avenue, Kamayut Township, Yangon',
        'No. 62, Bayint Naung Garden, Hlaing Township, Yangon',
        'No. 11, Parami View Street, Yankin Township, Yangon',
    ];

    private string $domain;

    public function __construct(
        private PDO $connection,
        private PasswordPolicyService $passwordPolicy,
        private ?AuditLogService $auditLog = null,
        ?string $domain = null,
    ) {
        $environmentDomain = getenv('OAAS_DEMO_EMAIL_DOMAIN') ?: getenv('SAPAS_DEMO_EMAIL_DOMAIN');
        $domain = mb_strtolower(trim($domain ?? (is_string($environmentDomain) && $environmentDomain !== '' ? $environmentDomain : self::DEFAULT_DOMAIN)));
        if (preg_match('/^(?!-)[a-z0-9.-]+(?<!-)\.[a-z]{2,}$/', $domain) !== 1) {
            throw new RuntimeException('OAAS_DEMO_EMAIL_DOMAIN must be a valid domain name.');
        }
        $this->domain = $domain;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function preview(): array
    {
        return $this->plan(false);
    }

    public function apply(string $privateDirectory): array
    {
        $accounts = $this->plan(true);
        if ($accounts === []) {
            throw new RuntimeException('No demonstration accounts were found.');
        }
        $before = $this->preservationSnapshot();
        $credentialPath = '';
        $this->connection->beginTransaction();
        try {
            $columns = $this->userColumns();
            $assignments = [
                'email = :email', 'password = :password', 'staff_code = :staff_code',
                'session_version = session_version + 1', 'email_verified_at = COALESCE(email_verified_at, NOW())',
            ];
            foreach (['email_verification_token', 'email_verification_code_hash', 'email_verification_expires_at', 'email_verification_sent_at'] as $column) {
                if (isset($columns[$column])) {
                    $assignments[] = $column . ' = NULL';
                }
            }
            if (isset($columns['email_verification_attempts'])) {
                $assignments[] = 'email_verification_attempts = 0';
            }
            foreach (['password_reset_token', 'password_reset_expires_at', 'remember_token'] as $column) {
                if (isset($columns[$column])) {
                    $assignments[] = $column . ' = NULL';
                }
            }
            $updateUser = $this->connection->prepare('UPDATE users SET ' . implode(', ', $assignments) . ' WHERE id = :id');
            $upsertProfile = $this->connection->prepare(
                'INSERT INTO employee_profiles (user_id, phone, address) VALUES (:user_id, :phone, :address)
                 ON DUPLICATE KEY UPDATE phone = VALUES(phone), address = VALUES(address)'
            );
            $processed = 0;
            $failAfter = max(0, (int) (getenv('OAAS_DEMO_REFRESH_FAIL_AFTER') ?: getenv('SAPAS_DEMO_REFRESH_FAIL_AFTER')));
            foreach ($accounts as $account) {
                $updateUser->execute([
                    ':email' => $account['email'], ':password' => password_hash((string) $account['temporary_password'], PASSWORD_DEFAULT),
                    ':staff_code' => $account['staff_code'], ':id' => $account['id'],
                ]);
                if ($updateUser->rowCount() !== 1) {
                    throw new RuntimeException('Demo account update did not affect exactly one row for user ' . $account['id']);
                }
                $upsertProfile->execute([':user_id' => $account['id'], ':phone' => $account['phone'], ':address' => $account['address']]);
                $processed++;
                if ($failAfter > 0 && $processed >= $failAfter) {
                    throw new RuntimeException('Injected demo refresh failure.');
                }
            }
            $this->assertPreserved($before);
            $this->auditLog?->record(null, 'demo.accounts_refreshed', 'demo_account_set', $this->domain,
                'Demonstration accounts refreshed', null, ['account_count' => count($accounts), 'email_domain' => $this->domain], [], true);

            $credentialPath = $this->writeCredentials($privateDirectory, $accounts);
            $this->connection->commit();
            return ['accounts' => $accounts, 'credential_path' => $credentialPath];
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            if ($credentialPath !== '' && is_file($credentialPath)) {
                @unlink($credentialPath);
            }
            throw $exception;
        }
    }

    private function plan(bool $withPasswords): array
    {
        $users = $this->connection->query(
            'SELECT u.id, u.name, u.role, u.status, u.staff_code, ep.phone, ep.address
             FROM users u LEFT JOIN employee_profiles ep ON ep.user_id = u.id ORDER BY u.id ASC'
        )->fetchAll();
        $slugCounts = [];
        $accounts = [];
        foreach ($users as $index => $user) {
            $base = $this->nameSlug((string) $user['name']);
            $slugCounts[$base] = ($slugCounts[$base] ?? 0) + 1;
            $slug = $base . ($slugCounts[$base] > 1 ? (string) $slugCounts[$base] : '');
            $staffCode = trim((string) ($user['staff_code'] ?? ''));
            if ($staffCode === '') {
                $staffCode = 'SAP-' . str_pad((string) (int) $user['id'], 4, '0', STR_PAD_LEFT);
            }
            $accounts[] = [
                'id' => (int) $user['id'], 'staff_code' => $staffCode, 'name' => (string) $user['name'],
                'role' => (string) $user['role'], 'status' => (string) $user['status'],
                'email' => $slug . '@' . $this->domain,
                'phone' => sprintf('09 700 %03d %03d', 100 + intdiv($index, 999), ($index % 999) + 1),
                'address' => self::ADDRESSES[$index % count(self::ADDRESSES)],
                'temporary_password' => $withPasswords ? $this->password() : '[generated on apply]',
            ];
        }
        return $accounts;
    }

    private function nameSlug(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9]+/u', '.', $name) ?? '';
        $name = trim($name, '.');
        if ($name === '' || in_array($name, ['admin', 'employee', 'user'], true)) {
            throw new RuntimeException('Every demo account must have a person-name suitable for a name-based email address.');
        }
        return $name;
    }

    private function password(): string
    {
        do {
            $password = 'Aq!' . rtrim(strtr(base64_encode(random_bytes(15)), '+/', 'XZ'), '=') . '7z';
            $errors = $this->passwordPolicy->validate($password, $password);
        } while ($errors !== [] || strlen($password) < 16 || preg_match('/[A-Z]/', $password) !== 1 || preg_match('/[a-z]/', $password) !== 1 || preg_match('/\d/', $password) !== 1 || preg_match('/[^A-Za-z0-9]/', $password) !== 1);
        return $password;
    }

    private function preservationSnapshot(): array
    {
        $snapshot = [];
        foreach (['users', 'attendance_records', 'leave_requests', 'notifications'] as $table) {
            $snapshot[$table] = [
                'count' => (int) $this->connection->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(),
                'ids' => (string) $this->connection->query('SELECT COALESCE(GROUP_CONCAT(id ORDER BY id), "") FROM ' . $table)->fetchColumn(),
            ];
        }
        return $snapshot;
    }

    private function assertPreserved(array $before): void
    {
        if ($this->preservationSnapshot() !== $before) {
            throw new RuntimeException('Demo refresh preservation check failed; IDs or historical row counts changed.');
        }
    }

    private function userColumns(): array
    {
        $columns = [];
        foreach ($this->connection->query('SHOW COLUMNS FROM users') as $column) {
            $columns[(string) $column['Field']] = true;
        }
        return $columns;
    }

    private function writeCredentials(string $directory, array $accounts): string
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create private credential directory.');
        }
        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'demo-credentials-' . date('Ymd-His') . '.txt';
        if (file_put_contents($path, $this->credentialText($accounts), LOCK_EX) === false) {
            throw new RuntimeException('Unable to write private credential file.');
        }
        @chmod($path, 0600);
        return $path;
    }

    public function credentialText(array $accounts): string
    {
        $lines = ['OAAS DEMONSTRATION ACCOUNTS', '', '| Staff Code | Name | Role | Email | Phone | Address | Temporary Password |',
            '| --- | --- | --- | --- | --- | --- | --- |'];
        foreach ($accounts as $account) {
            $cells = array_map(static fn (mixed $value): string => str_replace('|', '\\|', (string) $value), [
                $account['staff_code'], $account['name'], $account['role'], $account['email'], $account['phone'], $account['address'], $account['temporary_password'],
            ]);
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
        }
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
