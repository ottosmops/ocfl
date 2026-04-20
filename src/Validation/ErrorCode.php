<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Validation;

/**
 * OCFL 1.1 validation error/warning codes.
 *
 * Codes mirror the identifiers in the OCFL 1.1 specification validation
 * codes document (https://ocfl.io/1.1/spec/validation-codes.html) and
 * allow validators to emit structured, spec-linkable diagnostics.
 *
 * Only the codes this package currently emits are enumerated; the set
 * will grow as more phases come online.
 */
enum ErrorCode: string
{
    // Storage root / object root structure
    case E001 = 'E001'; // Disallowed content in object root
    case E003 = 'E003'; // Missing NAMASTE declaration
    case E007 = 'E007'; // NAMASTE contents malformed

    // Inventory structure
    case E033 = 'E033'; // Inventory not valid JSON
    case E034 = 'E034'; // Inventory manifest must be a JSON object
    case E036 = 'E036'; // Inventory missing required property
    case E037 = 'E037'; // Inventory id inconsistent across versions
    case E038 = 'E038'; // Inventory type has wrong value
    case E040 = 'E040'; // Head is not the most recent version
    case E041 = 'E041'; // Manifest missing

    // Content digest
    case E025 = 'E025'; // Unsupported primary digest algorithm
    case E050 = 'E050'; // Digest case (inventory digests must be lowercase)

    // Sidecar
    case E058 = 'E058'; // Inventory sidecar missing or malformed
    case E060 = 'E060'; // Inventory sidecar digest does not match inventory

    // Version block
    case E048 = 'E048'; // Version block missing required property
    case E049 = 'E049'; // created field malformed
    case E054 = 'E054'; // User object malformed
}
