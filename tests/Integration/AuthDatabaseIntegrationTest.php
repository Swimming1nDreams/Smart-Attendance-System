<?php

declare(strict_types=1);

use App\Core\SessionManager;
use App\Models\User;
use App\Services\AuthService;
use App\Services\EmailVerificationMailer;
use App\Services\EmailVerificationService;

test('database integration: active admin and employee users can log in', function (): void {
    $database = integration_database();
    $database->resetAndSeed();

    reset_test_session();
    $adminSession = new SessionManager();
    $adminAuth = new AuthService(new User($database->pdo()), $adminSession);

    assert_true($adminAuth->login('admin.integration@sapas.test', 'Password123!'));
    assert_true($adminSession->isAuthenticated());
    assert_same('admin', $adminSession->getUserRole());

    reset_test_session();
    $employeeSession = new SessionManager();
    $employeeAuth = new AuthService(new User($database->pdo()), $employeeSession);

    assert_true($employeeAuth->login('employee.integration@sapas.test', 'Password123!'));
    assert_true($employeeSession->isAuthenticated());
    assert_same('employee', $employeeSession->getUserRole());
});

test('database integration: inactive users cannot establish a session', function (): void {
    $database = integration_database();
    $database->resetAndSeed();

    reset_test_session();
    $session = new SessionManager();
    $auth = new AuthService(new User($database->pdo()), $session);

    assert_false($auth->login('inactive.integration@sapas.test', 'Password123!'));
    assert_false($session->isAuthenticated());
});

test('database integration: inactive administrators cannot establish a session', function (): void {
    $database = integration_database();
    $database->resetAndSeed();
    $database->pdo()->exec(
        "UPDATE users SET status = 'inactive' WHERE email = 'admin.integration@sapas.test'"
    );

    reset_test_session();
    $session = new SessionManager();
    $auth = new AuthService(new User($database->pdo()), $session);

    assert_false($auth->login('admin.integration@sapas.test', 'Password123!'));
    assert_same('inactive', $auth->getLastLoginFailureReason());
    assert_false($session->isAuthenticated());
});

test('database integration: unverified users are blocked until email verification succeeds', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();

    $statement = $pdo->prepare(
        'INSERT INTO users (name, email, password, role, department_id, position_id, status, staff_code)
         VALUES (:name, :email, :password, "employee", :department_id, :position_id, "active", "SAP-9101")'
    );
    $statement->execute([
        ':name' => 'Unverified Employee',
        ':email' => 'unverified.integration@sapas.test',
        ':password' => password_hash('Password123!', PASSWORD_DEFAULT),
        ':department_id' => $ids['department_id'],
        ':position_id' => $ids['position_id'],
    ]);
    $userId = (int) $pdo->lastInsertId();

    reset_test_session();
    $session = new SessionManager();
    $userModel = new User($pdo);
    $auth = new AuthService($userModel, $session);

    assert_false($auth->login('unverified.integration@sapas.test', 'Password123!'));
    assert_same('email_unverified', $auth->getLastLoginFailureReason());
    assert_false($session->isAuthenticated());

    $verification = $userModel->createVerificationCode($userId);
    assert_true(is_array($verification));
    assert_true(preg_match('/^\d{6}$/', (string) $verification['code']) === 1);

    $storedHash = $pdo->query('SELECT email_verification_code_hash FROM users WHERE id = ' . $userId)->fetchColumn();
    assert_true(is_string($storedHash));
    assert_not_contains((string) $verification['code'], $storedHash);
    assert_true(password_verify((string) $verification['code'], $storedHash));

    $service = new EmailVerificationService($userModel, new EmailVerificationMailer(['transport' => 'log']));
    assert_same('verified', $service->verifyCode('unverified.integration@sapas.test', (string) $verification['code']));
    assert_same('invalid', $service->verifyCode('unverified.integration@sapas.test', (string) $verification['code']));

    reset_test_session();
    $verifiedSession = new SessionManager();
    $verifiedAuth = new AuthService($userModel, $verifiedSession);

    assert_true($verifiedAuth->login('unverified.integration@sapas.test', 'Password123!'));
    assert_true($verifiedSession->isAuthenticated());
});

