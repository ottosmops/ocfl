<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl;

use RuntimeException;

/**
 * Compute and compare OCFL content digests.
 *
 * Per OCFL 1.1 §7.2 digests are serialised as lowercase hexadecimal strings;
 * per §3.5.1 comparisons MUST be case-insensitive.
 */
final class Digest
{
    public static function ofString(string $content, DigestAlgorithm $algorithm): string
    {
        return hash($algorithm->hashAlgorithm(), $content);
    }

    public static function ofFile(string $path, DigestAlgorithm $algorithm): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Unable to compute digest for file: {$path}");
        }

        $digest = hash_file($algorithm->hashAlgorithm(), $path);

        if ($digest === false) {
            throw new RuntimeException("Hashing failed for file: {$path}");
        }

        return $digest;
    }

    public static function equals(string $a, string $b): bool
    {
        return strcasecmp($a, $b) === 0;
    }
}
