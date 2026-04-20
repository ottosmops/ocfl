<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Inventory;

use Ottosmops\Ocfl\Digest;
use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Filesystem\Filesystem;
use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\OcflException;
use RuntimeException;

/**
 * Reads and writes the inventory.json digest sidecar file (spec §7.1).
 *
 * Format: `<digest> inventory.json\n`
 */
final class InventorySidecar
{
    public static function filename(DigestAlgorithm $algorithm): string
    {
        return Inventory::FILENAME . '.' . $algorithm->value;
    }

    public static function writeFor(Filesystem $fs, string $directory, DigestAlgorithm $algorithm): string
    {
        $inventoryPath = $directory . '/' . Inventory::FILENAME;

        if (! $fs->fileExists($inventoryPath)) {
            throw new OcflException(
                ErrorCode::E058,
                "cannot write sidecar: inventory.json not found in {$directory}",
            );
        }

        $digest = $fs->digestFile($inventoryPath, $algorithm);
        $sidecarPath = $directory . '/' . self::filename($algorithm);
        $fs->write($sidecarPath, "{$digest} " . Inventory::FILENAME . "\n");

        return $sidecarPath;
    }

    public static function readDigest(Filesystem $fs, string $directory, DigestAlgorithm $algorithm): string
    {
        $path = $directory . '/' . self::filename($algorithm);

        if (! $fs->fileExists($path)) {
            throw new OcflException(ErrorCode::E058, "sidecar missing: {$path}");
        }

        try {
            $contents = $fs->read($path);
        } catch (RuntimeException $e) {
            throw new OcflException(ErrorCode::E058, "sidecar unreadable: {$path}", $e);
        }

        if (! preg_match('/^([a-fA-F0-9]+)\s+inventory\.json$/', rtrim($contents, "\n"), $matches)) {
            throw new OcflException(ErrorCode::E058, "sidecar malformed: {$path}");
        }

        return strtolower($matches[1]);
    }

    public static function verify(Filesystem $fs, string $directory, DigestAlgorithm $algorithm): bool
    {
        $expected = self::readDigest($fs, $directory, $algorithm);
        $actual = $fs->digestFile($directory . '/' . Inventory::FILENAME, $algorithm);

        return Digest::equals($expected, $actual);
    }
}
