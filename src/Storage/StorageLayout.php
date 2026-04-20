<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Storage;

/**
 * Maps an OCFL object identifier to a storage-root-relative directory path.
 *
 * Implementations correspond to registered OCFL community extensions
 * (https://github.com/OCFL/extensions). Each layout MUST be deterministic:
 * the same id always maps to the same path.
 */
interface StorageLayout
{
    /**
     * The registered extension name, e.g. "0004-hashed-n-tuple-storage-layout".
     */
    public function extensionName(): string;

    /**
     * The storage-root-relative directory path at which an object with the
     * given id lives. Always forward-slash separated, no leading slash.
     */
    public function resolveObjectPath(string $id): string;

    /**
     * Layout-specific configuration as it should appear in
     * `extensions/<extension>/config.json` (per community extension schema).
     *
     * @return array<string, mixed>
     */
    public function configuration(): array;
}
