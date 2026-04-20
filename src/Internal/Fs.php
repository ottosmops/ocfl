<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Internal;

use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\OcflException;

/**
 * @internal Small filesystem primitives used by OcflObject and VersionBuilder.
 */
final class Fs
{
    public static function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (! mkdir($path, 0o755, true) && ! is_dir($path)) {
            throw new OcflException(ErrorCode::E001, "failed to create directory: {$path}");
        }
    }

    public static function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new OcflException(ErrorCode::E001, "failed to write file: {$path}");
        }
    }
}
