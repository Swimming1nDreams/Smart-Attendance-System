<?php

declare(strict_types=1);

namespace App\Services;

final class AuditSourcePresenter
{
    /** @return array{network: string, ip_address: ?string, user_agent_summary: ?string} */
    public static function describe(mixed $ipAddress, mixed $userAgent = null): array
    {
        $ipAddress = trim((string) $ipAddress);

        return [
            'network' => self::classifyIp($ipAddress),
            'ip_address' => filter_var($ipAddress, FILTER_VALIDATE_IP) ? $ipAddress : null,
            'user_agent_summary' => self::summarizeUserAgent($userAgent),
        ];
    }

    public static function classifyIp(string $ipAddress): string
    {
        $ipAddress = trim($ipAddress);
        if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            return 'unknown';
        }

        if ($ipAddress === '::1' || self::isIpv4Loopback($ipAddress)) {
            return 'localhost';
        }

        if (self::isPrivateIpv4($ipAddress)) {
            return 'private_network';
        }

        return 'public_network';
    }

    public static function summarizeUserAgent(mixed $userAgent): ?string
    {
        $userAgent = trim((string) $userAgent);
        if ($userAgent === '') {
            return null;
        }

        $browser = self::browserName($userAgent);
        $platform = self::platformName($userAgent);
        $parts = array_values(array_filter([$browser, $platform], static fn (?string $value): bool => $value !== null && $value !== ''));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private static function isIpv4Loopback(string $ipAddress): bool
    {
        $parts = explode('.', $ipAddress);

        return count($parts) === 4 && $parts[0] === '127';
    }

    private static function isPrivateIpv4(string $ipAddress): bool
    {
        $parts = array_map('intval', explode('.', $ipAddress));
        if (count($parts) !== 4) {
            return false;
        }

        return $parts[0] === 10
            || ($parts[0] === 172 && $parts[1] >= 16 && $parts[1] <= 31)
            || ($parts[0] === 192 && $parts[1] === 168);
    }

    private static function browserName(string $userAgent): ?string
    {
        return match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };
    }

    private static function platformName(string $userAgent): ?string
    {
        return match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };
    }
}
