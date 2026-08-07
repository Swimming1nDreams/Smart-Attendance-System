<?php

declare(strict_types=1);

$defaults = [
    'transport' => 'log',
    'host' => '',
    'port' => 587,
    'username' => '',
    'password' => '',
    'encryption' => 'tls',
    'from_email' => 'no-reply@sapas.local',
    'from_name' => 'Smart Attendance',
    'verification_subject' => 'Verify your Smart Attendance account email',
    'local_host' => '',
];

$localConfigPath = __DIR__ . '/mail.local.php';
$local = is_file($localConfigPath) ? require $localConfigPath : [];

if (!is_array($local)) {
    $local = [];
}

$config = array_replace($defaults, $local);

$envMap = [
    'MAIL_TRANSPORT' => 'transport',
    'MAIL_HOST' => 'host',
    'MAIL_PORT' => 'port',
    'MAIL_USERNAME' => 'username',
    'MAIL_PASSWORD' => 'password',
    'MAIL_ENCRYPTION' => 'encryption',
    'MAIL_FROM_EMAIL' => 'from_email',
    'MAIL_FROM_NAME' => 'from_name',
    'MAIL_VERIFICATION_SUBJECT' => 'verification_subject',
    'MAIL_LOCAL_HOST' => 'local_host',
];

foreach ($envMap as $envName => $configKey) {
    $value = getenv($envName);

    if ($value === false || $value === '') {
        continue;
    }

    $config[$configKey] = $configKey === 'port' ? (int) $value : $value;
}

$config['port'] = (int) ($config['port'] ?? 587);

return $config;
