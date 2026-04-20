<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Inventory;

use Ottosmops\Ocfl\Digest;
use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\OcflException;

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

    public static function writeFor(string $directory, DigestAlgorithm $algorithm): string
    {
        $inventoryPath = $directory . DIRECTORY_SEPARATOR . Inventory::FILENAME;

        if (! is_file($inventoryPath)) {
            throw new OcflException(
                ErrorCode::E058,
                "cannot write sidecar: inventory.json not found in {$directory}",
            );
        }

        $digest = Digest::ofFile($inventoryPath, $algorithm);
        $sidecarPath = $directory . DIRECTORY_SEPARATOR . self::filename($algorithm);

        if (file_put_contents($sidecarPath, "{$digest} " . Inventory::FILENAME . "\n") === false) {
            throw new OcflException(ErrorCode::E058, "failed to write sidecar at {$sidecarPath}");
        }

        return $sidecarPath;
    }

    public static function readDigest(string $directory, DigestAlgorithm $algorithm): string
    {
        $path = $directory . DIRECTORY_SEPARATOR . self::filename($algorithm);

        if (! is_file($path)) {
            throw new OcflException(ErrorCode::E058, "sidecar missing: {$path}");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new OcflException(ErrorCode::E058, "sidecar unreadable: {$path}");
        }

        if (! preg_match('/^([a-fA-F0-9]+)\s+inventory\.json$/', rtrim($contents, "\n"), $matches)) {
            throw new OcflException(ErrorCode::E058, "sidecar malformed: {$path}");
        }

        return strtolower($matches[1]);
    }

    public static function verify(string $directory, DigestAlgorithm $algorithm): bool
    {
        $expected = self::readDigest($directory, $algorithm);
        $actual = Digest::ofFile(
            $directory . DIRECTORY_SEPARATOR . Inventory::FILENAME,
            $algorithm,
        );

        return Digest::equals($expected, $actual);
    }
}
