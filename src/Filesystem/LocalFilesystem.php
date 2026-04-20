<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Filesystem;

use FilesystemIterator;
use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\OcflException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Filesystem backed by the POSIX filesystem through PHP stdlib calls.
 *
 * All $path arguments are absolute filesystem paths on the host.
 */
final class LocalFilesystem implements Filesystem
{
    public function read(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException("file does not exist: {$path}");
        }

        $bytes = file_get_contents($path);

        if ($bytes === false) {
            throw new RuntimeException("unable to read file: {$path}");
        }

        return $bytes;
    }

    public function write(string $path, string $contents): void
    {
        $this->createDirectory(dirname($path));

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("unable to write file: {$path}");
        }
    }

    public function copy(string $source, string $destination): void
    {
        if (! is_file($source)) {
            throw new RuntimeException("copy source missing: {$source}");
        }

        $this->createDirectory(dirname($destination));

        if (! copy($source, $destination)) {
            throw new RuntimeException("copy failed: {$source} → {$destination}");
        }
    }

    public function move(string $source, string $destination): void
    {
        $this->createDirectory(dirname($destination));

        if (! rename($source, $destination)) {
            throw new RuntimeException("rename failed: {$source} → {$destination}");
        }
    }

    public function delete(string $path): void
    {
        if (is_file($path) && ! unlink($path)) {
            throw new RuntimeException("unlink failed: {$path}");
        }
    }

    public function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iter as $entry) {
            /** @var SplFileInfo $entry */
            $entry->isDir() ? rmdir((string) $entry) : unlink((string) $entry);
        }

        rmdir($path);
    }

    public function createDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (! mkdir($path, 0o755, true) && ! is_dir($path)) {
            throw new OcflException(ErrorCode::E001, "failed to create directory: {$path}");
        }
    }

    public function fileExists(string $path): bool
    {
        return is_file($path);
    }

    public function directoryExists(string $path): bool
    {
        return is_dir($path);
    }

    public function listDirectory(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $names = [];
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var SplFileInfo $entry */
            $names[] = $entry->getFilename();
        }

        return $names;
    }

    public function listFilesRecursively(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $files = [];
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );

        $prefixLength = strlen($path) + 1;
        foreach ($iter as $entry) {
            /** @var SplFileInfo $entry */
            if (! $entry->isFile()) {
                continue;
            }
            $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), $prefixLength));
        }

        return $files;
    }

    public function digestFile(string $path, DigestAlgorithm $algorithm): string
    {
        if (! is_file($path)) {
            throw new RuntimeException("file does not exist: {$path}");
        }

        $digest = hash_file($algorithm->hashAlgorithm(), $path);

        if ($digest === false) {
            throw new RuntimeException("digest computation failed for: {$path}");
        }

        return $digest;
    }
}
