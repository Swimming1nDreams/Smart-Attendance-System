<?php

declare(strict_types=1);

$settings = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $settings['host'], $settings['name'], $settings['charset']),
    $settings['user'],
    $settings['pass'],
    $settings['options'],
);

$rows = $pdo->query('SELECT id, name, email, role, staff_code FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$invalid = array_values(array_filter(
    $rows,
    static fn (array $row): bool => preg_match('/\A[0-9]{6}\z/D', trim((string) ($row['staff_code'] ?? ''))) !== 1,
));

if ($invalid === []) {
    echo 'Staff Code audit passed: every account uses the required six-digit format.' . PHP_EOL;
    exit(0);
}

echo sprintf("Staff Code audit found %d account(s) requiring Administrator review:%s", count($invalid), PHP_EOL);
foreach ($invalid as $row) {
    echo sprintf(
        "- #%d %s <%s> [%s] current code: %s%s",
        (int) $row['id'],
        (string) $row['name'],
        (string) $row['email'],
        (string) $row['role'],
        trim((string) ($row['staff_code'] ?? '')) ?: '(blank)',
        PHP_EOL,
    );
}
echo 'Update each listed account from Employees > Edit. No code was changed automatically.' . PHP_EOL;
exit(2);
