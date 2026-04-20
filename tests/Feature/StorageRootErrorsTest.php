<?php

declare(strict_types=1);

use Ottosmops\Ocfl\Namaste;
use Ottosmops\Ocfl\NamasteType;
use Ottosmops\Ocfl\Storage\FlatDirectStorageLayout;
use Ottosmops\Ocfl\Storage\StorageRoot;
use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\OcflException;

function srErrDir(): string
{
    $p = sys_get_temp_dir() . '/ocfl-sr-err-' . uniqid();
    mkdir($p, 0o755, true);

    return $p;
}

function srErrCleanup(string $p): void
{
    if (! is_dir($p)) {
        return;
    }
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iter as $entry) {
        /** @var SplFileInfo $entry */
        $entry->isDir() ? rmdir((string) $entry) : unlink((string) $entry);
    }
    rmdir($p);
}

test('open rejects a missing storage root path with E003', function (): void {
    try {
        StorageRoot::open('/tmp/ocfl-missing-' . uniqid());
    } catch (OcflException $e) {
        expect($e->errorCode)->toBe(ErrorCode::E003);

        return;
    }
    throw new RuntimeException('expected OcflException');
});

test('open rejects a root without ocfl_layout.json with E070', function (): void {
    $dir = srErrDir();

    try {
        // NAMASTE present but no layout config.
        Namaste::write(Namaste::local(), $dir, NamasteType::StorageRoot);

        try {
            StorageRoot::open($dir);
            throw new RuntimeException('expected OcflException');
        } catch (OcflException $e) {
            expect($e->errorCode)->toBe(ErrorCode::E070);
        }
    } finally {
        srErrCleanup($dir);
    }
});

test('open rejects malformed ocfl_layout.json with E070', function (): void {
    $dir = srErrDir();

    try {
        Namaste::write(Namaste::local(), $dir, NamasteType::StorageRoot);
        file_put_contents($dir . '/ocfl_layout.json', 'not valid json');

        try {
            StorageRoot::open($dir);
            throw new RuntimeException('expected OcflException');
        } catch (OcflException $e) {
            expect($e->errorCode)->toBe(ErrorCode::E070);
        }
    } finally {
        srErrCleanup($dir);
    }
});

test('open rejects ocfl_layout.json missing extensionName with E070', function (): void {
    $dir = srErrDir();

    try {
        Namaste::write(Namaste::local(), $dir, NamasteType::StorageRoot);
        file_put_contents($dir . '/ocfl_layout.json', (string) json_encode(['wrong' => 'key']));

        try {
            StorageRoot::open($dir);
            throw new RuntimeException('expected OcflException');
        } catch (OcflException $e) {
            expect($e->errorCode)->toBe(ErrorCode::E070);
        }
    } finally {
        srErrCleanup($dir);
    }
});

test('open rejects an unsupported layout extension with E070', function (): void {
    $dir = srErrDir();

    try {
        Namaste::write(Namaste::local(), $dir, NamasteType::StorageRoot);
        file_put_contents(
            $dir . '/ocfl_layout.json',
            (string) json_encode(['extensionName' => '9999-not-a-real-layout']),
        );

        try {
            StorageRoot::open($dir);
            throw new RuntimeException('expected OcflException');
        } catch (OcflException $e) {
            expect($e->errorCode)->toBe(ErrorCode::E070);
        }
    } finally {
        srErrCleanup($dir);
    }
});

test('create refuses to init on an already-populated directory', function (): void {
    $dir = srErrDir();

    try {
        file_put_contents($dir . '/blocker.txt', 'in the way');

        try {
            StorageRoot::create($dir, new FlatDirectStorageLayout());
            throw new RuntimeException('expected OcflException');
        } catch (OcflException $e) {
            expect($e->errorCode)->toBe(ErrorCode::E001);
        }
    } finally {
        srErrCleanup($dir);
    }
});

test('getObject throws when id does not exist under the root', function (): void {
    $dir = srErrDir();

    try {
        $root = StorageRoot::create($dir, new FlatDirectStorageLayout());

        expect(fn () => $root->getObject('nope'))->toThrow(OcflException::class);
    } finally {
        srErrCleanup($dir);
    }
});
