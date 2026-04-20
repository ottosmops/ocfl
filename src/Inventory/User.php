<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Inventory;

final readonly class User
{
    public function __construct(
        public string $name,
        public ?string $address = null,
    ) {
    }
}
