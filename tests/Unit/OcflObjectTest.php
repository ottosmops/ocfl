<?php

declare(strict_types=1);

use Ottosmops\Ocfl\OcflObject;
use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\OcflException;

function objectFixture(string $name): string
{
    return __DIR__ . '/../fixtures/ocfl/1.1/good-objects/' . $name;
}

test('opens a single-version object root', function (): void {
    $object = OcflObject::open(objectFixture('minimal_one_version_one_file'));

    expect($object->id())->toBe('ark:123/abc')
        ->and($object->head())->toBe('v1')
        ->and($object->versionNames())->toBe(['v1']);
});

test('opens a multi-version object root', function (): void {
    $object = OcflObject::open(objectFixture('spec-ex-full'));

    expect($object->id())->toBe('ark:/12345/bcd987')
        ->and($object->head())->toBe('v3')
        ->and($object->versionNames())->toBe(['v1', 'v2', 'v3']);
});

test('rejects a directory that is not an OCFL object root with E003', function (): void {
    $tmp = sys_get_temp_dir() . '/ocfl-bogus-' . uniqid();
    mkdir($tmp, 0o755, true);

    try {
        try {
            OcflObject::open($tmp);
            throw new RuntimeException('expected OcflException');
        } catch (OcflException $e) {
            expect($e->errorCode)->toBe(ErrorCode::E003);
        }
    } finally {
        rmdir($tmp);
    }
});

test('rejects an object whose sidecar does not match the inventory with E060', function (): void {
    $tmp = sys_get_temp_dir() . '/ocfl-tampered-' . uniqid();
    mkdir($tmp, 0o755, true);

    try {
        // Start from a good fixture, but tamper with the inventory.
        $src = objectFixture('minimal_one_version_one_file');
        foreach (['0=ocfl_object_1.1', 'inventory.json', 'inventory.json.sha512'] as $file) {
            copy($src . '/' . $file, $tmp . '/' . $file);
        }
        file_put_contents(
            $tmp . '/inventory.json',
            (string) file_get_contents($tmp . '/inventory.json') . ' ',
        );

        try {
            OcflObject::open($tmp);
            throw new RuntimeException('expected OcflException');
        } catch (OcflException $e) {
            expect($e->errorCode)->toBe(ErrorCode::E060);
        }
    } finally {
        foreach (glob($tmp . '/*') ?: [] as $f) {
            is_file($f) && unlink($f);
        }
        rmdir($tmp);
    }
});

test('lists logical paths for a version', function (): void {
    $object = OcflObject::open(objectFixture('spec-ex-full'));

    expect($object->logicalPaths('v1'))->toContain('empty.txt', 'foo/bar.xml', 'image.tiff');
});

test('resolves a logical path to the concrete content path', function (): void {
    $object = OcflObject::open(objectFixture('spec-ex-full'));

    // v3 state still references v1's content for image.tiff (reinstated, same digest)
    $resolved = $object->resolveContentPath('v3', 'image.tiff');
    expect($resolved)->toBe('v1/content/image.tiff');

    // foo/bar.xml was modified in v2 — v3 still references the v2 content
    $resolvedBar = $object->resolveContentPath('v3', 'foo/bar.xml');
    expect($resolvedBar)->toBe('v2/content/foo/bar.xml');

    // empty2.txt is a dedup: same digest as v1/content/empty.txt
    $resolvedEmpty2 = $object->resolveContentPath('v3', 'empty2.txt');
    expect($resolvedEmpty2)->toBe('v1/content/empty.txt');
});

test('readContent returns the bytes at a given logical path', function (): void {
    $object = OcflObject::open(objectFixture('spec-ex-full'));

    $bytes = $object->readContent('v1', 'empty.txt');
    expect($bytes)->toBe('');

    $imageBytes = $object->readContent('v1', 'image.tiff');
    expect(strlen($imageBytes))->toBeGreaterThan(0);
});

test('throws when resolving a logical path absent from the version state', function (): void {
    $object = OcflObject::open(objectFixture('spec-ex-full'));

    expect(fn () => $object->resolveContentPath('v1', 'does_not_exist.txt'))
        ->toThrow(OutOfBoundsException::class);
});

test('throws when resolving against a non-existent version name', function (): void {
    $object = OcflObject::open(objectFixture('spec-ex-full'));

    expect(fn () => $object->resolveContentPath('v99', 'empty.txt'))
        ->toThrow(OutOfBoundsException::class);
});

test('checkout materialises head state by default', function (): void {
    $object = OcflObject::open(objectFixture('spec-ex-full'));
    $target = sys_get_temp_dir() . '/ocfl-checkout-' . uniqid();

    try {
        $object->checkout($target);

        expect(is_file($target . '/foo/bar.xml'))->toBeTrue()
            ->and(is_file($target . '/empty2.txt'))->toBeTrue()
            // image.tiff was reinstated in v3 (via dedup from v1 content)
            ->and(is_file($target . '/image.tiff'))->toBeTrue()
            // empty.txt was deleted in v3
            ->and(is_file($target . '/empty.txt'))->toBeFalse();
    } finally {
        recursiveRemove($target);
    }
});

test('checkout materialises an explicit earlier version', function (): void {
    $object = OcflObject::open(objectFixture('spec-ex-full'));
    $target = sys_get_temp_dir() . '/ocfl-checkout-v1-' . uniqid();

    try {
        $object->checkout($target, 'v1');

        expect(is_file($target . '/image.tiff'))->toBeTrue()
            ->and(is_file($target . '/empty.txt'))->toBeTrue();
    } finally {
        recursiveRemove($target);
    }
});

function recursiveRemove(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iter as $path) {
        /** @var SplFileInfo $path */
        $path->isDir() ? rmdir((string) $path) : unlink((string) $path);
    }

    rmdir($dir);
}
