<?php

declare(strict_types=1);

use App\Controllers\ProfileController;
use App\Core\Localization;
use App\Core\Request;
use App\Core\SessionManager;
use App\Models\Employee;
use App\Models\EmployeeProfile;
use App\Services\EmployeeValidationService;
use App\Services\ProfilePhotoStorageService;
use Tests\Support\FakeApplication;

test('employee profile routes are self-scoped and do not accept employee IDs', function (): void {
    $routes = (string) file_get_contents(project_path('public/index.php'));
    $controller = project_path('src/Controllers/ProfileController.php');

    foreach ([
        "addRoute('GET', '/profile'",
        "addRoute('POST', '/profile/update'",
        "addRoute('GET', '/profile/password'",
        "addRoute('POST', '/profile/password/update'",
        "addRoute('POST', '/profile/photo'",
        "addRoute('POST', '/profile/photo/remove'",
        "addRoute('GET', '/profile/photo'",
    ] as $route) {
        assert_contains($route, $routes);
    }

    assert_method_order($controller, 'show', ['requireOwnIdentity()', 'renderProfile($userId)']);
    assert_method_order($controller, 'update', ['requireOwnIdentity()', 'validateCsrfToken($request)', 'validateContact']);
    assert_method_order($controller, 'updatePhoto', ['requireOwnIdentity()', 'validateCsrfToken($request)', 'photoStorage->store']);
    assert_method_order($controller, 'removePhoto', ['requireOwnIdentity()', 'validateCsrfToken($request)', 'findByUserId($userId)']);
    assert_method_order($controller, 'changePassword', ['requireOwnIdentity()', 'findById($userId)', "render('profile/password'"]);
    assert_method_order($controller, 'updatePassword', ['requireOwnIdentity()', 'validateCsrfToken($request)', 'findById($userId)', 'resetPassword($userId, $newPassword)']);
    assert_method_order($controller, 'photo', ['requireAuthentication()', 'authorizePhotoRequest($request)', "findByUserId(\$authorization['user_id'])", 'photoStorage->resolve']);
    assert_method_contains($controller, 'requireOwnIdentity', "requireAuthentication()");
    assert_method_contains($controller, 'requireOwnIdentity', 'getUserId()');
    assert_method_contains($controller, 'authorizePhotoRequest', "\$role !== 'admin'");
    assert_not_contains("input('user_id'", (string) file_get_contents($controller));
    assert_not_contains("params['user_id']", (string) file_get_contents($controller));
});

