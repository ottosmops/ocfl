<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Storage;

use InvalidArgumentException;

/**
 * 0002-flat-direct-storage-layout
 *
 * https://ocfl.github.io/extensions/0002-flat-direct-storage-layout.html
 *
 * The object id is used verbatim as the single directory segment. This is
 * only suitable when all ids are safe, filesystem-legal directory names.
 */
final class FlatDirectStorageLayout implements StorageLayout
{
    public const EXTENSION_NAME = '0002-flat-direct-storage-layout';

    public function extensionName(): string
    {
        return self::EXTENSION_NAME;
    }

    public function resolveObjectPath(string $id): string
    {
        if ($id === '' || $id === '.' || $id === '..') {
            throw new InvalidArgumentException("invalid object id for flat layout: '{$id}'");
        }

        if (str_contains($id, '/') || str_contains($id, '\\') || str_contains($id, "\0")) {
            throw new InvalidArgumentException(
                "object id '{$id}' contains a path separator; flat layout requires filesystem-safe ids",
            );
        }

        return $id;
    }

    public function configuration(): array
    {
        return ['extensionName' => self::EXTENSION_NAME];
    }
}
