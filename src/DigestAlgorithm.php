<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl;

/**
 * Digest algorithms recognised by the OCFL 1.1 specification (§7.2).
 *
 * Only sha512 and sha256 are allowed as the primary content-addressing
 * algorithm. The others are permitted only as fixity supplements.
 */
enum DigestAlgorithm: string
{
    case Sha512 = 'sha512';
    case Sha256 = 'sha256';
    case Sha1 = 'sha1';
    case Md5 = 'md5';
    case Blake2b512 = 'blake2b-512';

    public function isPrimary(): bool
    {
        return $this === self::Sha512 || $this === self::Sha256;
    }

    public function hashAlgorithm(): string
    {
        return match ($this) {
            self::Blake2b512 => 'blake2b',
            default => $this->value,
        };
    }
}
