<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Validation;

/**
 * A single diagnostic emitted by the validator.
 *
 * The $code carries the spec-linkable error or warning identifier; the
 * $path is the relative location within the object or storage root at
 * which the issue was detected (empty string for root-level issues).
 */
final readonly class ValidationIssue
{
    public function __construct(
        public ErrorCode $code,
        public string $message,
        public string $path = '',
    ) {
    }
}
