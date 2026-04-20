<?php

declare(strict_types=1);

use Ottosmops\Ocfl\Filesystem\LocalFilesystem;
use Ottosmops\Ocfl\Namaste;
use Ottosmops\Ocfl\NamasteType;

test('Namaste::write refuses to write into a non-existent directory', function (): void {
    expect(fn () => Namaste::write(
        new LocalFilesystem(),
        '/tmp/ocfl-nope-' . uniqid(),
        NamasteType::ObjectRoot,
    ))->toThrow(RuntimeException::class);
});

test('Namaste::local returns a usable LocalFilesystem', function (): void {
    $fs = Namaste::local();

    expect($fs)->toBeInstanceOf(LocalFilesystem::class);
});
