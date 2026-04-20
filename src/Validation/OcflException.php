<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Validation;

use RuntimeException;
use Throwable;

/**
 * Base exception for OCFL spec violations.
 *
 * Carries the spec ErrorCode so callers can discriminate structurally
 * rather than string-matching the message.
 */
class OcflException extends RuntimeException
{
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct("[{$errorCode->value}] {$message}", 0, $previous);
    }
}
