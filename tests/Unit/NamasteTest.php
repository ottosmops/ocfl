<?php

declare(strict_types=1);

use Ottosmops\Ocfl\Namaste;
use Ottosmops\Ocfl\NamasteType;

function makeTempDir(): string
{
    $path = sys_get_temp_dir() . '/ocfl-namaste-' . uniqid();
    mkdir($path, 0o755, true);

    return $path;
}

function cleanupTempDir(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    foreach (glob($path . '/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($path);
}

test('storage root namaste produces 0=ocfl_1.1 file', function (): void {
    expect(NamasteType::StorageRoot->filename())->toBe('0=ocfl_1.1')
        ->and(NamasteType::StorageRoot->payload())->toBe("ocfl_1.1\n");
});

test('object root namaste produces 0=ocfl_object_1.1 file', function (): void {
    expect(NamasteType::ObjectRoot->filename())->toBe('0=ocfl_object_1.1')
        ->and(NamasteType::ObjectRoot->payload())->toBe("ocfl_object_1.1\n");
});

test('writes namaste declaration to a directory', function (): void {
    $tmp = makeTempDir();

    try {
        Namaste::write($tmp, NamasteType::ObjectRoot);

        $path = $tmp . '/0=ocfl_object_1.1';
        expect(is_file($path))->toBeTrue()
            ->and(file_get_contents($path))->toBe("ocfl_object_1.1\n");
    } finally {
        cleanupTempDir($tmp);
    }
});

test('detects existing namaste in a directory', function (): void {
    $tmp = makeTempDir();

    try {
        Namaste::write($tmp, NamasteType::StorageRoot);
        expect(Namaste::find($tmp))->toBe(NamasteType::StorageRoot);
    } finally {
        cleanupTempDir($tmp);
    }
});

test('returns null when no namaste file is present', function (): void {
    $tmp = makeTempDir();

    try {
        expect(Namaste::find($tmp))->toBeNull();
    } finally {
        cleanupTempDir($tmp);
    }
});

test('reads a real ocfl fixture object namaste', function (): void {
    $fixture = __DIR__ . '/../fixtures/ocfl/1.1/good-objects/minimal_one_version_one_file';

    expect(Namaste::find($fixture))->toBe(NamasteType::ObjectRoot);
});

test('rejects namaste with mismatched payload', function (): void {
    $tmp = makeTempDir();

    try {
        file_put_contents($tmp . '/0=ocfl_object_1.1', "something_else\n");
        expect(fn () => Namaste::find($tmp))
            ->toThrow(RuntimeException::class, 'malformed');
    } finally {
        cleanupTempDir($tmp);
    }
});