test('password self-service requires authentication before account lookup', function (): void {
    $lookups = [];
    $employeeModel = new class($lookups) extends Employee {
        public function __construct(private array &$lookups) {}
        public function findById(int $id): ?array
        {
            $this->lookups[] = $id;
            return null;
        }
    };
    $profileModel = (new ReflectionClass(EmployeeProfile::class))->newInstanceWithoutConstructor();
    $validation = (new ReflectionClass(EmployeeValidationService::class))->newInstanceWithoutConstructor();
    $session = new class extends SessionManager {
        public function requireAuthentication(): void
        {
            throw new RuntimeException('authentication denied');
        }
    };

    $denied = false;
    try {
        (new ProfileController(
            $employeeModel,
            $profileModel,
            $validation,
            new ProfilePhotoStorageService(profile_photo_test_root()),
            $session,
            new FakeApplication()
        ))->changePassword(new Request(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/profile/password']));
    } catch (RuntimeException $exception) {
        $denied = $exception->getMessage() === 'authentication denied';
    }

    assert_true($denied, 'Unauthenticated password-page access must be rejected.');
    assert_same([], $lookups, 'No account lookup may occur before authentication succeeds.');
});

test('profile shows password self-service to every authenticated role', function (): void {
    foreach (['admin', 'department_head', 'employee'] as $role) {
        $t = static fn (string $key): string => $key;
        $employee = [
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'role' => $role,
            'status' => 'active',
            'employment_type' => 'full-time',
            'position_name' => null,
            'department_name' => null,
        ];
        $profile = [];
        $formData = ['phone' => '', 'address' => ''];
        $errors = [];
        $hasPhoto = false;
        $profilePhotoUrl = null;
        $csrf_token = 'test-token';

        ob_start();
        require project_path('views/profile/show.php');
        $output = (string) ob_get_clean();

        assert_contains('data-profile-security', $output, $role . ' must see the account-security section.');
        assert_contains('href="/profile/password"', $output, $role . ' must see the Change Password link.');
        assert_contains('profile.change_password', $output, $role . ' must see the localized action label.');
    }
});

test('password form and browser checklist protect the self-service contract', function (): void {
    $view = (string) file_get_contents(project_path('views/profile/password.php'));
    $browserSeed = (string) file_get_contents(project_path('tests/browser_seed.php'));
    $browserChecklist = (string) file_get_contents(project_path('tests/Browser/seeded_flows.md'));

    assert_contains('action="/profile/password/update"', $view);
    assert_contains('name="csrf_token"', $view);
    assert_contains('name="current_password"', $view);
    assert_contains('autocomplete="current-password"', $view);
    assert_same(2, substr_count($view, 'autocomplete="new-password"'));
    assert_contains('name="confirm_password"', $view);
    assert_not_contains('name="user_id"', $view);
    assert_contains('profile.password_signout_warning', $view);

    assert_contains("'department_head'", $browserSeed);
    assert_contains('Department Head: department.head.integration@sapas.test', $browserSeed);
    foreach (['Administrator', 'Department Head', 'Employee', 'old password is rejected', 'new password signs in successfully'] as $expectation) {
        assert_contains($expectation, $browserChecklist);
    }
});

test('profile controller enforces authentication before lookup and renders only session employee data', function (): void {
    Localization::setCurrent(new Localization(project_path('lang')));
    $lookups = [];
    $employeeModel = new class($lookups) extends Employee {
        /** @param list<int> $lookups */
        public function __construct(private array &$lookups)
        {
        }

        public function findById(int $id): ?array
        {
            $this->lookups[] = $id;
            return $id === 21 ? [
                'id' => 21,
                'name' => 'Session Employee',
                'email' => 'session.employee@sapas.test',
                'role' => 'employee',
                'status' => 'active',
                'department_id' => 876543,
                'position_id' => 987654,
                'department_name' => 'Engineering',
                'position_name' => 'Developer',
                'employment_type' => 'full-time',
                'expected_daily_work_hours' => '8.00',
                'overtime_eligible' => 1,
            ] : null;
        }
    };
    $profileModel = new class extends EmployeeProfile {
        public function __construct()
        {
        }

        public function findByUserId(int $userId): ?array
        {
            return $userId === 21
                ? ['user_id' => 21, 'phone' => '094591020', 'address' => 'Yangon', 'profile_photo_filename' => null]
                : null;
        }
    };
    $validation = (new ReflectionClass(EmployeeValidationService::class))->newInstanceWithoutConstructor();
    $request = new Request(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/profile']);

    $unauthorizedSession = new class(null, null) extends SessionManager {
        public function __construct(private ?int $id, private ?string $role)
        {
        }

        public function requireAuthentication(): void
        {
            if ($this->id === null) {
                throw new RuntimeException('authentication denied');
            }
        }

        public function requireRole(string $role): void
        {
            if ($this->id === null || $this->role !== $role) {
                throw new RuntimeException('role denied');
            }
        }

        public function getUserId(): ?int { return $this->id; }
        public function getUserRole(): ?string { return $this->role; }
    };
    $denied = false;
    try {
        (new ProfileController($employeeModel, $profileModel, $validation, new ProfilePhotoStorageService(profile_photo_test_root()), $unauthorizedSession, new FakeApplication()))->show($request);
    } catch (RuntimeException $exception) {
        $denied = $exception->getMessage() === 'authentication denied';
    }
    assert_true($denied, 'An unauthenticated request must be rejected by the authentication gate.');
    assert_same([], $lookups, 'No employee lookup may occur before the authentication gate succeeds.');

    $employeeSession = new class(21, 'employee') extends SessionManager {
        public function __construct(private ?int $id, private ?string $role)
        {
        }

        public function requireAuthentication(): void
        {
            if ($this->id === null) {
                throw new RuntimeException('authentication denied');
            }
        }

        public function requireRole(string $role): void
        {
            if ($this->id === null || $this->role !== $role) {
                throw new RuntimeException('role denied');
            }
        }

        public function getUserId(): ?int { return $this->id; }
        public function getUserRole(): ?string { return $this->role; }
        public function getUserName(): ?string { return 'Session Employee'; }
        public function generateCsrfToken(): string { return 'test-csrf-token'; }
        public function consumeFlash(): array { return []; }
    };

    ob_start();
    (new ProfileController($employeeModel, $profileModel, $validation, new ProfilePhotoStorageService(profile_photo_test_root()), $employeeSession, new FakeApplication()))->show($request);
    $output = (string) ob_get_clean();

    assert_same([21], $lookups);
    assert_contains('Session Employee', $output);
    assert_contains('094591020', $output);
    assert_contains('data-profile-position-badge', $output);
    assert_contains('data-profile-department-badge', $output);
    assert_contains('Developer', $output);
    assert_contains('Engineering', $output);
    assert_not_contains('987654', $output);
    assert_not_contains('876543', $output);
    assert_not_contains('Other Employee', $output);
});

test('all topbar identities link directly to profile and logout remains unchanged', function (): void {
    $topbar = (string) file_get_contents(project_path('views/partials/topbar.php'));
    $sidebar = (string) file_get_contents(project_path('views/partials/sidebar.php'));

    assert_not_contains("if (\$userRole === 'employee')", $topbar);
    assert_contains('href="/profile"', $topbar);
    assert_contains('sapas-topbar-profile-link', $topbar);
    assert_contains("\$t('nav.profile')", $topbar);
    assert_not_contains('<div class="sapas-topbar-user"', $topbar);
    assert_contains('method="post" action="/logout"', $sidebar);
});

test('profile hero omits organization badges when position and department are missing', function (): void {
    $t = static fn (string $key): string => $key;
    $employee = [
        'name' => 'No Assignment Employee',
        'role' => 'employee',
        'status' => 'active',
        'position_name' => null,
        'department_name' => '',
        'employment_type' => 'full-time',
    ];
    $profile = [];
    $formData = ['phone' => '', 'address' => ''];
    $errors = [];
    $hasPhoto = false;
    $profilePhotoUrl = null;
    $csrf_token = 'test-token';

    ob_start();
    require project_path('views/profile/show.php');
    $output = (string) ob_get_clean();

    assert_not_contains('data-profile-position-badge', $output);
    assert_not_contains('data-profile-department-badge', $output);
    assert_not_contains('data-profile-organization-badges', $output);
    assert_not_contains('NULL', $output);
});

test('employee profile view exposes only phone and address as editable contact fields', function (): void {
    $view = (string) file_get_contents(project_path('views/profile/show.php'));
    $sidebar = (string) file_get_contents(project_path('views/partials/sidebar.php'));

    assert_contains('name="phone"', $view);
    assert_contains('name="address"', $view);
    assert_contains('name="profile_photo"', $view);
    assert_not_contains('name="name"', $view);
    assert_not_contains('name="email"', $view);
    assert_not_contains('name="role"', $view);
    assert_not_contains('name="status"', $view);
    assert_not_contains('name="department_id"', $view);
    assert_not_contains('name="position_id"', $view);
    assert_contains("['label' => \$t('nav.profile'), 'href' => '/profile'", $sidebar);
});

test('profile and employee account forms expose identity and effective-policy boundaries', function (): void {
    $profile = (string) file_get_contents(project_path('views/profile/show.php'));
    $employeeForm = (string) file_get_contents(project_path('views/employees/form.php'));
    $topbar = (string) file_get_contents(project_path('views/partials/topbar.php'));
    $accessDenied = (string) file_get_contents(project_path('views/errors/access_denied.php'));

    assert_contains('email_verified_at', (string) file_get_contents(project_path('src/Models/Employee.php')));
    assert_contains('profile.email_verified', $profile);
    assert_contains('profile.email_verification_required', $profile);
    assert_contains('data-effective-work-policy', $profile);
    assert_not_contains('expected_daily_work_hours', $profile);
    assert_contains('employees_module.email_identity_help', $employeeForm);
    assert_contains('if ($isEdit)', $employeeForm);
    assert_contains('employees_module.security_change_warning', $employeeForm);
    assert_contains('data-department-required-indicator', $employeeForm);
    assert_contains('employees_module.department_head_help', $employeeForm);
    assert_contains("\$t('roles.' . \$userRole)", $topbar);
    assert_contains("'department_head' => '/department-head/dashboard'", $topbar);
    assert_contains("'department_head' => '/department-head/dashboard'", $accessDenied);

    $t = static fn (string $key): string => $key;
    $title = 'Edit';
    $action = '/employees/update?id=9';
    $errors = [];
    $employee = [
        'id' => 9, 'name' => 'Department Head', 'email' => 'dh@example.test', 'role' => 'department_head',
        'department_id' => '', 'position_id' => '', 'status' => 'active', 'staff_code' => '123456',
        'employment_type' => 'full-time', 'join_date' => '', 'expected_daily_work_hours' => '8.00',
        'overtime_eligible' => 1, 'phone' => '', 'address' => '',
    ];
    $departments = [];
    $positions = [];
    $userId = 1;
    ob_start();
    require project_path('views/employees/form.php');
    $editOutput = (string) ob_get_clean();
    assert_not_contains('name="email"', $editOutput);
    assert_contains('aria-required="true"', $editOutput);
});

test('profile photo implementation uses private storage and layered image validation', function (): void {
    $storage = (string) file_get_contents(project_path('src/Services/ProfilePhotoStorageService.php'));
    $controller = project_path('src/Controllers/ProfileController.php');
    $migration = (string) file_get_contents(project_path('migrations/2026_08_13_000000_add_employee_profile_photo.sql'));

    assert_contains("'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'profile_photos'", $storage);
    assert_contains('is_uploaded_file', $storage);
    assert_contains('FILEINFO_MIME_TYPE', $storage);
    assert_contains('getimagesize', $storage);
    assert_contains('random_bytes(16)', $storage);
    assert_contains('MAX_BYTES = 2 * 1024 * 1024', $storage);
    assert_not_contains('image/svg', $storage);
    assert_method_contains($controller, 'photo', "header('X-Content-Type-Options: nosniff')");
    assert_contains('profile_photo_filename VARCHAR(255) NULL', $migration);
    assert_not_contains('BLOB', strtoupper($migration));
});

test('profile translations exist in English and Myanmar', function (): void {
    $english = require project_path('lang/en.php');
    $myanmar = require project_path('lang/my.php');

    foreach (['title', 'description', 'profile_photo', 'edit_contact', 'not_provided', 'account_security', 'account_security_help', 'change_password', 'change_password_desc', 'current_password', 'new_password', 'confirm_password', 'password_requirements', 'save_password', 'password_signout_warning', 'email_verified', 'email_verification_required', 'effective_work_policy', 'effective_work_policy_help', 'effective_work_policy_unavailable', 'view_work_policy'] as $key) {
        assert_true(isset($english['profile'][$key]) && $english['profile'][$key] !== '');
        assert_true(isset($myanmar['profile'][$key]) && $myanmar['profile'][$key] !== '');
    }
    foreach (['profile_photo_too_large', 'profile_photo_invalid_type', 'profile_photo_upload_failed'] as $key) {
        assert_true(isset($english['validation'][$key]) && $english['validation'][$key] !== '');
        assert_true(isset($myanmar['validation'][$key]) && $myanmar['validation'][$key] !== '');
    }
    foreach (['current_password_required', 'new_password_required', 'current_password_invalid', 'password_confirmation_mismatch'] as $key) {
        assert_true(isset($english['validation'][$key]) && $english['validation'][$key] !== '');
        assert_true(isset($myanmar['validation'][$key]) && $myanmar['validation'][$key] !== '');
    }
    foreach (['password_updated', 'password_update_failed'] as $key) {
        assert_true(isset($english['flash'][$key]) && $english['flash'][$key] !== '');
        assert_true(isset($myanmar['flash'][$key]) && $myanmar['flash'][$key] !== '');
    }
});