test('database integration: expired email verification codes fail without verifying the account', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();

    $statement = $pdo->prepare(
        'INSERT INTO users (name, email, password, role, department_id, position_id, status, staff_code)
         VALUES (:name, :email, :password, "employee", :department_id, :position_id, "active", "SAP-9102")'
    );
    $statement->execute([
        ':name' => 'Expired Token Employee',
        ':email' => 'expired-token.integration@sapas.test',
        ':password' => password_hash('Password123!', PASSWORD_DEFAULT),
        ':department_id' => $ids['department_id'],
        ':position_id' => $ids['position_id'],
    ]);
    $userId = (int) $pdo->lastInsertId();

    $userModel = new User($pdo);
    $verification = $userModel->createVerificationCode($userId);
    assert_true(is_array($verification));

    $expireStatement = $pdo->prepare('UPDATE users SET email_verification_expires_at = :expires_at WHERE id = :id');
    $expireStatement->execute([
        ':expires_at' => '2000-01-01 00:00:00',
        ':id' => $userId,
    ]);

    $service = new EmailVerificationService($userModel, new EmailVerificationMailer(['transport' => 'log']));
    assert_same('expired', $service->verifyCode('expired-token.integration@sapas.test', (string) $verification['code']));

    $verifiedAt = $pdo->query("SELECT email_verified_at FROM users WHERE email = 'expired-token.integration@sapas.test'")->fetchColumn();
    assert_same(null, $verifiedAt === false ? null : $verifiedAt);
});

test('database integration: wrong verification codes are limited to five attempts', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $statement = $pdo->prepare(
        'INSERT INTO users (name, email, password, role, department_id, position_id, status, staff_code)
         VALUES ("Attempt Employee", "attempt.integration@sapas.test", :password, "employee", :department_id, :position_id, "active", "SAP-9103")'
    );
    $statement->execute([
        ':password' => password_hash('Password123!', PASSWORD_DEFAULT),
        ':department_id' => $ids['department_id'],
        ':position_id' => $ids['position_id'],
    ]);

    $userModel = new User($pdo);
    $verification = $userModel->createVerificationCode((int) $pdo->lastInsertId());
    assert_true(is_array($verification));
    $service = new EmailVerificationService($userModel, new EmailVerificationMailer(['transport' => 'log']));

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        assert_same('invalid', $service->verifyCode('attempt.integration@sapas.test', '999999'));
    }

    assert_same('attempts_exceeded', $service->verifyCode('attempt.integration@sapas.test', '999999'));
    assert_same('attempts_exceeded', $service->verifyCode('attempt.integration@sapas.test', (string) $verification['code']));

    $lockedUser = $userModel->findByEmail('attempt.integration@sapas.test');
    assert_true(is_array($lockedUser));
    assert_true($service->sendForUser($lockedUser, true));
    $attemptsAfterResend = $pdo->query("SELECT email_verification_attempts FROM users WHERE email = 'attempt.integration@sapas.test'")->fetchColumn();
    assert_same(0, (int) $attemptsAfterResend);
});

test('database integration: resend rotates the code hash and resets attempts after cooldown', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $statement = $pdo->prepare(
        'INSERT INTO users (name, email, password, role, department_id, position_id, status, staff_code)
         VALUES ("Resend Employee", "resend.integration@sapas.test", :password, "employee", :department_id, :position_id, "active", "SAP-9104")'
    );
    $statement->execute([
        ':password' => password_hash('Password123!', PASSWORD_DEFAULT),
        ':department_id' => $ids['department_id'],
        ':position_id' => $ids['position_id'],
    ]);

    $userModel = new User($pdo);
    $service = new EmailVerificationService($userModel, new EmailVerificationMailer(['transport' => 'log']));
    $user = $userModel->findByEmail('resend.integration@sapas.test');
    assert_true(is_array($user));
    assert_true($service->sendForUser($user, false));
    $firstHash = $pdo->query("SELECT email_verification_code_hash FROM users WHERE email = 'resend.integration@sapas.test'")->fetchColumn();

    $pdo->exec("UPDATE users SET email_verification_sent_at = '2000-01-01 00:00:00', email_verification_attempts = 3 WHERE email = 'resend.integration@sapas.test'");
    $user = $userModel->findByEmail('resend.integration@sapas.test');
    assert_true(is_array($user));
    assert_true($service->sendForUser($user, true));
    $rotated = $pdo->query("SELECT email_verification_code_hash, email_verification_attempts FROM users WHERE email = 'resend.integration@sapas.test'")->fetch();

    assert_true(is_array($rotated));
    assert_true($firstHash !== $rotated['email_verification_code_hash']);
    assert_same(0, (int) $rotated['email_verification_attempts']);
});

