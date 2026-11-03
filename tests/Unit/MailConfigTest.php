<?php

declare(strict_types=1);

test('mail config defaults to log mode and exposes SMTP fields', function (): void {
    $originalTransport = getenv('MAIL_TRANSPORT');
    $originalHost = getenv('MAIL_HOST');
    $originalPort = getenv('MAIL_PORT');
    $originalFromName = getenv('MAIL_FROM_NAME');
    $originalSubject = getenv('MAIL_VERIFICATION_SUBJECT');
    $originalLocalHost = getenv('MAIL_LOCAL_HOST');

    putenv('MAIL_TRANSPORT');
    putenv('MAIL_HOST');
    putenv('MAIL_PORT');
    putenv('MAIL_FROM_NAME');
    putenv('MAIL_VERIFICATION_SUBJECT');
    putenv('MAIL_LOCAL_HOST');

    try {
        $config = load_mail_config_without_local_overrides();

        assert_same('log', $config['transport']);
        assert_same('', $config['host']);
        assert_same(587, $config['port']);
        assert_same('no-reply@oaas.local', $config['from_email']);
        assert_same('Office Attendance and Analytic System (OAAS)', $config['from_name']);
        assert_same('Your Office Attendance and Analytic System verification code', $config['verification_subject']);
        assert_same('', $config['local_host']);
        assert_true(array_key_exists('username', $config));
        assert_true(array_key_exists('password', $config));
        assert_true(is_file(project_path('config/mail.local.example.php')));
    } finally {
        restore_env_value('MAIL_TRANSPORT', $originalTransport);
        restore_env_value('MAIL_HOST', $originalHost);
        restore_env_value('MAIL_PORT', $originalPort);
        restore_env_value('MAIL_FROM_NAME', $originalFromName);
        restore_env_value('MAIL_VERIFICATION_SUBJECT', $originalSubject);
        restore_env_value('MAIL_LOCAL_HOST', $originalLocalHost);
    }
});

test('mail config accepts SMTP environment overrides', function (): void {
    $originalTransport = getenv('MAIL_TRANSPORT');
    $originalHost = getenv('MAIL_HOST');
    $originalPort = getenv('MAIL_PORT');
    $originalLocalHost = getenv('MAIL_LOCAL_HOST');

    putenv('MAIL_TRANSPORT=smtp');
    putenv('MAIL_HOST=smtp.gmail.com');
    putenv('MAIL_PORT=587');
    putenv('MAIL_LOCAL_HOST=192.168.1.15:8000');

    try {
        $config = require project_path('config/mail.php');

        assert_same('smtp', $config['transport']);
        assert_same('smtp.gmail.com', $config['host']);
        assert_same(587, $config['port']);
        assert_same('192.168.1.15:8000', $config['local_host']);
    } finally {
        restore_env_value('MAIL_TRANSPORT', $originalTransport);
        restore_env_value('MAIL_HOST', $originalHost);
        restore_env_value('MAIL_PORT', $originalPort);
        restore_env_value('MAIL_LOCAL_HOST', $originalLocalHost);
    }
});

function restore_env_value(string $name, string|false $value): void
{
    if ($value === false) {
        putenv($name);
        return;
    }

    putenv($name . '=' . $value);
}

function load_mail_config_without_local_overrides(): array
{
    $temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sapas-mail-config-' . bin2hex(random_bytes(8));

    if (!mkdir($temporaryDirectory, 0777, true) && !is_dir($temporaryDirectory)) {
        throw new RuntimeException('Unable to create temporary mail config directory.');
    }

    $temporaryConfig = $temporaryDirectory . DIRECTORY_SEPARATOR . 'mail.php';

    if (!copy(project_path('config/mail.php'), $temporaryConfig)) {
        rmdir($temporaryDirectory);
        throw new RuntimeException('Unable to copy mail config for isolated defaults test.');
    }

    try {
        return require $temporaryConfig;
    } finally {
        unlink($temporaryConfig);
        rmdir($temporaryDirectory);
    }
}
