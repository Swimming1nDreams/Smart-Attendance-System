<?php

declare(strict_types=1);

use App\Services\PasswordPolicyException;
use App\Services\PasswordPolicyService;

test('password policy rejects passwords shorter than ten characters', function (): void {
    $errors = (new PasswordPolicyService())->validate('Abc12345', 'Abc12345');
    assert_true(in_array('password_min_length', $errors, true));
});

test('password policy rejects passwords without a letter', function (): void {
    $errors = (new PasswordPolicyService())->validate('1234567890', '1234567890');
    assert_true(in_array('password_letter_required', $errors, true));
});

test('password policy rejects passwords without a number', function (): void {
    $errors = (new PasswordPolicyService())->validate('StrongLettersOnly', 'StrongLettersOnly');
    assert_true(in_array('password_number_required', $errors, true));
});

test('password policy rejects common passwords case-insensitively', function (): void {
    $errors = (new PasswordPolicyService())->validate('PASSWORD123', 'PASSWORD123');
    assert_true(in_array('password_too_common', $errors, true));
});

test('password policy rejects mismatched confirmation', function (): void {
    $errors = (new PasswordPolicyService())->validate('SecurePass42', 'SecurePass43');
    assert_true(in_array('password_confirmation_mismatch', $errors, true));
});

test('password policy accepts a valid confirmed password', function (): void {
    $errors = (new PasswordPolicyService())->validate('SecurePass42', 'SecurePass42');
    assert_same([], $errors);
});

test('password policy errors never include submitted password values', function (): void {
    $password = 'DoNotLeakThisPassword';
    $policy = new PasswordPolicyService();
    $errors = $policy->validate($password, $password);

    assert_not_contains($password, json_encode($errors) ?: '');

    try {
        $policy->assertValid($password, $password);
        throw new RuntimeException('Expected password policy rejection.');
    } catch (PasswordPolicyException $exception) {
        assert_not_contains($password, $exception->getMessage());
    }

    $source = file_get_contents(project_path('src/Services/PasswordPolicyService.php'));
    assert_true(is_string($source));
    assert_not_contains('error_log', $source);
});
