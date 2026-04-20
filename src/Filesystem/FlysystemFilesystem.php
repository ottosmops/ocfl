<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Filesystem;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;
use Ottosmops\Ocfl\DigestAlgorithm;
use RuntimeException;

/**
 * Adapts a league/flysystem v3 FilesystemOperator to the OCFL Filesystem
 * contract. All paths are treated as Flysystem keys (always forward-slash
 * separated, no leading slash required).
 *
 * Requires league/flysystem ^3.0 as a suggested dependency.
 */
final class FlysystemFilesystem implements Filesystem
{
    public function __construct(private readonly FilesystemOperator $flysystem)
    {
    }

    public function read(string $path): string
    {
        return $this->flysystem->read(self::normalise($path));
    }

    public function write(string $path, string $contents): void
    {
        $this->flysystem->write(self::normalise($path), $contents);
    }

    public function copy(string $source, string $destination): void
    {
        $this->flysystem->copy(self::normalise($source), self::normalise($destination));
    }

    public function move(string $source, string $destination): void
    {
        $sourceKey = self::normalise($source);
        $destKey = self::normalise($destination);

        // Flysystem's native move() is file-only. If the source is a
        // directory, fall back to a recursive copy-then-delete so callers
        // that do directory renames (e.g. VersionBuilder staging) work on
        // object-store backends — at the cost of atomicity, which cloud
        // stores never provide for directory-level operations anyway.
        if ($this->flysystem->directoryExists($sourceKey)) {
            $this->flysystem->createDirectory($destKey);
            foreach ($this->flysystem->listContents($sourceKey, deep: true) as $entry) {
                if (! $entry->isFile()) {
                    continue;
                }
                $relative = substr($entry->path(), strlen($sourceKey) + 1);
                $this->flysystem->move($entry->path(), $destKey . '/' . $relative);
            }
            $this->flysystem->deleteDirectory($sourceKey);

            return;
        }

        $this->flysystem->move($sourceKey, $destKey);
    }

    public function delete(string $path): void
    {
        $this->flysystem->delete(self::normalise($path));
    }

    public function deleteDirectory(string $path): void
    {
        $this->flysystem->deleteDirectory(self::normalise($path));
    }

    public function createDirectory(string $path): void
    {
        $this->flysystem->createDirectory(self::normalise($path));
    }

    public function fileExists(string $path): bool
    {
        return $this->flysystem->fileExists(self::normalise($path));
    }

    public function directoryExists(string $path): bool
    {
        return $this->flysystem->directoryExists(self::normalise($path));
    }

    public function listDirectory(string $path): array
    {
        $prefix = self::normalise($path);
        $names = [];

        /** @var StorageAttributes $entry */
        foreach ($this->flysystem->listContents($prefix, deep: false) as $entry) {
            $names[] = basename($entry->path());
        }

        return $names;
    }

    public function listFilesRecursively(string $path): array
    {
        $prefix = self::normalise($path);
        $files = [];
        $prefixLength = $prefix === '' ? 0 : strlen($prefix) + 1;

        /** @var StorageAttributes $entry */
        foreach ($this->flysystem->listContents($prefix, deep: true) as $entry) {
            if (! $entry->isFile()) {
                continue;
            }
            $files[] = substr($entry->path(), $prefixLength);
        }

        return $files;
    }

    public function digestFile(string $path, DigestAlgorithm $algorithm): string
    {
        $stream = $this->flysystem->readStream(self::normalise($path));

        $context = hash_init($algorithm->hashAlgorithm());
        while (! feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                fclose($stream);

                throw new RuntimeException("digest read failed for: {$path}");
            }
            hash_update($context, $chunk);
        }
        fclose($stream);

        return hash_final($context);
    }

    private static function normalise(string $path): string
    {
        return ltrim($path, '/');
    }
}
