<?php

declare(strict_types=1);

use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\NamasteType;
use Ottosmops\Ocfl\OcflObject;

function makeWorkDir(): string
{
    $path = sys_get_temp_dir() . '/ocfl-commit-' . uniqid();
    mkdir($path, 0o755, true);

    return $path;
}

function rmrf(string $dir): void
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

function writeSource(string $dir, string $filename, string $contents): string
{
    $path = $dir . DIRECTORY_SEPARATOR . $filename;
    $parent = dirname($path);
    if (! is_dir($parent)) {
        mkdir($parent, 0o755, true);
    }
    file_put_contents($path, $contents);

    return $path;
}

test('creates an empty object with NAMASTE and empty v0 inventory', function (): void {
    $dir = makeWorkDir();

    try {
        $object = OcflObject::create(path: $dir, id: 'urn:test:empty');

        expect(is_file($dir . '/' . NamasteType::ObjectRoot->filename()))->toBeTrue()
            ->and($object->id())->toBe('urn:test:empty')
            ->and($object->versionNames())->toBe([]);
    } finally {
        rmrf($dir);
    }
});

test('commits v1 with a single file from disk', function (): void {
    $dir = makeWorkDir();
    $src = makeWorkDir();

    try {
        $sourceFile = writeSource($src, 'hello.txt', 'Hello, OCFL!');

        $object = OcflObject::create(path: $dir, id: 'urn:test:one')
            ->newVersion()
            ->addFile('hello.txt', $sourceFile)
            ->withMessage('First import')
            ->withUser('Alice', 'mailto:alice@example.com')
            ->commit();

        expect($object->versionNames())->toBe(['v1'])
            ->and($object->head())->toBe('v1')
            ->and($object->readContent('v1', 'hello.txt'))->toBe('Hello, OCFL!')
            ->and(is_file($dir . '/v1/content/hello.txt'))->toBeTrue()
            ->and(is_file($dir . '/v1/inventory.json'))->toBeTrue()
            ->and(is_file($dir . '/v1/inventory.json.sha512'))->toBeTrue()
            ->and(is_file($dir . '/inventory.json'))->toBeTrue()
            ->and(is_file($dir . '/inventory.json.sha512'))->toBeTrue();
    } finally {
        rmrf($dir);
        rmrf($src);
    }
});

test('reopens a committed object identically', function (): void {
    $dir = makeWorkDir();
    $src = makeWorkDir();

    try {
        $sourceFile = writeSource($src, 'hello.txt', 'Hello, OCFL!');

        OcflObject::create(path: $dir, id: 'urn:test:reopen')
            ->newVersion()
            ->addFile('hello.txt', $sourceFile)
            ->commit();

        $reopened = OcflObject::open($dir);

        expect($reopened->id())->toBe('urn:test:reopen')
            ->and($reopened->head())->toBe('v1')
            ->and($reopened->readContent('v1', 'hello.txt'))->toBe('Hello, OCFL!');
    } finally {
        rmrf($dir);
        rmrf($src);
    }
});

test('commits v2 with added and removed files', function (): void {
    $dir = makeWorkDir();
    $src = makeWorkDir();

    try {
        $a = writeSource($src, 'a.txt', 'aaa');
        $b = writeSource($src, 'b.txt', 'bbb');
        $c = writeSource($src, 'c.txt', 'ccc');

        $v1 = OcflObject::create(path: $dir, id: 'urn:test:multi')
            ->newVersion()
            ->addFile('a.txt', $a)
            ->addFile('b.txt', $b)
            ->commit();

        $v2 = $v1->newVersion()
            ->addFile('c.txt', $c)
            ->removeFile('a.txt')
            ->commit();

        expect($v2->versionNames())->toBe(['v1', 'v2'])
            ->and($v2->head())->toBe('v2')
            ->and($v2->logicalPaths('v2'))->toBe(['b.txt', 'c.txt'])
            ->and($v2->logicalPaths('v1'))->toBe(['a.txt', 'b.txt'])
            // v2 state references v1's content for unchanged b.txt (forward-delta)
            ->and($v2->resolveContentPath('v2', 'b.txt'))->toBe('v1/content/b.txt')
            ->and($v2->resolveContentPath('v2', 'c.txt'))->toBe('v2/content/c.txt');
    } finally {
        rmrf($dir);
        rmrf($src);
    }
});

test('dedups content with identical digest across versions', function (): void {
    $dir = makeWorkDir();
    $src = makeWorkDir();

    try {
        $a = writeSource($src, 'a.txt', 'same content');
        $b = writeSource($src, 'b.txt', 'same content'); // identical bytes → same digest

        $object = OcflObject::create(path: $dir, id: 'urn:test:dedup')
            ->newVersion()
            ->addFile('a.txt', $a)
            ->commit()
            ->newVersion()
            ->addFile('b.txt', $b)
            ->commit();

        expect($object->resolveContentPath('v2', 'b.txt'))->toBe('v1/content/a.txt')
            ->and(is_file($dir . '/v2/content/b.txt'))->toBeFalse(); // dedup: no new copy
    } finally {
        rmrf($dir);
        rmrf($src);
    }
});

test('supports inline content addition without a source file', function (): void {
    $dir = makeWorkDir();

    try {
        $object = OcflObject::create(path: $dir, id: 'urn:test:inline')
            ->newVersion()
            ->addContents('note.txt', 'just a note')
            ->commit();

        expect($object->readContent('v1', 'note.txt'))->toBe('just a note');
    } finally {
        rmrf($dir);
    }
});

test('commit fails when writing an empty version with no changes', function (): void {
    $dir = makeWorkDir();

    try {
        $object = OcflObject::create(path: $dir, id: 'urn:test:emptyv');

        expect(fn () => $object->newVersion()->commit())
            ->toThrow(RuntimeException::class);
    } finally {
        rmrf($dir);
    }
});

test('honors the configured primary digest algorithm', function (): void {
    $dir = makeWorkDir();

    try {
        $object = OcflObject::create(
            path: $dir,
            id: 'urn:test:sha256',
            digestAlgorithm: DigestAlgorithm::Sha256,
        )
            ->newVersion()
            ->addContents('a.txt', 'content')
            ->commit();

        expect($object->inventory->digestAlgorithm)->toBe(DigestAlgorithm::Sha256)
            ->and(is_file($dir . '/inventory.json.sha256'))->toBeTrue()
            ->and(is_file($dir . '/v1/inventory.json.sha256'))->toBeTrue();
    } finally {
        rmrf($dir);
    }
});

test('renames a logical path without duplicating content', function (): void {
    $dir = makeWorkDir();
    $src = makeWorkDir();

    try {
        $a = writeSource($src, 'original.txt', 'xxx');

        $object = OcflObject::create(path: $dir, id: 'urn:test:rename')
            ->newVersion()
            ->addFile('original.txt', $a)
            ->commit()
            ->newVersion()
            ->renameFile('original.txt', 'renamed.txt')
            ->commit();

        expect($object->logicalPaths('v2'))->toBe(['renamed.txt'])
            ->and($object->resolveContentPath('v2', 'renamed.txt'))->toBe('v1/content/original.txt')
            ->and(is_file($dir . '/v2/content/renamed.txt'))->toBeFalse();
    } finally {
        rmrf($dir);
        rmrf($src);
    }
});
