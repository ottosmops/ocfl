<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl;

use Ottosmops\Ocfl\Filesystem\Filesystem;
use Ottosmops\Ocfl\Filesystem\LocalFilesystem;
use RuntimeException;

/**
 * NAMASTE conformance declaration handling for OCFL 1.1.
 *
 * Per §3.1 a Storage Root MUST contain exactly one `0=ocfl_1.1` file whose
 * contents are `ocfl_1.1\n`; per §4.1 the equivalent holds for Object Roots.
 */
final class Namaste
{
    public static function write(Filesystem $fs, string $directory, NamasteType $type): void
    {
        if (! $fs->directoryExists($directory)) {
            throw new RuntimeException("Directory does not exist: {$directory}");
        }

        $fs->write($directory . '/' . $type->filename(), $type->payload());
    }

    public static function find(Filesystem $fs, string $directory): ?NamasteType
    {
        foreach (NamasteType::cases() as $type) {
            $path = $directory . '/' . $type->filename();

            if (! $fs->fileExists($path)) {
                continue;
            }

            $contents = $fs->read($path);

            if (rtrim($contents, "\n") !== $type->value) {
                throw new RuntimeException("NAMASTE file is malformed: {$path}");
            }

            return $type;
        }

        return null;
    }

    public static function local(): Filesystem
    {
        return new LocalFilesystem();
    }
}
