<?php

declare(strict_types=1);

use App\Config\Database;
use App\Controllers\LeaveController;
use App\Core\Application;
use App\Core\Localization;
use App\Core\SessionManager;
use Tests\Support\FakeApplication;
use Tests\Support\FakeEmployee;
use Tests\Support\FakeHoliday;
use Tests\Support\FakeLeaveRequest;

final class LeaveAttachmentTestDatabase extends Database
{
    public function __construct(private \PDO $connection)
    {
    }

    public function getConnection(): \PDO
    {
        return $this->connection;
    }
}

final class LeaveAttachmentTestApplication extends Application
{
    public function __construct(private Database $database)
    {
    }

    public function getDatabase(): Database
    {
        return $this->database;
    }
}

final class TestableLeaveAttachmentController extends LeaveController
{
    protected function isHttpUploadedFile(string $path): bool
    {
        return is_file($path);
    }

    protected function moveHttpUploadedFile(string $source, string $destination): bool
    {
        return rename($source, $destination);
    }
}

final class LeaveAttachmentTestSession extends SessionManager
{
    public function __construct(private ?int $userId, private ?string $role)
    {
    }

    public function isAuthenticated(): bool
    {
        return $this->userId !== null && $this->role !== null;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getUserRole(): ?string
    {
        return $this->role;
    }
}

function make_leave_attachment_controller(?\PDO $connection = null, ?SessionManager $sessionManager = null): TestableLeaveAttachmentController
{
    reset_test_session();

    $application = $connection === null
        ? new FakeApplication()
        : new LeaveAttachmentTestApplication(new LeaveAttachmentTestDatabase($connection));

    return new TestableLeaveAttachmentController(
        new FakeLeaveRequest(),
        new FakeHoliday(),
        new FakeEmployee(),
        $sessionManager ?? new SessionManager(),
        $application,
    );
}

function leave_attachment_download_database(string $filePath, int $ownerId = 21, int $attachmentId = 4): \PDO
{
    $connection = new \PDO('sqlite::memory:');
    $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $connection->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    $connection->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT NOT NULL, status TEXT NOT NULL, department_id INTEGER NULL)');
    $connection->exec('CREATE TABLE leave_requests (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL)');
    $connection->exec('CREATE TABLE leave_request_attachments (id INTEGER PRIMARY KEY, leave_request_id INTEGER NOT NULL, file_path TEXT NOT NULL, original_name TEXT NOT NULL, mime_type TEXT NOT NULL, file_size INTEGER NOT NULL)');
    $connection->prepare('INSERT INTO leave_requests (id, user_id) VALUES (100, :user_id)')->execute([':user_id' => $ownerId]);
    $insertUser = $connection->prepare('INSERT INTO users (id, role, status, department_id) VALUES (:id, :role, :status, :department_id)');
    foreach ([
        [1, 'admin', 'active', null],
        [21, 'employee', 'active', 7],
        [22, 'employee', 'active', 8],
        [30, 'department_head', 'active', 7],
        [31, 'department_head', 'active', 8],
    ] as [$id, $role, $status, $departmentId]) {
        $insertUser->execute([':id' => $id, ':role' => $role, ':status' => $status, ':department_id' => $departmentId]);
    }
    $connection->prepare(
        'INSERT INTO leave_request_attachments (id, leave_request_id, file_path, original_name, mime_type, file_size) VALUES (:id, 100, :file_path, :original_name, :mime_type, :file_size)'
    )->execute([
        ':id' => $attachmentId,
        ':file_path' => $filePath,
        ':original_name' => 'supporting-document.pdf',
        ':mime_type' => 'application/pdf',
        ':file_size' => 54,
    ]);

    return $connection;
}

