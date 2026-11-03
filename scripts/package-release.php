<?php

declare(strict_types=1);

/**
 * @return string Absolute path to the generated release archive.
 */
function buildSapasReleasePackage(string $projectRoot, string $outputDirectory, ?DateTimeImmutable $createdAt = null): string
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('ZIP support is unavailable. Enable the PHP zip extension and try again.');
    }

    $resolvedRoot = realpath($projectRoot);

    if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
        throw new RuntimeException('The OAAS project root could not be resolved.');
    }

    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException('The release output directory could not be created.');
    }

    $resolvedOutputDirectory = realpath($outputDirectory);

    if ($resolvedOutputDirectory === false || !is_dir($resolvedOutputDirectory)) {
        throw new RuntimeException('The release output directory could not be resolved.');
    }

    $createdAt ??= new DateTimeImmutable('now');
    $baseName = 'OAAS-release-' . $createdAt->format('Ymd-His');
    $archivePath = $resolvedOutputDirectory . DIRECTORY_SEPARATOR . $baseName . '.zip';
    $suffix = 1;

    while (file_exists($archivePath)) {
        $archivePath = $resolvedOutputDirectory . DIRECTORY_SEPARATOR . sprintf('%s-%02d.zip', $baseName, $suffix);
        $suffix++;
    }

    $zip = new ZipArchive();
    $openResult = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::EXCL);

    if ($openResult !== true) {
        throw new RuntimeException('The release ZIP could not be created.');
    }

    try {
        $directory = new RecursiveDirectoryIterator($resolvedRoot, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            static function (SplFileInfo $entry) use ($resolvedRoot): bool {
                $relativePath = substr($entry->getPathname(), strlen($resolvedRoot) + 1);

                return !shouldExcludeSapasReleasePath($relativePath, $entry->isDir());
            }
        );
        $files = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }

            $absolutePath = $file->getPathname();
            $relativePath = str_replace('\\', '/', substr($absolutePath, strlen($resolvedRoot) + 1));

            if (!$zip->addFile($absolutePath, $relativePath)) {
                throw new RuntimeException('A required project file could not be added to the release ZIP.');
            }
        }

        if (!$zip->close()) {
            throw new RuntimeException('The release ZIP could not be finalized.');
        }
    } catch (Throwable $exception) {
        $zip->close();

        if (is_file($archivePath)) {
            unlink($archivePath);
        }

        throw $exception;
    }

    return $archivePath;
}

function shouldExcludeSapasReleasePath(string $relativePath, bool $isDirectory): bool
{
    $normalized = trim(str_replace('\\', '/', $relativePath), '/');
    $lower = strtolower($normalized);
    $parts = $lower === '' ? [] : explode('/', $lower);
    $baseName = $parts === [] ? '' : (string) end($parts);

    if ($baseName === '.ds_store' || ($baseName !== '.env.example' && str_starts_with($baseName, '.env'))) {
        return true;
    }

    if ($lower === 'config/mail.local.php') {
        return true;
    }

    $excludedDirectoryNames = ['.git', '.agents', '.codex', '.uploads', 'vendor', 'node_modules', 'coverage', 'tmp', 'temp', 'cache', 'logs', 'releases', 'scratch', 'storage', 'oapas'];

    foreach ($parts as $part) {
        if (in_array($part, $excludedDirectoryNames, true)) {
            return true;
        }
    }

    if (
        str_starts_with($lower, 'storage/backups/')
        || str_starts_with($lower, 'storage/generated-reports/')
        || str_starts_with($lower, 'storage/private/')
        || str_starts_with($lower, 'storage/sessions/')
        || str_starts_with($lower, 'storage/pdf-export/')
        || str_starts_with($lower, 'storage/dompdf-fonts/')
    ) {
        return true;
    }

    if (
        !$isDirectory
        && (
            str_ends_with($lower, '.log')
            || str_ends_with($lower, '.zip')
            || str_ends_with($lower, '.phase5bak')
            || (str_ends_with($lower, '.sql') && !str_starts_with($lower, 'migrations/'))
        )
    ) {
        return true;
    }

    if (!$isDirectory && in_array($baseName, [
        'debug_creation.txt',
        'tests_output.txt',
        'tests_final_output.txt',
        'tests_final_output_2.txt',
    ], true)) {
        return true;
    }

    return false;
}

function resolveSapasReleaseOutputDirectory(array $arguments, string $projectRoot): string
{
    foreach (array_slice($arguments, 1) as $argument) {
        if (str_starts_with($argument, '--output-dir=')) {
            $outputDirectory = trim(substr($argument, strlen('--output-dir=')));

            if ($outputDirectory === '') {
                throw new InvalidArgumentException('The --output-dir value cannot be empty.');
            }

            return $outputDirectory;
        }

        throw new InvalidArgumentException('Unknown package option. Supported option: --output-dir=PATH');
    }

    return $projectRoot . DIRECTORY_SEPARATOR . 'releases';
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $root = dirname(__DIR__);
        $outputDirectory = resolveSapasReleaseOutputDirectory($argv ?? [], $root);
        $archivePath = buildSapasReleasePackage($root, $outputDirectory);
        echo 'Release package created: ' . $archivePath . PHP_EOL;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Release packaging failed: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}
