<?php

declare(strict_types=1);

use Ottosmops\Ocfl\Inventory\InventoryReader;
use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\OcflException;

function expectInventoryCode(string $json, ErrorCode $expected): void
{
    try {
        InventoryReader::fromString($json);
    } catch (OcflException $e) {
        expect($e->errorCode)->toBe($expected);

        return;
    }

    throw new RuntimeException("expected {$expected->value}");
}

test('rejects non-string type with E038', function (): void {
    expectInventoryCode((string) json_encode([
        'id' => 'x',
        'type' => ['wrong'],
        'digestAlgorithm' => 'sha512',
        'head' => 'v1',
        'manifest' => [],
        'versions' => ['v1' => ['created' => '2020-01-01T00:00:00Z', 'state' => []]],
    ]), ErrorCode::E038);
});

test('rejects non-string digestAlgorithm with E025', function (): void {
    expectInventoryCode((string) json_encode([
        'id' => 'x',
        'type' => 'https://ocfl.io/1.1/spec/#inventory',
        'digestAlgorithm' => ['sha512'],
        'head' => 'v1',
        'manifest' => [],
        'versions' => ['v1' => ['created' => '2020-01-01T00:00:00Z', 'state' => []]],
    ]), ErrorCode::E025);
});

test('rejects manifest not being a JSON object with E034', function (): void {
    expectInventoryCode((string) json_encode([
        'id' => 'x',
        'type' => 'https://ocfl.io/1.1/spec/#inventory',
        'digestAlgorithm' => 'sha512',
        'head' => 'v1',
        'manifest' => 'not an object',
        'versions' => ['v1' => ['created' => '2020-01-01T00:00:00Z', 'state' => []]],
    ]), ErrorCode::E034);
});

test('rejects manifest entries with non-string path with E034', function (): void {
    expectInventoryCode((string) json_encode([
        'id' => 'x',
        'type' => 'https://ocfl.io/1.1/spec/#inventory',
        'digestAlgorithm' => 'sha512',
        'head' => 'v1',
        'manifest' => ['abc' => [42]],
        'versions' => ['v1' => ['created' => '2020-01-01T00:00:00Z', 'state' => []]],
    ]), ErrorCode::E034);
});

test('rejects non-string created with E049', function (): void {
    expectInventoryCode((string) json_encode([
        'id' => 'x',
        'type' => 'https://ocfl.io/1.1/spec/#inventory',
        'digestAlgorithm' => 'sha512',
        'head' => 'v1',
        'manifest' => [],
        'versions' => ['v1' => ['created' => 123, 'state' => []]],
    ]), ErrorCode::E049);
});

test('rejects user that is not an object with E054', function (): void {
    expectInventoryCode((string) json_encode([
        'id' => 'x',
        'type' => 'https://ocfl.io/1.1/spec/#inventory',
        'digestAlgorithm' => 'sha512',
        'head' => 'v1',
        'manifest' => [],
        'versions' => ['v1' => [
            'created' => '2020-01-01T00:00:00Z',
            'state' => [],
            'user' => 'just a string',
        ]],
    ]), ErrorCode::E054);
});

test('rejects user.name that is empty or missing with E054', function (): void {
    expectInventoryCode((string) json_encode([
        'id' => 'x',
        'type' => 'https://ocfl.io/1.1/spec/#inventory',
        'digestAlgorithm' => 'sha512',
        'head' => 'v1',
        'manifest' => [],
        'versions' => ['v1' => [
            'created' => '2020-01-01T00:00:00Z',
            'state' => [],
            'user' => ['address' => 'mailto:x@y.z'],
        ]],
    ]), ErrorCode::E054);
});

test('rejects fixity block that is not an object with E034', function (): void {
    expectInventoryCode((string) json_encode([
        'id' => 'x',
        'type' => 'https://ocfl.io/1.1/spec/#inventory',
        'digestAlgorithm' => 'sha512',
        'head' => 'v1',
        'manifest' => [],
        'versions' => ['v1' => ['created' => '2020-01-01T00:00:00Z', 'state' => []]],
        'fixity' => 'bogus',
    ]), ErrorCode::E034);
});

test('rejects version block missing state with E048', function (): void {
    expectInventoryCode((string) json_encode([
        'id' => 'x',
        'type' => 'https://ocfl.io/1.1/spec/#inventory',
        'digestAlgorithm' => 'sha512',
        'head' => 'v1',
        'manifest' => [],
        'versions' => ['v1' => ['created' => '2020-01-01T00:00:00Z']],
    ]), ErrorCode::E048);
});
