<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Inventory;

use DateTimeImmutable;

/**
 * A single OCFL object version entry (spec §7.3).
 */
final readonly class Version
{
    /**
     * @param  array<string, list<string>>  $state  digest → logical paths
     */
    public function __construct(
        public DateTimeImmutable $created,
        public array $state,
        public ?string $message = null,
        public ?User $user = null,
    ) {
    }
}
