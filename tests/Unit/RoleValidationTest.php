<?php

declare(strict_types=1);

use App\Services\EmployeeValidationService;
use App\Services\PasswordPolicyService;
use App\Models\Employee;
use App\Models\Department;
use App\Models\Position;
use App\Services\DateValidationService;

test('unit validation: employee validation service restricts roles and departments correctly', function (): void {
    // Simple stubs for the dependencies
    $pdo = new PDO('sqlite::memory:');
    
    $employeeModel = new class($pdo) extends Employee {
        public function __construct(PDO $pdo) { /* noop */ }
        public function existsByEmail(string $email, ?int $excludeId = null): bool { return false; }
        public function existsByStaffCode(string $staffCode, ?int $excludeId = null): bool { return false; }
    };

    $departmentModel = new class($pdo) extends Department {
        public function __construct(PDO $pdo) { /* noop */ }
        public function exists(int $id): bool { return true; }
        public function findById(int $id): ?array { return ['id' => $id]; }
    };

    $positionModel = new class($pdo) extends Position {
        public function __construct(PDO $pdo) { /* noop */ }
        public function exists(int $id): bool { return true; }
    };

    $passwordPolicy = new PasswordPolicyService();
    $dateValidator = new DateValidationService();
    
    $validator = new EmployeeValidationService($employeeModel, $departmentModel, $positionModel, $passwordPolicy, $dateValidator);
    
    $inputWithoutDept = [
        'name' => 'Test',
        'email' => 'test@example.com',
        'role' => 'department_head',
        'department_id' => '', // Empty means null
        'status' => 'active',
        'password' => 'Pass123!',
        'password_confirmation' => 'Pass123!',
        'employment_type' => 'full-time',
        'expected_daily_work_hours' => '8',
        'overtime_eligible' => '0',
        'phone' => '',
        'address' => '',
    ];
    
    $resultWithoutDept = $validator->validate($inputWithoutDept, null, 'EMP-1234');
    assert_true(in_array('department_head_requires_department', $resultWithoutDept['errors'], true));

    $inputWithDept = $inputWithoutDept;
    $inputWithDept['email'] = 'test2@example.com';
    $inputWithDept['department_id'] = '1';
    
    $resultWithDept = $validator->validate($inputWithDept, null, 'EMP-1235');
    assert_false(in_array('department_head_requires_department', $resultWithDept['errors'], true));

    // Also verify admin and employee are accepted without a department requirement
    $inputAdmin = $inputWithoutDept;
    $inputAdmin['email'] = 'admin@example.com';
    $inputAdmin['role'] = 'admin';
    $resultAdmin = $validator->validate($inputAdmin, null, 'EMP-1236');
    assert_false(in_array('department_head_requires_department', $resultAdmin['errors'], true));
    assert_false(in_array('role_invalid', $resultAdmin['errors'], true));

    $inputEmployee = $inputWithoutDept;
    $inputEmployee['email'] = 'employee@example.com';
    $inputEmployee['role'] = 'employee';
    $resultEmployee = $validator->validate($inputEmployee, null, 'EMP-1237');
    assert_false(in_array('department_head_requires_department', $resultEmployee['errors'], true));
    assert_false(in_array('role_invalid', $resultEmployee['errors'], true));

    // Verify invalid role is rejected
    $inputInvalid = $inputWithoutDept;
    $inputInvalid['email'] = 'invalid@example.com';
    $inputInvalid['role'] = 'superadmin';
    $resultInvalid = $validator->validate($inputInvalid, null, 'EMP-1238');
    assert_true(in_array('role_invalid', $resultInvalid['errors'], true));
});
