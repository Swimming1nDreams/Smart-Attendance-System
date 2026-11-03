<?php

declare(strict_types=1);

use App\Services\EmailVerificationMailer;

test('verification email contains a six-digit code and no verification link', function (): void {
    $mailer = new EmailVerificationMailer(['transport' => 'log']);
    $body = (string) call_private($mailer, 'buildVerificationBody', [
        'Test Employee',
        '012345',
        15,
    ]);

    assert_same('Your Office Attendance and Analytic System verification code', call_private($mailer, 'verificationSubject'));
    assert_contains('Office Attendance and Analytic System (OAAS)', $body);
    assert_contains('Your verification code is: 012345', $body);
    assert_contains('This code expires in 15 minutes.', $body);
    assert_contains('contact your HR administrator', $body);
    assert_not_contains('http://', $body);
    assert_not_contains('https://', $body);
});

test('verification endpoint and screen use a posted six-digit code', function (): void {
    $bootstrap = file_get_contents(project_path('public/index.php'));
    $view = file_get_contents(project_path('views/auth/email_verification.php'));
    $controller = file_get_contents(project_path('src/Controllers/AuthController.php'));

    assert_true($bootstrap !== false && $view !== false && $controller !== false);
    assert_contains("addRoute('POST', '/email-verification/verify'", $bootstrap);
    assert_not_contains("addRoute('GET', '/email-verification/verify'", $bootstrap);
    assert_contains('name="code"', $view);
    assert_contains('pattern="[0-9]{6}"', $view);
    assert_contains("\$guestBrandSubtitle = \$t('app.official_name');", $view);
    assert_contains("\$guestFrameVariant = 'verification';", $view);
    assert_contains("renderVerificationScreen(\$sent ? 'pending' : 'delivery_failed')", $controller);
});

test('local mail example still documents Gmail SMTP configuration', function (): void {
    $example = file_get_contents(project_path('config/mail.local.example.php'));

    assert_true($example !== false);
    assert_contains("'host' => 'smtp.gmail.com'", $example);
    assert_contains("'transport' => 'smtp'", $example);
});

test('unsupported mail transports fail instead of silently logging', function (): void {
    $mailer = new EmailVerificationMailer(['transport' => 'stmp']);

    assert_false($mailer->sendVerificationEmail('employee@example.test', 'Test Employee', '012345', 15));
});

test('log transport omits verification codes from log output', function (): void {
    $mailer = new EmailVerificationMailer(['transport' => 'log']);
    $source = file_get_contents(project_path('src/Services/EmailVerificationMailer.php'));

    assert_true(is_string($source));
    assert_contains("'log' => \$this->logEmail(\$recipientEmail, \$subject)", $source);
    assert_not_contains('logEmail(string $recipientEmail, string $subject, string $body)', $source);
    assert_true($mailer->sendVerificationEmail('employee@example.test', 'Test Employee', '012345', 15));
});

test('verification screens report delivery failures truthfully', function (): void {
    $controller = file_get_contents(project_path('src/Controllers/AuthController.php'));
    $view = file_get_contents(project_path('views/auth/email_verification.php'));
    $english = file_get_contents(project_path('lang/en.php'));
    $myanmar = file_get_contents(project_path('lang/my.php'));

    assert_true($controller !== false && $view !== false && $english !== false && $myanmar !== false);
    assert_contains("\$sent ? 'pending' : 'delivery_failed'", $controller);
    assert_contains("\$sent ? 'resent' : 'delivery_failed'", $controller);
    assert_contains("\$status === 'delivery_failed'", $view);
    assert_contains('email_verification_delivery_failed_message', $english);
    assert_contains('email_verification_delivery_failed_message', $myanmar);
});
