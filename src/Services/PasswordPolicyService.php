<?php

declare(strict_types=1);

namespace App\Services;

final class PasswordPolicyService
{
    public const MINIMUM_LENGTH = 10;

    private const COMMON_PASSWORDS = [
        'password',
        'password123',
        '1234567890',
        'qwerty12345',
        'admin12345',
    ];

    /** @return list<string> */
    public function validate(string $password, ?string $confirmation = null, bool $confirmationRequired = true): array
    {
        $errors = [];

        if (trim($password) === '') {
            $errors[] = 'password_whitespace_only';
        }

        if (mb_strlen($password) < self::MINIMUM_LENGTH) {
            $errors[] = 'password_min_length';
        }

        if (preg_match('/\p{L}/u', $password) !== 1) {
            $errors[] = 'password_letter_required';
        }

        if (preg_match('/\d/', $password) !== 1) {
            $errors[] = 'password_number_required';
        }

        if (in_array(mb_strtolower(trim($password)), self::COMMON_PASSWORDS, true)) {
            $errors[] = 'password_too_common';
        }

        if ($confirmationRequired && ($confirmation === null || !hash_equals($password, $confirmation))) {
            $errors[] = 'password_confirmation_mismatch';
        }

        return array_values(array_unique($errors));
    }

    public function assertValid(string $password, ?string $confirmation = null, bool $confirmationRequired = true): void
    {
        $errors = $this->validate($password, $confirmation, $confirmationRequired);

        if ($errors !== []) {
            throw new PasswordPolicyException($errors);
        }
    }
}
