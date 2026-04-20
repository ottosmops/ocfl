<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Filesystem;

use Ottosmops\Ocfl\DigestAlgorithm;

/**
 * Filesystem primitives OCFL needs from its storage backend.
 *
 * A minimal surface tailored to OCFL — not a general-purpose filesystem
 * abstraction. Implementations must treat paths as forward-slash-separated,
 * storage-root-relative keys.
 */
interface Filesystem
{
    public function read(string $path): string;

    public function write(string $path, string $contents): void;

    public function copy(string $source, string $destination): void;

    public function move(string $source, string $destination): void;

    public function delete(string $path): void;

    public function deleteDirectory(string $path): void;

    public function createDirectory(string $path): void;

    public function fileExists(string $path): bool;

    public function directoryExists(string $path): bool;

    /**
     * Return the names (not full paths) of the immediate entries in the
     * given directory, excluding "." and "..". Order is not defined.
     *
     * @return list<string>
     */
    public function listDirectory(string $path): array;

    /**
     * Return the names of every file below $path, at any depth, as
     * forward-slash-separated paths relative to $path.
     *
     * @return list<string>
     */
    public function listFilesRecursively(string $path): array;

    /**
     * Compute a digest of the file at $path without loading the full
     * contents into memory. Implementations should stream where possible.
     */
    public function digestFile(string $path, DigestAlgorithm $algorithm): string;
}
