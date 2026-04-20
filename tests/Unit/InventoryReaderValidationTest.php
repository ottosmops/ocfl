<?php

declare(strict_types=1);

use Ottosmops\Ocfl\Inventory\InventoryReader;
use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\OcflException;

function badFixture(string $name): string
{
    return __DIR__ . '/../fixtures/ocfl/1.1/bad-objects/' . $name . '/inventory.json';
}

function expectOcflErrorCode(callable $fn, ErrorCode $expected): void
{
    try {
        $fn();
    } catch (OcflException $e) {
        expect($e->errorCode)->toBe($expected);

        return;
    }

    throw new RuntimeException("Expected OcflException with code {$expected->value}, none thrown");
}

test('rejects invalid JSON with E033', function (): void {
    expectOcflErrorCode(
        fn () => InventoryReader::fromString('{ not valid json'),
        ErrorCode::E033,
    );
});

test('rejects non-object JSON root with E033', function (): void {
    expectOcflErrorCode(
        fn () => InventoryReader::fromString('"just a string"'),
        ErrorCode::E033,
    );
});

test('rejects missing id with E036', function (): void {
    expectOcflErrorCode(
        fn () => InventoryReader::fromFilesystem(new Ottosmops\Ocfl\Filesystem\LocalFilesystem(), badFixture('E036_no_id')),
        ErrorCode::E036,
    );
});

test('rejects missing head with E036', function (): void {
    expectOcflErrorCode(
        fn () => InventoryReader::fromFilesystem(new Ottosmops\Ocfl\Filesystem\LocalFilesystem(), badFixture('E036_no_head')),
        ErrorCode::E036,
    );
});

test('rejects missing manifest with E041', function (): void {
    expectOcflErrorCode(
        fn () => InventoryReader::fromFilesystem(new Ottosmops\Ocfl\Filesystem\LocalFilesystem(), badFixture('E041_no_manifest')),
        ErrorCode::E041,
    );
});

test('rejects wrong digest algorithm with E025', function (): void {
    expectOcflErrorCode(
        fn () => InventoryReader::fromFilesystem(new Ottosmops\Ocfl\Filesystem\LocalFilesystem(), badFixture('E025_wrong_digest_algorithm')),
        ErrorCode::E025,
    );
});

test('rejects empty versions object with E036', function (): void {
    expectOcflErrorCode(
        fn () => InventoryReader::fromFilesystem(new Ottosmops\Ocfl\Filesystem\LocalFilesystem(), badFixture('E008_E036_no_versions_no_head')),
        ErrorCode::E036,
    );
});

test('rejects non-readable file with E033', function (): void {
    expectOcflErrorCode(
        fn () => InventoryReader::fromFilesystem(new Ottosmops\Ocfl\Filesystem\LocalFilesystem(), '/tmp/no-such-inventory-' . uniqid() . '.json'),
        ErrorCode::E033,
    );
});

test('exception message is prefixed with spec error code', function (): void {
    try {
        InventoryReader::fromString('null');
    } catch (OcflException $e) {
        expect($e->getMessage())->toStartWith('[E033]');

        return;
    }

    throw new RuntimeException('expected OcflException');
});
