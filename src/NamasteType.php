<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl;

/**
 * NAMASTE declaration types used by OCFL 1.1 (§3.1, §4.1).
 *
 * Storage Root:  0=ocfl_1.1
 * Object Root:   0=ocfl_object_1.1
 */
enum NamasteType: string
{
    case StorageRoot = 'ocfl_1.1';
    case ObjectRoot = 'ocfl_object_1.1';

    public function filename(): string
    {
        return '0=' . $this->value;
    }

    public function payload(): string
    {
        return $this->value . "\n";
    }
}