function leave_attachment_pdf_fixture(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

test('leave attachment route uses real application and session APIs', function (): void {
    $routes = file_get_contents(project_path('public/index.php'));
    $controller = project_path('src/Controllers/LeaveController.php');

    assert_true(is_string($routes));
    assert_contains("addRoute('GET', '/leave/attachment/download'", $routes);
    assert_method_contains($controller, 'downloadAttachment', '$this->sessionManager->isAuthenticated()');
    assert_method_contains($controller, 'downloadAttachment', '$this->sessionManager->getUserRole()');
    assert_method_contains($controller, 'prepareAttachmentDownload', '$this->app->getDatabase()->getConnection()');
    assert_not_contains('hasRole(', method_source($controller, 'downloadAttachment'));
    assert_not_contains('getContainer(', method_source($controller, 'prepareAttachmentDownload'));
    assert_not_contains('stored_name', method_source($controller, 'prepareAttachmentDownload'));
    assert_not_contains("header('Pragma:", method_source($controller, 'downloadAttachment'));
});

test('leave attachment content disposition has an ASCII fallback and encoded UTF-8 filename without control bytes', function (): void {
    $controller = make_leave_attachment_controller();

    assert_same(
        'inline; filename="medical_certificate.pdf"; filename*=UTF-8\'\'medical_certificate.pdf',
        call_private($controller, 'buildAttachmentContentDisposition', ['medical_certificate.pdf'])
    );

    $unsafeName = "folder\\စာရွက်စာတမ်း\r\nX-Injected: yes\t\u{0085}.pdf\0";
    $header = call_private($controller, 'buildAttachmentContentDisposition', [$unsafeName]);

    assert_not_contains("\r", $header);
    assert_not_contains("\n", $header);
    assert_not_contains("\t", $header);
    assert_not_contains("\u{0085}", $header);
    assert_not_contains("\0", $header);
    assert_contains("filename*=UTF-8''", $header);
    assert_false(str_contains(strtoupper($header), '%0D'));
    assert_false(str_contains(strtoupper($header), '%0A'));

    $matches = [];
    assert_same(1, preg_match('/filename="([^"]+)"/', $header, $matches));
    assert_same(1, preg_match('/^[\x20-\x7E]+$/D', $matches[1]));
    assert_not_contains('"', $matches[1]);
});

test('valid employee PDF upload is stored privately and admin or owner can open it while another employee is denied', function (): void {
    $temporaryFile = tempnam(sys_get_temp_dir(), 'sapas-leave-upload-');
    assert_true(is_string($temporaryFile), 'A temporary upload fixture should be available.');

    $content = leave_attachment_pdf_fixture();
    assert_same(strlen($content), file_put_contents($temporaryFile, $content));

    $storedFile = null;
    try {
        $upload = call_private(make_leave_attachment_controller(), 'handleAttachmentUpload', [[
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($content),
            'tmp_name' => $temporaryFile,
            'name' => 'supporting-document.pdf',
        ]]);

        assert_false(array_key_exists('error', $upload), 'A valid PDF should pass attachment validation.');
        assert_same('application/pdf', $upload['mime_type']);
        assert_true(str_starts_with($upload['file_path'], 'leave_attachments/'));

        $storedFile = project_path('storage/private/' . $upload['file_path']);
        assert_true(is_file($storedFile), 'The accepted file should be moved to private attachment storage.');
        assert_same($content, file_get_contents($storedFile));

        $connection = leave_attachment_download_database($upload['file_path']);
        $controller = make_leave_attachment_controller($connection);

        $admin = call_private($controller, 'prepareAttachmentDownload', [4, 1, 'admin']);
        assert_same(200, $admin['status']);
        assert_same(realpath($storedFile), $admin['full_path']);

        $owner = call_private($controller, 'prepareAttachmentDownload', [4, 21, 'employee']);
        assert_same(200, $owner['status']);
        assert_same(realpath($storedFile), $owner['full_path']);

        $departmentHeadOwner = call_private($controller, 'prepareAttachmentDownload', [4, 21, 'department_head']);
        assert_same(200, $departmentHeadOwner['status']);
        assert_same(realpath($storedFile), $departmentHeadOwner['full_path']);

        $departmentManager = call_private($controller, 'prepareAttachmentDownload', [4, 30, 'department_head']);
        assert_same(200, $departmentManager['status']);
        assert_same(realpath($storedFile), $departmentManager['full_path']);

        $unrelatedEmployee = call_private($controller, 'prepareAttachmentDownload', [4, 22, 'employee']);
        assert_same(403, $unrelatedEmployee['status']);
        assert_same('Access denied.', $unrelatedEmployee['message']);

        $unrelatedDepartmentHead = call_private($controller, 'prepareAttachmentDownload', [4, 31, 'department_head']);
        assert_same(403, $unrelatedDepartmentHead['status']);
    } finally {
        if (is_string($storedFile) && is_file($storedFile)) {
            unlink($storedFile);
        }
        if (is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
    }
});

test('leave attachment download action streams a valid file without a service error', function (): void {
    if (!function_exists('proc_open')) {
        skip_test('proc_open is required for the isolated attachment download probe.');
    }

    $attachmentRoot = project_path('storage/private/leave_attachments');
    if (!is_dir($attachmentRoot)) {
        mkdir($attachmentRoot, 0750, true);
    }
    $fileName = 'endpoint-' . bin2hex(random_bytes(8)) . '.pdf';
    $filePath = $attachmentRoot . DIRECTORY_SEPARATOR . $fileName;
    $content = leave_attachment_pdf_fixture();
    file_put_contents($filePath, $content);

    try {
        $code = 'require ' . var_export(project_path('tests/bootstrap.php'), true) . ';'
            . 'require ' . var_export(project_path('tests/Support/TestRunner.php'), true) . ';'
            . 'require ' . var_export(project_path('tests/Support/Fakes.php'), true) . ';'
            . '$runner = new TestRunner();'
            . 'require ' . var_export(project_path('tests/Regression/LeaveAttachmentFlowTest.php'), true) . ';'
            . '$connection = leave_attachment_download_database(' . var_export('leave_attachments/' . $fileName, true) . ');'
            . '$session = new LeaveAttachmentTestSession(1, \'admin\');'
            . '$controller = make_leave_attachment_controller($connection, $session);'
            . '$request = new \\App\\Core\\Request([\'REQUEST_METHOD\' => \'GET\', \'REQUEST_URI\' => \'/leave/attachment/download?id=4\'], [\'id\' => 4]);'
            . '$controller->downloadAttachment($request);';

        $process = proc_open(
            [PHP_BINARY, '-d', 'xdebug.mode=off', '-r', $code],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            project_path(),
            null,
            ['bypass_shell' => true],
        );
        assert_true(is_resource($process), 'The isolated attachment download probe should start.');

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        assert_same(0, $exitCode, 'A valid attachment download should exit normally.');
        assert_same('', $stderr, 'A valid attachment download should not emit a PHP error.');
        assert_same($content, $stdout, 'The download action should stream the stored attachment bytes.');
    } finally {
        if (is_file($filePath)) {
            unlink($filePath);
        }
    }
});

test('missing leave attachment returns controlled file-not-found result', function (): void {
    $missingName = 'missing-' . bin2hex(random_bytes(8)) . '.pdf';
    $controller = make_leave_attachment_controller(
        leave_attachment_download_database('leave_attachments/' . $missingName)
    );

    $download = call_private($controller, 'prepareAttachmentDownload', [4, 21, 'employee']);

    assert_same(404, $download['status']);
    assert_same('File not found.', $download['message']);
});

test('leave attachment traversal outside the attachment directory is denied', function (): void {
    $privateRoot = project_path('storage/private');
    if (!is_dir($privateRoot)) {
        mkdir($privateRoot, 0750, true);
    }

    $markerName = 'leave-traversal-' . bin2hex(random_bytes(8)) . '.pdf';
    $markerPath = $privateRoot . DIRECTORY_SEPARATOR . $markerName;
    file_put_contents($markerPath, 'private marker outside leave attachment storage');

    try {
        assert_true(is_file($markerPath), 'The traversal probe must point to an existing private file.');
        $controller = make_leave_attachment_controller(
            leave_attachment_download_database('leave_attachments/../' . $markerName)
        );

        $download = call_private($controller, 'prepareAttachmentDownload', [4, 1, 'admin']);

        assert_same(404, $download['status']);
        assert_same('File not found.', $download['message']);
    } finally {
        if (is_file($markerPath)) {
            unlink($markerPath);
        }
    }
});

test('leave attachment upload rejects an invalid extension', function (): void {
    $temporaryFile = tempnam(sys_get_temp_dir(), 'sapas-leave-extension-');
    assert_true(is_string($temporaryFile));
    file_put_contents($temporaryFile, leave_attachment_pdf_fixture());

    try {
        $result = call_private(make_leave_attachment_controller(), 'handleAttachmentUpload', [[
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($temporaryFile),
            'tmp_name' => $temporaryFile,
            'name' => 'supporting-document.exe',
        ]]);

        assert_same(Localization::current()->translate('validation.invalid_file_extension'), $result['error']);
        assert_true(is_file($temporaryFile), 'Rejected files must not be moved into private storage.');
    } finally {
        if (is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
    }
});

test('leave attachment upload rejects files larger than five megabytes', function (): void {
    $result = call_private(make_leave_attachment_controller(), 'handleAttachmentUpload', [[
        'error' => UPLOAD_ERR_OK,
        'size' => (5 * 1024 * 1024) + 1,
        'tmp_name' => 'size-validation-stops-before-file-access',
        'name' => 'oversized.pdf',
    ]]);

    assert_same(Localization::current()->translate('validation.file_too_large'), $result['error']);
});

test('PHP upload size errors map to the leave attachment too-large validation message', function (): void {
    foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE] as $uploadError) {
        $result = call_private(make_leave_attachment_controller(), 'handleAttachmentUpload', [[
            'error' => $uploadError,
        ]]);

        assert_same(Localization::current()->translate('validation.file_too_large'), $result['error']);
    }
});

test('Apache and built-in PHP server setup enforce compatible leave upload limits', function (): void {
    $apacheConfig = file_get_contents(project_path('public/.htaccess'));
    $readme = file_get_contents(project_path('README.md'));

    assert_true(is_string($apacheConfig));
    assert_true(is_string($readme));
    assert_contains('<IfModule php_module>', $apacheConfig);
    assert_contains('php_value upload_max_filesize 5M', $apacheConfig);
    assert_contains('php_value post_max_size 6M', $apacheConfig);
    assert_contains('</IfModule>', $apacheConfig);
    assert_contains(
        'php -d upload_max_filesize=5M -d post_max_size=6M -S localhost:8000 -t public public/index.php',
        $readme
    );
});

test('leave attachment remains optional for every leave type', function (): void {
    $form = file_get_contents(project_path('views/leave/form.php'));
    $controller = project_path('src/Controllers/LeaveController.php');

    assert_true(is_string($form));
    assert_contains('name="attachment"', $form);
    assert_not_contains('name="attachment" required', $form);
    assert_method_contains($controller, 'store', "UPLOAD_ERR_NO_FILE");
});
