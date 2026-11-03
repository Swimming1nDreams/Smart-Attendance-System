<?php

declare(strict_types=1);

use App\Controllers\ProfileController;
use App\Core\Localization;
use App\Core\Request;
use App\Core\SessionManager;
use App\Models\Employee;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\EmployeeValidationService;
use App\Services\PasswordPolicyService;
use App\Services\ProfilePhotoStorageService;
use Tests\Support\FakeApplication;

test('profile change password controller invalidates session, stores hash, and authenticates', function (): void {
    $_SESSION['locale'] = 'en';
    Localization::setCurrent(new Localization(project_path('lang')));
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    $connection = $database->pdo();

    $employeeModel = new Employee($connection);
    $profileModel = new EmployeeProfile($connection);
    $userModel = new User($connection);
    $passwordPolicy = new PasswordPolicyService();
    $validation = new EmployeeValidationService(
        $employeeModel,
        new class($connection) extends \App\Models\Department {},
        new class($connection) extends \App\Models\Position {},
        $passwordPolicy,
        new \App\Services\DateValidationService()
    );
    $photoStorage = new ProfilePhotoStorageService(sys_get_temp_dir());
    
    // Create an authenticated session for the employee
    $session = new class($ids['employee_id'], 'employee') extends SessionManager {
        public bool $invalidated = false;
        public function __construct(private ?int $id, private ?string $role) {
            $_SESSION['csrf_token'] = 'test-token';
            $_SESSION['auth'] = [
                'id' => $id,
                'name' => 'Test',
                'email' => 'test@example.com',
                'role' => $role,
                'session_version' => 1,
            ];
        }
        public function requireAuthentication(): void {
            if ($this->id === null) throw new RuntimeException('auth denied');
        }
        public function requireRole(string $role): void {
            if ($this->id === null || $this->role !== $role) throw new RuntimeException('role denied');
        }
        public function getUserId(): ?int { return $this->id; }
        public function getUserRole(): ?string { return $this->role; }
        public function validateCsrfToken(?string $token): bool { return $token === 'test-token'; }
        public function invalidateAuthenticatedSession(): void {
            $this->invalidated = true;
            unset($_SESSION['auth']);
        }
        public function setFlash(string $type, string $message): void {}
    };

    $app = new FakeApplication();
    $controller = new ProfileController($employeeModel, $profileModel, $validation, $photoStorage, $session, $app);

    // Test 1: Missing current password keeps the required-field message.
    $request = new Request(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/profile/password/update'], [], ['current_password' => '', 'new_password' => 'SecurePass42', 'confirm_password' => 'SecurePass42', 'csrf_token' => 'test-token']);
    ob_start();
    $controller->updatePassword($request);
    $output = (string) ob_get_clean();
    assert_contains('Current password is required.', $output);
    assert_not_contains('Current password is incorrect.', $output);

    // Test 2: Wrong current password is rejected
    $request = new Request(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/profile/password/update'], [], ['current_password' => 'Wrong123!', 'new_password' => 'SecurePass42', 'confirm_password' => 'SecurePass42', 'csrf_token' => 'test-token']);
    ob_start();
    $controller->updatePassword($request);
    $output = (string) ob_get_clean();
    assert_contains('Current password is incorrect.', $output);

    // Test 3: Confirmation mismatch is rejected
    $request = new Request(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/profile/password/update'], [], ['current_password' => 'Password123!', 'new_password' => 'SecurePass42', 'confirm_password' => 'SecurePass43', 'csrf_token' => 'test-token']);
    ob_start();
    $controller->updatePassword($request);
    $output = (string) ob_get_clean();
    assert_contains('Password confirmation does not match.', $output);

    // Test 4: Invalid password is rejected according to policy
    $request = new Request(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/profile/password/update'], [], ['current_password' => 'Password123!', 'new_password' => 'password', 'confirm_password' => 'password', 'csrf_token' => 'test-token']);
    ob_start();
    $controller->updatePassword($request);
    $output = (string) ob_get_clean();
    assert_contains('Password must be at least 10 characters long.', $output);

    // Test 5: Successful password change ignores another-user input.
    $otherStatement = $connection->prepare('SELECT password, session_version FROM users WHERE id = :id LIMIT 1');
    $otherStatement->execute([':id' => $ids['other_employee_id']]);
    $otherStateBefore = $otherStatement->fetch(PDO::FETCH_ASSOC);
    assert_true(is_array($otherStateBefore));

    $request = new Request(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/profile/password/update'], [], ['user_id' => $ids['other_employee_id'], 'current_password' => 'Password123!', 'new_password' => 'SecurePass42', 'confirm_password' => 'SecurePass42', 'csrf_token' => 'test-token']);
    
    // It should exit(header) on success.
    try {
        ob_start();
        $controller->updatePassword($request);
    } catch (Throwable $e) {}
    ob_end_clean();

    // Verify session was invalidated (browser logged out)
    assert_true($session->invalidated, 'Current session must be invalidated upon password change');

    // Read the persisted authentication state without relying on a model API
    // that does not exist in the production User model.
    $statement = $connection->prepare(
        'SELECT password, session_version FROM users WHERE id = :id LIMIT 1'
    );
    $statement->execute([':id' => $ids['employee_id']]);
    $userState = $statement->fetch(PDO::FETCH_ASSOC);
    assert_true(is_array($userState), 'Updated user authentication state must exist');

    // Verify old password no longer works
    assert_false($userModel->verifyPassword('Password123!', (string) $userState['password']), 'Old password should no longer work');
    
    // Verify new password works
    assert_true($userModel->verifyPassword('SecurePass42', (string) $userState['password']), 'New password must authenticate successfully');
    
    // Verify session_version changed
    assert_same(2, (int) $userState['session_version'], 'Session version should bump');

    $otherStatement->execute([':id' => $ids['other_employee_id']]);
    $otherStateAfter = $otherStatement->fetch(PDO::FETCH_ASSOC);
    assert_same($otherStateBefore, $otherStateAfter, 'Self-service input must not change another account.');
});
