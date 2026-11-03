<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Services\DateValidationService;
use App\Services\EmployeeValidationService;
use App\Services\PasswordPolicyService;

/** @param list<string> $existingEmails */
function employee_validation_service(array $existingEmails = []): EmployeeValidationService
{
    $employeeModel = new class($existingEmails) extends Employee {
        /** @param list<string> $existingEmails */
        public function __construct(private array $existingEmails)
        {
        }

        public function existsByEmail(string $email, ?int $excludeId = null): bool
        {
            return in_array(strtolower($email), array_map('strtolower', $this->existingEmails), true);
        }

        public function existsByStaffCode(string $staffCode, ?int $excludeId = null): bool
        {
            return false;
        }
    };

    $departmentModel = new class extends Department {
        public function __construct()
        {
        }

        public function findById(int $id): ?array
        {
            return $id === 1 ? ['id' => 1, 'name' => 'Operations'] : null;
        }
    };

    $positionModel = new class extends Position {
        public function __construct()
        {
        }

        public function findById(int $id): ?array
        {
            return $id === 1 ? ['id' => 1, 'name' => 'Coordinator'] : null;
        }
    };

    return new EmployeeValidationService(
        $employeeModel,
        $departmentModel,
        $positionModel,
        new PasswordPolicyService(),
        new DateValidationService(),
    );
}

/** @return array<string, mixed> */
function valid_employee_validation_input(array $overrides = []): array
{
    return array_merge([
        'name' => 'Aung Min',
        'email' => 'aung.min@example.com',
        'password' => 'SecurePass42',
        'password_confirmation' => 'SecurePass42',
        'role' => 'employee',
        'department_id' => '',
        'position_id' => '',
        'status' => 'active',
        'staff_code' => '123456',
        'employment_type' => 'full-time',
        'join_date' => '',
        'expected_daily_work_hours' => '8.00',
        'overtime_eligible' => '1',
        'send_verification_code' => '1',
        'phone' => '',
        'address' => '',
    ], $overrides);
}

test('employee validation accepts English and Myanmar names but rejects numbers and symbols', function (): void {
    $service = employee_validation_service();

    $english = $service->validate(valid_employee_validation_input(['name' => "O'Connor-Smith"]));
    $myanmar = $service->validate(valid_employee_validation_input([
        'name' => 'အောင် မင်း',
        'email' => 'myanmar.name@example.com',
    ]));
    $numeric = $service->validate(valid_employee_validation_input(['name' => '123456']));
    $symbols = $service->validate(valid_employee_validation_input(['name' => '@@@']));
    $mixed = $service->validate(valid_employee_validation_input(['name' => 'Aung123']));

    assert_same([], $english['errors']);
    assert_same([], $myanmar['errors']);
    assert_true(in_array('employee_name_invalid', $numeric['errors'], true));
    assert_true(in_array('employee_name_invalid', $symbols['errors'], true));
    assert_true(in_array('employee_name_invalid', $mixed['errors'], true));
});

test('employee validation normalizes only the email domain and accepts non-Gmail domains', function (): void {
    $result = employee_validation_service()->validate(valid_employee_validation_input([
        'email' => 'Aung.Min@Company.ORG',
    ]));

    assert_same([], $result['errors']);
    assert_same('Aung.Min@company.org', $result['data']['email']);
});

test('employee validation enforces required valid unique and bounded email addresses', function (): void {
    $service = employee_validation_service(['used@example.com']);

    $required = $service->validate(valid_employee_validation_input(['email' => '']));
    $invalid = $service->validate(valid_employee_validation_input(['email' => 'not-an-email']));
    $duplicate = $service->validate(valid_employee_validation_input(['email' => 'Used@Example.COM']));
    $tooLong = $service->validate(valid_employee_validation_input(['email' => str_repeat('a', 250) . '@example.com']));

    assert_true(in_array('employee_email_required', $required['errors'], true));
    assert_true(in_array('employee_email_invalid', $invalid['errors'], true));
    assert_true(in_array('employee_email_exists', $duplicate['errors'], true));
    assert_true(in_array('email_too_long', $tooLong['errors'], true));
});

test('employee validation normalizes supported Myanmar phone formats to local form', function (): void {
    $service = employee_validation_service();
    $local = $service->validate(valid_employee_validation_input(['phone' => '09 700-999-001']));
    $international = $service->validate(valid_employee_validation_input(['phone' => '+959 700-999-001']));
    $dialPrefix = $service->validate(valid_employee_validation_input(['phone' => '00959 700-999-001']));

    assert_same('09700999001', $local['data']['phone']);
    assert_same('09700999001', $international['data']['phone']);
    assert_same('09700999001', $dialPrefix['data']['phone']);
    assert_same([], $local['errors']);
    assert_same([], $international['errors']);
    assert_same([], $dialPrefix['errors']);
});

test('employee validation keeps phone optional and rejects letters placeholders and excessive input', function (): void {
    $service = employee_validation_service();
    $optional = $service->validate(valid_employee_validation_input(['phone' => '']));
    $letters = $service->validate(valid_employee_validation_input(['phone' => '09ABC12345']));
    $fake = $service->validate(valid_employee_validation_input(['phone' => '09123456789']));
    $tooLong = $service->validate(valid_employee_validation_input(['phone' => '09' . str_repeat('1', 49)]));

    assert_same([], $optional['errors']);
    assert_true(in_array('employee_phone_invalid', $letters['errors'], true));
    assert_true(in_array('employee_phone_fake', $fake['errors'], true));
    assert_true(in_array('phone_too_long', $tooLong['errors'], true));
});

test('employee validation delegates password decisions to PasswordPolicyService', function (): void {
    $result = employee_validation_service()->validate(valid_employee_validation_input([
        'password' => 'PASSWORD123',
        'password_confirmation' => 'PASSWORD123',
    ]));

    assert_true(in_array('password_too_common', $result['errors'], true));
});

test('employee validation requires an admin supplied six digit staff code', function (): void {
    $service = employee_validation_service();

    assert_true(in_array('employee_staff_code_required', $service->validate(valid_employee_validation_input(['staff_code' => '']))['errors'], true));
    foreach (['12345', '1234567', 'ABC123', '１２３４５６'] as $invalidCode) {
        assert_true(in_array('employee_staff_code_format', $service->validate(valid_employee_validation_input(['staff_code' => $invalidCode]))['errors'], true));
    }
    assert_same([], $service->validate(valid_employee_validation_input(['staff_code' => '004217']))['errors']);
    assert_true(in_array(
        'employee_staff_code_format',
        $service->validate(valid_employee_validation_input(['staff_code' => 'SAP-0042']), 42, 'SAP-0042')['errors'],
        true,
    ), 'Editing a legacy account must require the Administrator to replace its old code.');
});
