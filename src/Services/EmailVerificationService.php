<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

class EmailVerificationService
{
    public const CODE_EXPIRY_MINUTES = 15;
    public const SEND_SENT = 'sent';
    public const SEND_ALREADY_VERIFIED = 'already_verified';
    public const SEND_COOLDOWN = 'cooldown';
    public const SEND_INACTIVE = 'inactive';
    public const SEND_FAILED = 'failed';
    private const CODE_EXPIRY_SECONDS = self::CODE_EXPIRY_MINUTES * 60;
    private const RESEND_COOLDOWN_SECONDS = 600;
    private const MAX_ATTEMPTS = 5;

    private User $userModel;
    private EmailVerificationMailer $mailer;
    public function __construct(User $userModel, EmailVerificationMailer $mailer)
    {
        $this->userModel = $userModel;
        $this->mailer = $mailer;
    }

    public function sendForUser(array $user, bool $respectCooldown = true): bool
    {
        if (($user['email_verified_at'] ?? null) !== null) {
            return true;
        }

        $attemptsExhausted = (int) ($user['email_verification_attempts'] ?? 0) >= self::MAX_ATTEMPTS;

        if ($respectCooldown && !$attemptsExhausted && $this->userModel->hasRecentVerificationSend($user, self::RESEND_COOLDOWN_SECONDS)) {
            return true;
        }

        return $this->sendNewCode($user);
    }

    public function sendForAdminResend(array $user): string
    {
        if (($user['email_verified_at'] ?? null) !== null) {
            return self::SEND_ALREADY_VERIFIED;
        }

        if (($user['status'] ?? '') !== 'active') {
            return self::SEND_INACTIVE;
        }

        $attemptsExhausted = (int) ($user['email_verification_attempts'] ?? 0) >= self::MAX_ATTEMPTS;
        if (!$attemptsExhausted && $this->userModel->hasRecentVerificationSend($user, self::RESEND_COOLDOWN_SECONDS)) {
            return self::SEND_COOLDOWN;
        }

        return $this->sendNewCode($user) ? self::SEND_SENT : self::SEND_FAILED;
    }

    public function sendForEmail(string $email): bool
    {
        $user = $this->userModel->findByEmail($email);

        if ($user === null || ($user['status'] ?? '') !== 'active' || ($user['email_verified_at'] ?? null) !== null) {
            return true;
        }

        return $this->sendForUser($user, true);
    }

    public function verifyCode(string $email, string $code): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/^\d{6}$/', $code) !== 1) {
            return 'invalid';
        }

        $user = $this->userModel->findByEmail($email);

        if ($user === null || ($user['status'] ?? '') !== 'active' || ($user['email_verified_at'] ?? null) !== null) {
            return 'invalid';
        }

        if ((int) ($user['email_verification_attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            return 'attempts_exceeded';
        }

        $expiresAt = strtotime((string) ($user['email_verification_expires_at'] ?? ''));

        if ($expiresAt === false || $expiresAt <= time()) {
            return 'expired';
        }

        if (!$this->userModel->verificationCodeMatches($user, $code)) {
            $attempts = $this->userModel->recordFailedVerificationAttempt((int) $user['id']);
            return $attempts >= self::MAX_ATTEMPTS ? 'attempts_exceeded' : 'invalid';
        }

        if ($this->userModel->markEmailVerified((int) $user['id'])) {
            return 'verified';
        }

        return 'invalid';
    }

    private function sendNewCode(array $user): bool
    {
        $verification = $this->userModel->createVerificationCode((int) $user['id'], self::CODE_EXPIRY_SECONDS);

        if ($verification === null) {
            return false;
        }

        $sent = $this->mailer->sendVerificationEmail(
            (string) $user['email'],
            (string) $user['name'],
            (string) $verification['code'],
            self::CODE_EXPIRY_MINUTES,
        );

        if (!$sent) {
            $this->userModel->clearVerificationCode((int) $user['id']);
        }

        return $sent;
    }
}
