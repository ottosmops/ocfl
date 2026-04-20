<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl;

use Ottosmops\Ocfl\Inventory\Inventory;
use Ottosmops\Ocfl\Inventory\InventoryReader;
use Ottosmops\Ocfl\Inventory\InventorySidecar;
use Ottosmops\Ocfl\Inventory\Version;
use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\OcflException;
use OutOfBoundsException;

/**
 * Read-side facade for an on-disk OCFL 1.1 object.
 *
 * Responsibilities:
 *   - Validate the object root layout (NAMASTE + inventory + sidecar).
 *   - Expose metadata (id, head, version names).
 *   - Resolve logical paths to content paths via manifest + version state.
 *   - Stream or copy content to callers, verifying fixity on read.
 */
final readonly class OcflObject
{
    private function __construct(
        public string $path,
        public Inventory $inventory,
    ) {
    }

    public static function open(string $path): self
    {
        if (! is_dir($path)) {
            throw new OcflException(ErrorCode::E003, "object root does not exist: {$path}");
        }

        $namaste = Namaste::find($path);

        if ($namaste !== NamasteType::ObjectRoot) {
            throw new OcflException(
                ErrorCode::E003,
                "object root NAMASTE declaration missing in {$path}",
            );
        }

        $inventoryPath = $path . DIRECTORY_SEPARATOR . 'inventory.json';
        $inventory = InventoryReader::fromFile($inventoryPath);

        if (! InventorySidecar::verify($path, $inventory->digestAlgorithm)) {
            throw new OcflException(
                ErrorCode::E060,
                "inventory sidecar digest does not match inventory.json in {$path}",
            );
        }

        return new self($path, $inventory);
    }

    public function id(): string
    {
        return $this->inventory->id;
    }

    public function head(): string
    {
        return $this->inventory->head;
    }

    /**
     * @return list<string>
     */
    public function versionNames(): array
    {
        return $this->inventory->versionSequence();
    }

    /**
     * Return the sorted list of logical paths present in the given version.
     *
     * @return list<string>
     */
    public function logicalPaths(string $version): array
    {
        $state = $this->requireVersion($version)->state;
        $paths = [];

        foreach ($state as $logicalPaths) {
            foreach ($logicalPaths as $path) {
                $paths[] = $path;
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * Resolve a logical path in a version to its content path, relative to
     * the object root.
     *
     * OCFL uses forward-delta dedup: an unchanged file's content entry may
     * still live in the v1 content directory even when referenced from v3.
     */
    public function resolveContentPath(string $version, string $logicalPath): string
    {
        $state = $this->requireVersion($version)->state;

        foreach ($state as $digest => $paths) {
            if (in_array($logicalPath, $paths, true)) {
                return $this->contentPathForDigest($digest, $version, $logicalPath);
            }
        }

        throw new OutOfBoundsException(
            "logical path '{$logicalPath}' not present in version '{$version}'",
        );
    }

    public function readContent(string $version, string $logicalPath): string
    {
        $relative = $this->resolveContentPath($version, $logicalPath);
        $absolute = $this->path . DIRECTORY_SEPARATOR . $relative;

        if (! is_file($absolute) || ! is_readable($absolute)) {
            throw new OcflException(
                ErrorCode::E092,
                "content file missing at {$absolute}",
            );
        }

        return (string) file_get_contents($absolute);
    }

    /**
     * Materialise a version's logical state into a target directory.
     */
    public function checkout(string $targetDirectory, ?string $version = null): void
    {
        $version ??= $this->inventory->head;
        $state = $this->requireVersion($version)->state;

        if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0o755, true) && ! is_dir($targetDirectory)) {
            throw new OcflException(
                ErrorCode::E001,
                "failed to create checkout target {$targetDirectory}",
            );
        }

        foreach ($state as $digest => $logicalPaths) {
            $source = $this->path . DIRECTORY_SEPARATOR
                . $this->contentPathForDigest($digest, $version, $logicalPaths[0]);

            foreach ($logicalPaths as $logicalPath) {
                $destination = $targetDirectory . DIRECTORY_SEPARATOR . $logicalPath;
                $parent = dirname($destination);

                if (! is_dir($parent) && ! mkdir($parent, 0o755, true) && ! is_dir($parent)) {
                    throw new OcflException(
                        ErrorCode::E001,
                        "failed to create directory {$parent}",
                    );
                }

                if (! copy($source, $destination)) {
                    throw new OcflException(
                        ErrorCode::E092,
                        "failed to copy {$source} to {$destination}",
                    );
                }

                $actual = Digest::ofFile($destination, $this->inventory->digestAlgorithm);

                if (! Digest::equals($actual, $digest)) {
                    throw new OcflException(
                        ErrorCode::E092,
                        "digest mismatch after checkout of '{$logicalPath}'",
                    );
                }
            }
        }
    }

    private function contentPathForDigest(string $digest, string $version, string $logicalPath): string
    {
        $candidates = $this->inventory->manifest[$digest] ?? null;

        if ($candidates === null || $candidates === []) {
            throw new OcflException(
                ErrorCode::E092,
                "digest for logical path '{$logicalPath}' in version '{$version}' not present in manifest",
            );
        }

        return $candidates[0];
    }

    private function requireVersion(string $version): Version
    {
        if (! isset($this->inventory->versions[$version])) {
            throw new OutOfBoundsException("version '{$version}' not present in object");
        }

        return $this->inventory->versions[$version];
    }
}
