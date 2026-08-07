<?php

declare(strict_types=1);

$officeNetworkEnabled = getenv('ATTENDANCE_OFFICE_NETWORK_ENABLED');
$officeAllowedIps = getenv('ATTENDANCE_OFFICE_ALLOWED_IPS');

return [
    'app' => [
        'base_url' => getenv('APP_BASE_URL') ?: 'http://localhost',
        'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Yangon',
    ],
    'attendance_network' => [
        'enabled' => $officeNetworkEnabled === false ? '0' : $officeNetworkEnabled,
        'allowed_ips' => $officeAllowedIps === false ? '127.0.0.1,::1' : $officeAllowedIps,
    ],
    'mail' => require __DIR__ . '/mail.php',
    'db' => require __DIR__ . '/database.php',
];
