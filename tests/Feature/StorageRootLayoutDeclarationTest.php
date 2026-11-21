<?php

declare(strict_types=1);

use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Namaste;
use Ottosmops\Ocfl\NamasteType;
use Ottosmops\Ocfl\Storage\FlatDirectStorageLayout;
use Ottosmops\Ocfl\Storage\HashedNTupleStorageLayout;
use Ottosmops\Ocfl\Storage\StorageLayout;
use Ottosmops\Ocfl\Storage\StorageRoot;
use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\OcflException;

function layoutDeclDir(): string
{
    $path = sys_get_temp_dir() . '/ocfl-layout-decl-' . uniqid();
    mkdir($path, 0o755, true);

    return $path;
}

function layoutDeclCleanup(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iter as $entry) {
        /** @var SplFileInfo $entry */
        $entry->isDir() ? rmdir((string) $entry) : unlink((string) $entry);
    }
    rmdir($dir);
}

/**
 * @return array<string, mixed>
 */
function layoutDeclJson(string $file): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

/**
 * Build a storage root the way v1.0.0 did: the layout configuration
 * (with extensionName) written straight into ocfl_layout.json.
 */
function layoutDeclLegacyRoot(string $dir, StorageLayout $layout): void
{
    Namaste::write(Namaste::local(), $dir, NamasteType::StorageRoot);
    file_put_contents(
        $dir . '/ocfl_layout.json',
        json_encode($layout->configuration(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
}

dataset('layouts', [
    '0002 flat-direct' => fn (): StorageLayout => new FlatDirectStorageLayout(),
    '0004 hashed-n-tuple (defaults)' => fn (): StorageLayout => new HashedNTupleStorageLayout(),
    '0004 hashed-n-tuple (sha512, 2x4, short)' => fn (): StorageLayout => new HashedNTupleStorageLayout(
        digestAlgorithm: DigestAlgorithm::Sha512,
        tupleSize: 2,
        numberOfTuples: 4,
        shortObjectRoot: true,
    ),
]);

it('create writes a spec-conformant ocfl_layout.json (§4.1)', function (StorageLayout $layout): void {
    $dir = layoutDeclDir();

    try {
        StorageRoot::create($dir, $layout);
        $declaration = layoutDeclJson($dir . '/ocfl_layout.json');

        expect(array_keys($declaration))->toEqualCanonicalizing(['extension', 'description'])
            ->and($declaration['extension'])->toBe($layout->extensionName())
            ->and(is_string($declaration['description']) && $declaration['description'] !== '')->toBeTrue();
    } finally {
        layoutDeclCleanup($dir);
    }
})->with('layouts');

it('create writes the layout parameters to extensions/<name>/config.json', function (StorageLayout $layout): void {
    $dir = layoutDeclDir();

    try {
        StorageRoot::create($dir, $layout);
        $configFile = $dir . '/extensions/' . $layout->extensionName() . '/config.json';

        expect(is_file($configFile))->toBeTrue()
            ->and(layoutDeclJson($configFile))->toBe($layout->configuration());
    } finally {
        layoutDeclCleanup($dir);
    }
})->with('layouts');

it('a new root reopens with the same layout and resolves objects', function (StorageLayout $layout): void {
    $dir = layoutDeclDir();

    try {
        StorageRoot::create($dir, $layout)
            ->createObject('urn:example:obj-1')
            ->newVersion()
            ->addContents('a.txt', 'alpha')
            ->commit();

        $reopened = StorageRoot::open($dir);

        expect($reopened->layout()->configuration())->toBe($layout->configuration())
            ->and($reopened->hasLegacyLayoutDeclaration())->toBeFalse()
            ->and($reopened->hasObject('urn:example:obj-1'))->toBeTrue()
            ->and($reopened->getObject('urn:example:obj-1')->readContent('v1', 'a.txt'))->toBe('alpha')
            ->and($reopened->listObjects())->toBe(['urn:example:obj-1']);
    } finally {
        layoutDeclCleanup($dir);
    }
})->with('layouts');

it('a v1.0.0-style root still opens and resolves object paths', function (StorageLayout $layout): void {
    $dir = layoutDeclDir();

    try {
        layoutDeclLegacyRoot($dir, $layout);
        $before = (string) file_get_contents($dir . '/ocfl_layout.json');

        $root = StorageRoot::open($dir);
        $root->createObject('urn:example:legacy')
            ->newVersion()
            ->addContents('a.txt', 'legacy')
            ->commit();

        $reopened = StorageRoot::open($dir);

        expect($reopened->layout()->configuration())->toBe($layout->configuration())
            ->and($reopened->hasLegacyLayoutDeclaration())->toBeTrue()
            ->and(is_dir($dir . '/' . $layout->resolveObjectPath('urn:example:legacy')))->toBeTrue()
            ->and($reopened->getObject('urn:example:legacy')->readContent('v1', 'a.txt'))->toBe('legacy')
            // Opening must not rewrite the declaration behind the caller's back.
            ->and((string) file_get_contents($dir . '/ocfl_layout.json'))->toBe($before)
            ->and(is_dir($dir . '/extensions'))->toBeFalse();
    } finally {
        layoutDeclCleanup($dir);
    }
})->with('layouts');

it('upgradeLayoutDeclaration converts a v1.0.0-style root in place', function (StorageLayout $layout): void {
    $dir = layoutDeclDir();

    try {
        layoutDeclLegacyRoot($dir, $layout);
        $root = StorageRoot::open($dir);
        $root->createObject('obj')->newVersion()->addContents('x.txt', 'x')->commit();

        $root->upgradeLayoutDeclaration();

        $declaration = layoutDeclJson($dir . '/ocfl_layout.json');
        $config = layoutDeclJson($dir . '/extensions/' . $layout->extensionName() . '/config.json');
        $reopened = StorageRoot::open($dir);

        expect(array_keys($declaration))->toEqualCanonicalizing(['extension', 'description'])
            ->and($declaration['extension'])->toBe($layout->extensionName())
            ->and($config)->toBe($layout->configuration())
            ->and($root->hasLegacyLayoutDeclaration())->toBeFalse()
            ->and($reopened->hasLegacyLayoutDeclaration())->toBeFalse()
            ->and($reopened->getObject('obj')->readContent('v1', 'x.txt'))->toBe('x');
    } finally {
        layoutDeclCleanup($dir);
    }
})->with('layouts');

test('upgradeLayoutDeclaration is a no-op on a conformant root', function (): void {
    $dir = layoutDeclDir();

    try {
        $root = StorageRoot::create($dir, new HashedNTupleStorageLayout());
        $before = (string) file_get_contents($dir . '/ocfl_layout.json');

        $root->upgradeLayoutDeclaration();

        expect((string) file_get_contents($dir . '/ocfl_layout.json'))->toBe($before);
    } finally {
        layoutDeclCleanup($dir);
    }
});

test('a conformant 0004 root without config.json opens with the extension defaults', function (): void {
    $dir = layoutDeclDir();

    try {
        Namaste::write(Namaste::local(), $dir, NamasteType::StorageRoot);
        file_put_contents($dir . '/ocfl_layout.json', (string) json_encode([
            'extension' => '0004-hashed-n-tuple-storage-layout',
            'description' => 'written by another implementation',
        ]));

        $root = StorageRoot::open($dir);

        expect($root->layout()->configuration())->toBe((new HashedNTupleStorageLayout())->configuration())
            ->and($root->hasLegacyLayoutDeclaration())->toBeFalse();
    } finally {
        layoutDeclCleanup($dir);
    }
});

test('open rejects config.json naming a different extension with E070', function (): void {
    $dir = layoutDeclDir();

    try {
        StorageRoot::create($dir, new HashedNTupleStorageLayout());
        file_put_contents(
            $dir . '/extensions/0004-hashed-n-tuple-storage-layout/config.json',
            (string) json_encode(['extensionName' => '0002-flat-direct-storage-layout']),
        );

        try {
            StorageRoot::open($dir);
            throw new RuntimeException('expected OcflException');
        } catch (OcflException $e) {
            expect($e->errorCode)->toBe(ErrorCode::E070);
        }
    } finally {
        layoutDeclCleanup($dir);
    }
});

test('open rejects a malformed extension config.json with E070', function (): void {
    $dir = layoutDeclDir();

    try {
        StorageRoot::create($dir, new HashedNTupleStorageLayout());
        file_put_contents($dir . '/extensions/0004-hashed-n-tuple-storage-layout/config.json', '{nope');

        try {
            StorageRoot::open($dir);
            throw new RuntimeException('expected OcflException');
        } catch (OcflException $e) {
            expect($e->errorCode)->toBe(ErrorCode::E070);
        }
    } finally {
        layoutDeclCleanup($dir);
    }
});

test('open rejects an unsupported extension in the conformant form with E070', function (): void {
    $dir = layoutDeclDir();

    try {
        Namaste::write(Namaste::local(), $dir, NamasteType::StorageRoot);
        file_put_contents($dir . '/ocfl_layout.json', (string) json_encode([
            'extension' => '../../etc',
            'description' => 'path traversal attempt',
        ]));

        try {
            StorageRoot::open($dir);
            throw new RuntimeException('expected OcflException');
        } catch (OcflException $e) {
            expect($e->errorCode)->toBe(ErrorCode::E070);
        }
    } finally {
        layoutDeclCleanup($dir);
    }
});
