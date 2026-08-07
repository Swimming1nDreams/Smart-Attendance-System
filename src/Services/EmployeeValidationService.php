<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;

final class EmployeeValidationService
{
    private const INVALID_PHONE_NUMBERS = [
        '09123456789',
        '09000000000',
        '09012345678',
    ];

    private Employee $employeeModel;
    private Department $departmentModel;
    private Position $positionModel;
    private PasswordPolicyService $passwordPolicy;
    private DateValidationService $dateValidator;

    public function __construct(
        Employee $employeeModel,
        Department $departmentModel,
        Position $positionModel,
        PasswordPolicyService $passwordPolicy,
        DateValidationService $dateValidator
    ) {
        $this->employeeModel = $employeeModel;
        $this->departmentModel = $departmentModel;
        $this->positionModel = $positionModel;
        $this->passwordPolicy = $passwordPolicy;
        $this->dateValidator = $dateValidator;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, mixed>, errors: list<string>}
     */
    public function validate(array $input, ?int $excludeId = null, string $staffCode = ''): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $email = $this->normalizeEmail((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $passwordConfirmation = (string) ($input['password_confirmation'] ?? '');
        $role = trim((string) ($input['role'] ?? ''));
        [$departmentId, $departmentValueIsValid] = $this->normalizeOptionalInt($input['department_id'] ?? null);
        [$positionId, $positionValueIsValid] = $this->normalizeOptionalInt($input['position_id'] ?? null);
        $status = trim((string) ($input['status'] ?? ''));
        $employmentType = trim((string) ($input['employment_type'] ?? ''));
        $joinDate = trim((string) ($input['join_date'] ?? ''));
        $expectedDailyWorkHoursValue = trim((string) ($input['expected_daily_work_hours'] ?? ''));
        $expectedDailyWorkHours = is_numeric($expectedDailyWorkHoursValue) ? (float) $expectedDailyWorkHoursValue : 0.0;
        $overtimeEligibleValue = $input['overtime_eligible'] ?? null;
        $overtimeEligible = (string) $overtimeEligibleValue === '1';
        $sendVerificationCode = (string) ($input['send_verification_code'] ?? '1') === '1';
        $phoneInput = (string) ($input['phone'] ?? '');
        $phone = trim($phoneInput);
        $address = trim((string) ($input['address'] ?? ''));
        $errors = [];

        if ($name === '') {
            $errors[] = 'employee_name_required';
        } elseif (mb_strlen($name) > 255) {
            $errors[] = 'name_too_long';
        } elseif (!$this->isValidName($name)) {
            $errors[] = 'employee_name_invalid';
        }

        $emailIsValid = true;
        if ($email === '') {
            $errors[] = 'employee_email_required';
            $emailIsValid = false;
        } elseif (mb_strlen($email) > 255) {
            $errors[] = 'email_too_long';
            $emailIsValid = false;
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'employee_email_invalid';
            $emailIsValid = false;
        }

        if ($emailIsValid && $this->employeeModel->existsByEmail($email, $excludeId)) {
            $errors[] = 'employee_email_exists';
        }

        if ($password === '' && $excludeId === null) {
            $errors[] = 'employee_password_required_new';
        } elseif ($password !== '' || $passwordConfirmation !== '') {
            $errors = array_merge($errors, $this->passwordPolicy->validate($password, $passwordConfirmation));
        }

        if (!in_array($role, ['admin', 'employee'], true)) {
            $errors[] = 'role_invalid';
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $errors[] = 'employee_status_invalid';
        }

        if (!in_array($employmentType, ['full-time', 'part-time', 'intern', 'contract'], true)) {
            $errors[] = 'employee_employment_type_invalid';
        }

        if ($joinDate !== '' && !$this->dateValidator->isValidDate($joinDate)) {
            $errors[] = 'employee_join_date_invalid';
        }

        if ($expectedDailyWorkHoursValue === '' || !is_numeric($expectedDailyWorkHoursValue) || $expectedDailyWorkHours < 0.5 || $expectedDailyWorkHours > 24) {
            $errors[] = 'employee_expected_hours_invalid';
        }

        if (!in_array((string) $overtimeEligibleValue, ['0', '1'], true)) {
            $errors[] = 'employee_overtime_eligible_invalid';
        }

        if (!$departmentValueIsValid || ($departmentId !== null && $this->departmentModel->findById($departmentId) === null)) {
            $errors[] = 'department_invalid';
        }

        if (!$positionValueIsValid || ($positionId !== null && $this->positionModel->findById($positionId) === null)) {
            $errors[] = 'position_invalid';
        }

        if (mb_strlen($staffCode) > 50) {
            $errors[] = 'employee_staff_code_too_long';
        }

        if (mb_strlen($phoneInput) > 50) {
            $errors[] = 'phone_too_long';
        } elseif ($phone !== '') {
            $normalizedPhone = $this->normalizeMyanmarPhone($phone);
            if ($normalizedPhone === null) {
                $errors[] = 'employee_phone_invalid';
            } elseif (in_array($normalizedPhone, self::INVALID_PHONE_NUMBERS, true)) {
                $errors[] = 'employee_phone_fake';
            } else {
                $phone = $normalizedPhone;
            }
        }

        if (mb_strlen($address) > 1000) {
            $errors[] = 'address_too_long';
        }

        return [
            'data' => [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $passwordConfirmation,
                'role' => $role,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'status' => $status,
                'staff_code' => $staffCode,
                'employment_type' => $employmentType,
                'join_date' => $joinDate,
                'expected_daily_work_hours_value' => $expectedDailyWorkHoursValue,
                'expected_daily_work_hours' => $expectedDailyWorkHours,
                'overtime_eligible_value' => $overtimeEligibleValue,
                'overtime_eligible' => $overtimeEligible,
                'send_verification_code' => $sendVerificationCode,
                'phone' => $phone,
                'address' => $address,
            ],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    private function normalizeEmail(string $email): string
    {
        $email = trim($email);
        $separator = strrpos($email, '@');

        if ($separator === false) {
            return $email;
        }

        return substr($email, 0, $separator + 1) . strtolower(substr($email, $separator + 1));
    }

    private function isValidName(string $name): bool
    {
        return preg_match('/\p{L}/u', $name) === 1
            && preg_match('/^[\p{L}\p{M} .\x{0027}\x{2019}-]+$/u', $name) === 1;
    }

    private function normalizeMyanmarPhone(string $phone): ?string
    {
        if (preg_match('/^(?:\+)?[0-9][0-9 -]*$/', $phone) !== 1) {
            return null;
        }

        $compact = str_replace([' ', '-'], '', $phone);

        if (str_starts_with($compact, '+959')) {
            $compact = '09' . substr($compact, 4);
        } elseif (str_starts_with($compact, '00959')) {
            $compact = '09' . substr($compact, 5);
        }

        return preg_match('/^09\d{7,9}$/', $compact) === 1 ? $compact : null;
    }

    /** @return array{0: ?int, 1: bool} */
    private function normalizeOptionalInt(mixed $value): array
    {
        if ($value === null || trim((string) $value) === '') {
            return [null, true];
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return $parsed === false ? [null, false] : [(int) $parsed, true];
    }
}
