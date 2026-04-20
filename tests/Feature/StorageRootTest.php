<?php

declare(strict_types=1);

use Ottosmops\Ocfl\NamasteType;
use Ottosmops\Ocfl\Storage\FlatDirectStorageLayout;
use Ottosmops\Ocfl\Storage\HashedNTupleStorageLayout;
use Ottosmops\Ocfl\Storage\StorageRoot;
use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\OcflException;

function storageWorkDir(): string
{
    $path = sys_get_temp_dir() . '/ocfl-storage-' . uniqid();
    mkdir($path, 0o755, true);

    return $path;
}

function rmrfStorage(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iter as $p) {
        /** @var SplFileInfo $p */
        $p->isDir() ? rmdir((string) $p) : unlink((string) $p);
    }

    rmdir($dir);
}

test('creates a storage root with NAMASTE and layout declaration', function (): void {
    $dir = storageWorkDir();

    try {
        $root = StorageRoot::create($dir, new FlatDirectStorageLayout());

        expect(is_file($dir . '/' . NamasteType::StorageRoot->filename()))->toBeTrue()
            ->and(is_file($dir . '/ocfl_layout.json'))->toBeTrue()
            ->and($root->layout()->extensionName())->toBe('0002-flat-direct-storage-layout');
    } finally {
        rmrfStorage($dir);
    }
});

test('opens a previously created storage root and loads its layout', function (): void {
    $dir = storageWorkDir();

    try {
        StorageRoot::create($dir, new HashedNTupleStorageLayout(tupleSize: 3, numberOfTuples: 3));
        $reopened = StorageRoot::open($dir);

        expect($reopened->layout()->extensionName())->toBe('0004-hashed-n-tuple-storage-layout');
    } finally {
        rmrfStorage($dir);
    }
});

test('rejects opening a non-conforming directory with E003', function (): void {
    $dir = storageWorkDir();

    try {
        try {
            StorageRoot::open($dir);
            throw new RuntimeException('expected exception');
        } catch (OcflException $e) {
            expect($e->errorCode)->toBe(ErrorCode::E003);
        }
    } finally {
        rmrfStorage($dir);
    }
});

test('refuses to create a storage root in a non-empty directory', function (): void {
    $dir = storageWorkDir();
    file_put_contents($dir . '/pre-existing.txt', 'blocker');

    try {
        expect(fn () => StorageRoot::create($dir, new FlatDirectStorageLayout()))
            ->toThrow(OcflException::class);
    } finally {
        rmrfStorage($dir);
    }
});

test('creates an object through the storage root and places it via the layout', function (): void {
    $dir = storageWorkDir();

    try {
        $root = StorageRoot::create($dir, new FlatDirectStorageLayout());

        $root->createObject('my-first-object')
            ->newVersion()
            ->addContents('hello.txt', 'hi')
            ->commit();

        expect(is_dir($dir . '/my-first-object'))->toBeTrue()
            ->and(is_file($dir . '/my-first-object/inventory.json'))->toBeTrue();
    } finally {
        rmrfStorage($dir);
    }
});

test('retrieves an object by id via the storage root', function (): void {
    $dir = storageWorkDir();

    try {
        $root = StorageRoot::create($dir, new FlatDirectStorageLayout());

        $root->createObject('obj-a')
            ->newVersion()
            ->addContents('a.txt', 'content-a')
            ->commit();

        $opened = $root->getObject('obj-a');
        expect($opened->id())->toBe('obj-a')
            ->and($opened->readContent('v1', 'a.txt'))->toBe('content-a');
    } finally {
        rmrfStorage($dir);
    }
});

test('places objects via hashed-n-tuple layout at the computed path', function (): void {
    $dir = storageWorkDir();

    try {
        $layout = new HashedNTupleStorageLayout();
        $root = StorageRoot::create($dir, $layout);

        $root->createObject('urn:example:foo')
            ->newVersion()
            ->addContents('x.txt', 'x')
            ->commit();

        $expected = $dir . '/' . $layout->resolveObjectPath('urn:example:foo');
        expect(is_dir($expected))->toBeTrue()
            ->and(is_file($expected . '/inventory.json'))->toBeTrue();
    } finally {
        rmrfStorage($dir);
    }
});

test('listObjects walks the storage root and returns all object ids', function (): void {
    $dir = storageWorkDir();

    try {
        $root = StorageRoot::create($dir, new HashedNTupleStorageLayout());

        foreach (['alpha', 'beta', 'gamma'] as $id) {
            $root->createObject($id)
                ->newVersion()
                ->addContents('x.txt', $id)
                ->commit();
        }

        $ids = $root->listObjects();
        sort($ids);

        expect($ids)->toBe(['alpha', 'beta', 'gamma']);
    } finally {
        rmrfStorage($dir);
    }
});

test('rejects creating an object with an id that already exists', function (): void {
    $dir = storageWorkDir();

    try {
        $root = StorageRoot::create($dir, new FlatDirectStorageLayout());
        $root->createObject('dup')
            ->newVersion()
            ->addContents('x.txt', 'first')
            ->commit();

        expect(fn () => $root->createObject('dup'))->toThrow(OcflException::class);
    } finally {
        rmrfStorage($dir);
    }
});

test('hasObject returns true for present ids and false otherwise', function (): void {
    $dir = storageWorkDir();

    try {
        $root = StorageRoot::create($dir, new FlatDirectStorageLayout());
        $root->createObject('present')
            ->newVersion()
            ->addContents('x.txt', 'x')
            ->commit();

        expect($root->hasObject('present'))->toBeTrue()
            ->and($root->hasObject('absent'))->toBeFalse();
    } finally {
        rmrfStorage($dir);
    }
});
