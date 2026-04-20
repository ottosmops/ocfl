<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Storage;

use InvalidArgumentException;
use Ottosmops\Ocfl\Digest;
use Ottosmops\Ocfl\DigestAlgorithm;

/**
 * 0004-hashed-n-tuple-storage-layout
 *
 * https://ocfl.github.io/extensions/0004-hashed-n-tuple-storage-layout.html
 *
 * The object id is hashed, and the first $numberOfTuples × $tupleSize hex
 * characters are taken as a sequence of path segments. The full hash (or a
 * short suffix, when $shortObjectRoot is true) becomes the final segment.
 */
final class HashedNTupleStorageLayout implements StorageLayout
{
    public const EXTENSION_NAME = '0004-hashed-n-tuple-storage-layout';

    public function __construct(
        private readonly DigestAlgorithm $digestAlgorithm = DigestAlgorithm::Sha256,
        private readonly int $tupleSize = 3,
        private readonly int $numberOfTuples = 3,
        private readonly bool $shortObjectRoot = false,
    ) {
        if ($tupleSize < 1 || $numberOfTuples < 1) {
            throw new InvalidArgumentException('tupleSize and numberOfTuples must both be >= 1');
        }

        $hashLength = strlen(hash($digestAlgorithm->hashAlgorithm(), ''));

        if ($tupleSize * $numberOfTuples > $hashLength) {
            throw new InvalidArgumentException(
                "tupleSize × numberOfTuples ({$tupleSize}×{$numberOfTuples}) exceeds "
                . "{$digestAlgorithm->value} digest length ({$hashLength})",
            );
        }
    }

    public function extensionName(): string
    {
        return self::EXTENSION_NAME;
    }

    public function resolveObjectPath(string $id): string
    {
        $hash = Digest::ofString($id, $this->digestAlgorithm);

        $segments = [];
        for ($i = 0; $i < $this->numberOfTuples; $i++) {
            $segments[] = substr($hash, $i * $this->tupleSize, $this->tupleSize);
        }

        $segments[] = $this->shortObjectRoot
            ? substr($hash, $this->tupleSize * $this->numberOfTuples)
            : $hash;

        return implode('/', $segments);
    }

    public function configuration(): array
    {
        return [
            'extensionName' => self::EXTENSION_NAME,
            'digestAlgorithm' => $this->digestAlgorithm->value,
            'tupleSize' => $this->tupleSize,
            'numberOfTuples' => $this->numberOfTuples,
            'shortObjectRoot' => $this->shortObjectRoot,
        ];
    }
}
