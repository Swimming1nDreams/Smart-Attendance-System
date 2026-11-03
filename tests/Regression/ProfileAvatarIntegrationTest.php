<?php

declare(strict_types=1);

use App\Config\Database;
use App\Controllers\ProfileController;
use App\Core\Application;
use App\Core\Controller;
use App\Core\Localization;
use App\Core\Request;
use App\Core\SessionManager;
use App\Models\EmployeeProfile;
use App\Services\EmployeeValidationService;
use App\Services\ProfilePhotoStorageService;
use Tests\Support\FakeEmployee;

final class ProfileAvatarTestDatabase extends Database
{
    public function __construct(private PDO $connection)
    {
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }
}

final class ProfileAvatarTestApplication extends Application
{
    public function __construct(private Database $database)
    {
    }

    public function getDatabase(): Database
    {
        return $this->database;
    }
}

function profile_avatar_png_fixture(): string
{
    $content = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    if (!is_string($content)) {
        throw new RuntimeException('Unable to decode avatar fixture.');
    }

    return $content;
}

function profile_avatar_session(int $userId, string $role): SessionManager
{
    return new class($userId, $role) extends SessionManager {
        public function __construct(private int $userId, private string $role)
        {
        }

        public function requireAuthentication(): void
        {
        }

        public function getUserId(): ?int
        {
            return $this->userId;
        }

        public function getUserRole(): ?string
        {
            return $this->role;
        }
    };
}

test('shared profile photo route allows admin employee details and employee self access but blocks another employee', function (): void {
    Localization::setCurrent(new Localization(project_path('lang')));
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sapas-shared-photo-' . bin2hex(random_bytes(8));
    mkdir($root, 0750, true);
    $filename = str_repeat('c', 32) . '.png';
    $content = profile_avatar_png_fixture();
    file_put_contents($root . DIRECTORY_SEPARATOR . $filename, $content);

    $employees = new FakeEmployee([
        21 => ['id' => 21, 'name' => 'Photo Employee', 'role' => 'employee'],
        22 => ['id' => 22, 'name' => 'Other Employee', 'role' => 'employee'],
        1 => ['id' => 1, 'name' => 'Admin User', 'role' => 'admin'],
    ]);
    $profiles = new class($filename) extends EmployeeProfile {
        public function __construct(private string $filename)
        {
        }

        public function findByUserId(int $userId): ?array
        {
            return $userId === 21
                ? ['user_id' => 21, 'profile_photo_filename' => $this->filename, 'updated_at' => '2026-08-14 00:00:00']
                : null;
        }
    };
    $validation = (new ReflectionClass(EmployeeValidationService::class))->newInstanceWithoutConstructor();

    try {
        $adminController = new ProfileController($employees, $profiles, $validation, new ProfilePhotoStorageService($root), profile_avatar_session(1, 'admin'), new Tests\Support\FakeApplication());
        http_response_code(200);
        ob_start();
        $adminController->photo(new Request(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/profile/photo?id=21'], ['id' => '21']));
        $adminOutput = (string) ob_get_clean();
        assert_same(200, http_response_code());
        assert_same($content, $adminOutput);

        $employeeController = new ProfileController($employees, $profiles, $validation, new ProfilePhotoStorageService($root), profile_avatar_session(21, 'employee'), new Tests\Support\FakeApplication());
        http_response_code(200);
        ob_start();
        $employeeController->photo(new Request(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/profile/photo']));
        $employeeOutput = (string) ob_get_clean();
        assert_same(200, http_response_code());
        assert_same($content, $employeeOutput);

        http_response_code(200);
        ob_start();
        $employeeController->photo(new Request(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/profile/photo?id=22'], ['id' => '22']));
        $deniedOutput = (string) ob_get_clean();
        assert_same(403, http_response_code());
        assert_not_contains($content, $deniedOutput);
    } finally {
        $path = $root . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path)) {
            unlink($path);
        }
        if (is_dir($root)) {
            rmdir($root);
        }
        http_response_code(200);
    }
});

test('authenticated employee topbar avatar state resolves only an existing safe private photo', function (): void {
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    $filename = bin2hex(random_bytes(16)) . '.png';
    $root = project_path('storage/private/profile_photos');
    if (!is_dir($root)) {
        mkdir($root, 0750, true);
    }
    $path = $root . DIRECTORY_SEPARATOR . $filename;
    file_put_contents($path, profile_avatar_png_fixture());
    $profileModel = new EmployeeProfile($database->pdo());
    assert_true($profileModel->updatePhotoFilename($ids['employee_id'], $filename));
    assert_true($profileModel->updatePhotoFilename($ids['admin_id'], $filename));

    $application = new ProfileAvatarTestApplication(new ProfileAvatarTestDatabase($database->pdo()));
    $session = profile_avatar_session($ids['employee_id'], 'employee');
    $controller = new class($application, $session) extends Controller {
    };

    try {
        $url = call_private($controller, 'resolveTopbarAvatarUrl', ['employee', $ids['employee_id']]);
        assert_true(is_string($url));
        assert_true(str_starts_with($url, '/profile/photo'));
        assert_not_contains('storage/private', $url);
        $adminUrl = call_private($controller, 'resolveTopbarAvatarUrl', ['admin', $ids['admin_id']]);
        assert_true(is_string($adminUrl));
        assert_true(str_starts_with($adminUrl, '/profile/photo'));
        $departmentHeadUrl = call_private($controller, 'resolveTopbarAvatarUrl', ['department_head', $ids['admin_id']]);
        assert_true(is_string($departmentHeadUrl));

        unlink($path);
        assert_null(call_private($controller, 'resolveTopbarAvatarUrl', ['employee', $ids['employee_id']]));
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

test('avatar partial renders photo or clean initials fallback without private paths', function (): void {
    $avatarName = 'Mya Ei';
    $avatarPhotoUrl = '';
    $avatarClass = 'h-24 w-24';
    $avatarInitialsClass = 'text-2xl';
    $avatarAlt = 'Profile photo for Mya Ei';
    $avatarFallbackLabel = 'Initials avatar for Mya Ei';
    ob_start();
    require project_path('views/partials/avatar.php');
    $fallback = (string) ob_get_clean();

    assert_contains('ME', $fallback);
    assert_contains('Initials avatar for Mya Ei', $fallback);
    assert_not_contains('<img', $fallback);

    $avatarPhotoUrl = '/profile/photo?id=21';
    ob_start();
    require project_path('views/partials/avatar.php');
    $photo = (string) ob_get_clean();

    assert_contains('<img', $photo);
    assert_contains('/profile/photo?id=21', $photo);
    assert_not_contains('storage/private', $photo);
});

test('all roles reuse the avatar partial in topbar', function (): void {
    $employeeController = (string) file_get_contents(project_path('src/Controllers/EmployeeController.php'));
    $employeeDetails = (string) file_get_contents(project_path('views/employees/show.php'));
    $topbar = (string) file_get_contents(project_path('views/partials/topbar.php'));
    $profile = (string) file_get_contents(project_path('views/profile/show.php'));

    assert_contains("'/profile/photo?id=' . \$id", $employeeController);
    assert_contains("require __DIR__ . '/../partials/avatar.php'", $employeeDetails);
    assert_contains("require __DIR__ . '/avatar.php'", $topbar);
    assert_not_contains("if (\$userRole === 'employee')", $topbar);
    assert_not_contains("\$iconName = 'user'", $topbar);
    assert_contains("require __DIR__ . '/../partials/avatar.php'", $profile);
    assert_not_contains('storage/private/profile_photos', $employeeDetails . $topbar . $profile);
});
