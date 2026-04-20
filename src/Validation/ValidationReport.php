<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Validation;

/**
 * Result of a validator run. Separates hard errors from warnings so callers
 * can gate their logic on `isValid()` while still surfacing advisories.
 */
final class ValidationReport
{
    /** @var list<ValidationIssue> */
    private array $errors = [];

    /** @var list<ValidationIssue> */
    private array $warnings = [];

    public function addError(ErrorCode $code, string $message, string $path = ''): void
    {
        $this->errors[] = new ValidationIssue($code, $message, $path);
    }

    public function addWarning(ErrorCode $code, string $message, string $path = ''): void
    {
        $this->warnings[] = new ValidationIssue($code, $message, $path);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /**
     * @return list<ValidationIssue>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<ValidationIssue>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return list<ErrorCode>
     */
    public function errorCodes(): array
    {
        return array_map(fn (ValidationIssue $i) => $i->code, $this->errors);
    }

    /**
     * @return list<ErrorCode>
     */
    public function warningCodes(): array
    {
        return array_map(fn (ValidationIssue $i) => $i->code, $this->warnings);
    }

    public function hasError(ErrorCode $code): bool
    {
        foreach ($this->errors as $issue) {
            if ($issue->code === $code) {
                return true;
            }
        }

        return false;
    }

    public function hasWarning(ErrorCode $code): bool
    {
        foreach ($this->warnings as $issue) {
            if ($issue->code === $code) {
                return true;
            }
        }

        return false;
    }
}