test('database integration: administrator verification resend reports cooldown without rotating code', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $statement = $pdo->prepare(
        'INSERT INTO users (name, email, password, role, department_id, position_id, status, staff_code)
         VALUES ("Admin Resend Employee", "admin-resend.integration@sapas.test", :password, "employee", :department_id, :position_id, "active", "SAP-9105")'
    );
    $statement->execute([
        ':password' => password_hash('Password123!', PASSWORD_DEFAULT),
        ':department_id' => $ids['department_id'],
        ':position_id' => $ids['position_id'],
    ]);

    $userModel = new User($pdo);
    $service = new EmailVerificationService($userModel, new EmailVerificationMailer(['transport' => 'log']));
    $user = $userModel->findByEmail('admin-resend.integration@sapas.test');
    assert_true(is_array($user));

    assert_same(EmailVerificationService::SEND_SENT, $service->sendForAdminResend($user));
    $afterSend = $userModel->findByEmail('admin-resend.integration@sapas.test');
    assert_true(is_array($afterSend));
    $firstHash = (string) $afterSend['email_verification_code_hash'];
    assert_true($firstHash !== '');
    assert_not_contains('012345', $firstHash);

    assert_same(EmailVerificationService::SEND_COOLDOWN, $service->sendForAdminResend($afterSend));
    $afterCooldown = $userModel->findByEmail('admin-resend.integration@sapas.test');
    assert_true(is_array($afterCooldown));
    assert_same($firstHash, (string) $afterCooldown['email_verification_code_hash']);
});

test('database integration: administrator verification resend rejects inactive and verified accounts', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $statement = $pdo->prepare(
        'INSERT INTO users (name, email, password, role, department_id, position_id, status, staff_code, email_verified_at)
         VALUES (:name, :email, :password, "employee", :department_id, :position_id, :status, :staff_code, :verified_at)'
    );

    $statement->execute([
        ':name' => 'Inactive Unverified Employee',
        ':email' => 'inactive-unverified.integration@sapas.test',
        ':password' => password_hash('Password123!', PASSWORD_DEFAULT),
        ':department_id' => $ids['department_id'],
        ':position_id' => $ids['position_id'],
        ':status' => 'inactive',
        ':staff_code' => 'SAP-9106',
        ':verified_at' => null,
    ]);
    $statement->execute([
        ':name' => 'Verified Resend Employee',
        ':email' => 'verified-resend.integration@sapas.test',
        ':password' => password_hash('Password123!', PASSWORD_DEFAULT),
        ':department_id' => $ids['department_id'],
        ':position_id' => $ids['position_id'],
        ':status' => 'active',
        ':staff_code' => 'SAP-9107',
        ':verified_at' => '2026-01-01 00:00:00',
    ]);

    $userModel = new User($pdo);
    $service = new EmailVerificationService($userModel, new EmailVerificationMailer(['transport' => 'log']));
    $inactive = $userModel->findByEmail('inactive-unverified.integration@sapas.test');
    $verified = $userModel->findByEmail('verified-resend.integration@sapas.test');
    assert_true(is_array($inactive));
    assert_true(is_array($verified));

    assert_same(EmailVerificationService::SEND_INACTIVE, $service->sendForAdminResend($inactive));
    assert_same(EmailVerificationService::SEND_ALREADY_VERIFIED, $service->sendForAdminResend($verified));
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email IN ('inactive-unverified.integration@sapas.test', 'verified-resend.integration@sapas.test') AND email_verification_code_hash IS NOT NULL")->fetchColumn());
});
