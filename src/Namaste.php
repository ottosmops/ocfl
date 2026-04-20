<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl;

use RuntimeException;

/**
 * NAMASTE conformance declaration handling for OCFL 1.1.
 *
 * Per §3.1 a Storage Root MUST contain exactly one `0=ocfl_1.1` file whose
 * contents are `ocfl_1.1\n`; per §4.1 the equivalent holds for Object Roots.
 */
final class Namaste
{
    public static function write(string $directory, NamasteType $type): void
    {
        if (! is_dir($directory)) {
            throw new RuntimeException("Directory does not exist: {$directory}");
        }

        $path = $directory . DIRECTORY_SEPARATOR . $type->filename();

        if (file_put_contents($path, $type->payload()) === false) {
            throw new RuntimeException("Failed to write NAMASTE file: {$path}");
        }
    }

    public static function find(string $directory): ?NamasteType
    {
        foreach (NamasteType::cases() as $type) {
            $path = $directory . DIRECTORY_SEPARATOR . $type->filename();

            if (! is_file($path)) {
                continue;
            }

            $contents = file_get_contents($path);

            if ($contents === false || rtrim($contents, "\n") !== $type->value) {
                throw new RuntimeException(
                    "NAMASTE file is malformed: {$path}",
                );
            }

            return $type;
        }

        return null;
    }
}
