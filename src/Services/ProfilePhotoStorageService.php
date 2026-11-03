<?php

declare(strict_types=1);

namespace App\Services;

use finfo;

class ProfilePhotoStorageService
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    public function __construct(private ?string $storageRoot = null)
    {
        $this->storageRoot ??= dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'profile_photos';
    }

    /** @param array<string, mixed> $upload */
    public function store(array $upload): string
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new ProfilePhotoException('profile_photo_too_large');
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new ProfilePhotoException('profile_photo_required');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new ProfilePhotoException('profile_photo_upload_failed');
        }

        $temporaryPath = (string) ($upload['tmp_name'] ?? '');
        $declaredSize = (int) ($upload['size'] ?? 0);
        if ($temporaryPath === '' || !$this->isHttpUploadedFile($temporaryPath)) {
            throw new ProfilePhotoException('profile_photo_upload_failed');
        }

        $actualSize = filesize($temporaryPath);
        if ($declaredSize <= 0 || $actualSize === false || $actualSize <= 0) {
            throw new ProfilePhotoException('profile_photo_invalid');
        }
        if ($declaredSize > self::MAX_BYTES || $actualSize > self::MAX_BYTES) {
            throw new ProfilePhotoException('profile_photo_too_large');
        }

        $originalName = basename((string) ($upload['name'] ?? ''));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        $imageInfo = @getimagesize($temporaryPath);
        if (!is_string($mime) || !isset(self::MIME_EXTENSIONS[$mime]) || !in_array($extension, self::MIME_EXTENSIONS[$mime], true)) {
            throw new ProfilePhotoException('profile_photo_invalid_type');
        }
        if (!is_array($imageInfo) || ($imageInfo['mime'] ?? null) !== $mime) {
            throw new ProfilePhotoException('profile_photo_invalid');
        }

        $this->ensureStorageRoot();
        $canonicalExtension = $mime === 'image/jpeg' ? 'jpg' : self::MIME_EXTENSIONS[$mime][0];
        $filename = bin2hex(random_bytes(16)) . '.' . $canonicalExtension;
        $destination = $this->storageRoot . DIRECTORY_SEPARATOR . $filename;

        if (!$this->moveHttpUploadedFile($temporaryPath, $destination)) {
            throw new ProfilePhotoException('profile_photo_upload_failed');
        }
        @chmod($destination, 0640);

        return $filename;
    }

    /** @return array{path: string, mime: string}|null */
    public function resolve(string $filename): ?array
    {
        $path = $this->resolveSafePath($filename);
        if ($path === null) {
            return null;
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!is_string($mime) || !isset(self::MIME_EXTENSIONS[$mime])) {
            return null;
        }

        return ['path' => $path, 'mime' => $mime];
    }

    public function delete(string $filename): bool
    {
        $path = $this->resolveSafePath($filename);
        return $path === null || unlink($path);
    }

    protected function isHttpUploadedFile(string $path): bool
    {
        return is_uploaded_file($path);
    }

    protected function moveHttpUploadedFile(string $source, string $destination): bool
    {
        return move_uploaded_file($source, $destination);
    }

    private function ensureStorageRoot(): void
    {
        if (!is_dir($this->storageRoot) && !mkdir($this->storageRoot, 0750, true) && !is_dir($this->storageRoot)) {
            throw new ProfilePhotoException('profile_photo_upload_failed');
        }
        if (!is_writable($this->storageRoot)) {
            throw new ProfilePhotoException('profile_photo_upload_failed');
        }
    }

    private function resolveSafePath(string $filename): ?string
    {
        if (preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/D', $filename) !== 1 || basename($filename) !== $filename) {
            return null;
        }

        $root = realpath($this->storageRoot);
        $path = realpath($this->storageRoot . DIRECTORY_SEPARATOR . $filename);
        if ($root === false || $path === false || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }
}
