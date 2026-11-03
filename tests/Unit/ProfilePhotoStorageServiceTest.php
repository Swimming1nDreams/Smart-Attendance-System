<?php

declare(strict_types=1);

use App\Services\ProfilePhotoException;
use App\Services\ProfilePhotoStorageService;

final class TestableProfilePhotoStorageService extends ProfilePhotoStorageService
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

function profile_photo_png_fixture(): string
{
    $decoded = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    if (!is_string($decoded)) {
        throw new RuntimeException('Unable to decode the profile photo fixture.');
    }
    return $decoded;
}

/** @param callable(): void $callback */
function assert_profile_photo_error(string $expectedKey, callable $callback): void
{
    try {
        $callback();
    } catch (ProfilePhotoException $exception) {
        assert_same($expectedKey, $exception->errorKey());
        return;
    }

    throw new RuntimeException('Expected a profile photo validation error.');
}

function profile_photo_test_root(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sapas-profile-photo-' . bin2hex(random_bytes(8));
}

test('profile photo storage accepts a real PNG and stores only a random safe filename', function (): void {
    $root = profile_photo_test_root();
    $temporaryFile = tempnam(sys_get_temp_dir(), 'sapas-profile-upload-');
    assert_true(is_string($temporaryFile));
    $content = profile_photo_png_fixture();
    file_put_contents($temporaryFile, $content);

    try {
        $service = new TestableProfilePhotoStorageService($root);
        $filename = $service->store([
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($content),
            'tmp_name' => $temporaryFile,
            'name' => 'portrait.PNG',
        ]);

        assert_same(1, preg_match('/^[a-f0-9]{32}\.png$/D', $filename));
        assert_not_contains('portrait', $filename);
        $stored = $service->resolve($filename);
        assert_not_null($stored);
        assert_same('image/png', $stored['mime']);
        assert_same($content, file_get_contents($stored['path']));
        assert_true($service->delete($filename));
        assert_null($service->resolve($filename));
    } finally {
        if (is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
        if (is_dir($root)) {
            rmdir($root);
        }
    }
});

test('profile photo storage rejects extension and content type mismatches', function (): void {
    $root = profile_photo_test_root();
    $temporaryFile = tempnam(sys_get_temp_dir(), 'sapas-profile-upload-');
    assert_true(is_string($temporaryFile));
    $content = profile_photo_png_fixture();
    file_put_contents($temporaryFile, $content);

    try {
        $service = new TestableProfilePhotoStorageService($root);
        assert_profile_photo_error('profile_photo_invalid_type', static function () use ($service, $temporaryFile, $content): void {
            $service->store([
                'error' => UPLOAD_ERR_OK,
                'size' => strlen($content),
                'tmp_name' => $temporaryFile,
                'name' => 'portrait.php',
            ]);
        });
    } finally {
        if (is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
        if (is_dir($root)) {
            rmdir($root);
        }
    }
});

test('profile photo storage rejects oversized files before image processing', function (): void {
    $root = profile_photo_test_root();
    $temporaryFile = tempnam(sys_get_temp_dir(), 'sapas-profile-upload-');
    assert_true(is_string($temporaryFile));
    file_put_contents($temporaryFile, str_repeat('x', ProfilePhotoStorageService::MAX_BYTES + 1));

    try {
        $service = new TestableProfilePhotoStorageService($root);
        assert_profile_photo_error('profile_photo_too_large', static function () use ($service, $temporaryFile): void {
            $service->store([
                'error' => UPLOAD_ERR_OK,
                'size' => ProfilePhotoStorageService::MAX_BYTES + 1,
                'tmp_name' => $temporaryFile,
                'name' => 'large.png',
            ]);
        });
    } finally {
        if (is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
        if (is_dir($root)) {
            rmdir($root);
        }
    }
});

test('profile photo removal ignores traversal and non-random filenames', function (): void {
    $root = profile_photo_test_root();
    mkdir($root, 0750, true);
    $outside = tempnam(sys_get_temp_dir(), 'sapas-profile-outside-');
    assert_true(is_string($outside));
    file_put_contents($outside, profile_photo_png_fixture());

    try {
        $service = new TestableProfilePhotoStorageService($root);
        assert_null($service->resolve('../' . basename($outside)));
        assert_true($service->delete('../' . basename($outside)));
        assert_true(is_file($outside), 'A traversal value must never delete a file outside private profile storage.');
        assert_null($service->resolve('portrait.png'));
    } finally {
        if (is_file($outside)) {
            unlink($outside);
        }
        if (is_dir($root)) {
            rmdir($root);
        }
    }
});

test('profile photo removal can clean up a safely named referenced file even if its contents are damaged', function (): void {
    $root = profile_photo_test_root();
    mkdir($root, 0750, true);
    $filename = str_repeat('b', 32) . '.png';
    $path = $root . DIRECTORY_SEPARATOR . $filename;
    file_put_contents($path, 'damaged image bytes');

    try {
        $service = new TestableProfilePhotoStorageService($root);
        assert_null($service->resolve($filename));
        assert_true($service->delete($filename));
        assert_false(is_file($path));
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
        if (is_dir($root)) {
            rmdir($root);
        }
    }
});
