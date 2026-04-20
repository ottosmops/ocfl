<?php

declare(strict_types=1);

pest()->extend(Tests\TestCase::class)->in('Feature', 'Unit');

function fixture(string $relative): string
{
    $path = __DIR__ . '/fixtures/' . ltrim($relative, '/');

    if (! file_exists($path)) {
        throw new RuntimeException("Fixture not found: {$path}");
    }

    return $path;
}
