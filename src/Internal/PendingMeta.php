<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Internal;

use JsonException;
use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Filesystem\Filesystem;

/**
 * Crossing the create → commit boundary in a CLI workflow means the
 * two calls run in separate PHP processes. Between them we need to
 * remember the object id and chosen digest algorithm: OCFL's own
 * inventory.json only appears at first commit, so we park a tiny
 * metadata file alongside the NAMASTE declaration and remove it once
 * the real inventory is written.
 *
 * @internal
 */
final class PendingMeta
{
    public const FILENAME = '.ocfl-pending-meta.json';

    public function __construct(
        public readonly string $id,
        public readonly DigestAlgorithm $digestAlgorithm,
    ) {
    }

    public static function path(string $objectRoot): string
    {
        return $objectRoot . '/' . self::FILENAME;
    }

    public static function write(Filesystem $fs, string $objectRoot, self $meta): void
    {
        $fs->write(self::path($objectRoot), (string) json_encode([
            'id' => $meta->id,
            'digestAlgorithm' => $meta->digestAlgorithm->value,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function read(Filesystem $fs, string $objectRoot): ?self
    {
        $path = self::path($objectRoot);

        if (! $fs->fileExists($path)) {
            return null;
        }

        try {
            /** @var array{id?: mixed, digestAlgorithm?: mixed} $meta */
            $meta = json_decode($fs->read($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $id = is_string($meta['id'] ?? null) ? $meta['id'] : '';
        $algoValue = is_string($meta['digestAlgorithm'] ?? null) ? $meta['digestAlgorithm'] : null;
        $algorithm = $algoValue !== null
            ? DigestAlgorithm::tryFrom($algoValue) ?? DigestAlgorithm::Sha512
            : DigestAlgorithm::Sha512;

        return new self($id, $algorithm);
    }

    public static function discard(Filesystem $fs, string $objectRoot): void
    {
        $path = self::path($objectRoot);
        if ($fs->fileExists($path)) {
            $fs->delete($path);
        }
    }
}
