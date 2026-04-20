<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Validation;

/**
 * OCFL 1.1 validation error/warning codes
 * (https://ocfl.io/1.1/spec/validation-codes.html).
 *
 * Only the codes this package actually emits are enumerated; the set grows
 * as the validator gains coverage.
 */
enum ErrorCode: string
{
    // Storage root / object root structure
    case E001 = 'E001'; // Disallowed content in object root
    case E003 = 'E003'; // Missing NAMASTE declaration
    case E007 = 'E007'; // NAMASTE contents malformed
    case E008 = 'E008'; // Missing versions
    case E010 = 'E010'; // Gap in version sequence
    case E011 = 'E011'; // Version directory name malformed
    case E013 = 'E013'; // Inconsistent version padding
    case E015 = 'E015'; // Content file outside contentDirectory
    case E017 = 'E017'; // contentDirectory invalid (e.g., contains /)
    case E019 = 'E019'; // Inconsistent contentDirectory across versions
    case E046 = 'E046'; // Extra version directory on disk not in versions map
    case E061 = 'E061'; // Sidecar file format invalid
    case E023 = 'E023'; // Content file missing from or extra vs. manifest
    case E052 = 'E052'; // Logical path contains . or .. segments
    case E053 = 'E053'; // Logical path is absolute or has leading slash
    case E063 = 'E063'; // Missing root inventory
    case E064 = 'E064'; // Root inventory differs from head version inventory
    case E067 = 'E067'; // extensions/ contains non-directory
    case E095 = 'E095'; // Conflicting or non-unique logical paths
    case E096 = 'E096'; // Manifest has duplicate digests (case variants)
    case E099 = 'E099'; // Manifest content path malformed (empty segment or dot)
    case E100 = 'E100'; // Manifest content path absolute or contains parent refs
    case E101 = 'E101'; // Manifest content path list has duplicate entries
    case E103 = 'E103'; // Version inventory declares an older spec version
    case E107 = 'E107'; // Manifest entry not referenced by any version state

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

    // Content / manifest integrity
    case E092 = 'E092'; // Content file digest does not match manifest
    case E093 = 'E093'; // Fixity digest does not match content file
    case E097 = 'E097'; // Fixity block has duplicate digests (case variants)

    // Storage root
    case E070 = 'E070'; // Missing or invalid ocfl_layout.json

    // Warnings
    case W001 = 'W001'; // Version directory names zero-padded
    case W002 = 'W002'; // Extra directory inside a version directory
    case W004 = 'W004'; // sha256 used instead of sha512
    case W005 = 'W005'; // id is not a URI
    case W007 = 'W007'; // Version block missing message or user
    case W008 = 'W008'; // user.address missing
    case W009 = 'W009'; // user.address not a URI
    case W010 = 'W010'; // Version directory missing inventory.json
    case W013 = 'W013'; // Unregistered extension in extensions/

    // Version block
    case E048 = 'E048'; // Version block missing required property
    case E049 = 'E049'; // created field malformed
    case E054 = 'E054'; // User object malformed
}
