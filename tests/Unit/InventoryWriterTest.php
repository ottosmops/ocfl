<?php

declare(strict_types=1);

use Ottosmops\Ocfl\Inventory\InventoryReader;
use Ottosmops\Ocfl\Inventory\InventoryWriter;

function goodFixtureForWriter(string $name): string
{
    return __DIR__ . '/../fixtures/ocfl/1.1/good-objects/' . $name . '/inventory.json';
}

test('round-trips a minimal fixture without data loss', function (): void {
    $original = (string) file_get_contents(goodFixtureForWriter('minimal_one_version_one_file'));
    $inv = InventoryReader::fromString($original);
    $rewritten = InventoryWriter::toJson($inv);

    // Re-parse rewritten and compare all fields.
    $reparsed = InventoryReader::fromString($rewritten);

    expect($reparsed->id)->toBe($inv->id)
        ->and($reparsed->type)->toBe($inv->type)
        ->and($reparsed->digestAlgorithm)->toBe($inv->digestAlgorithm)
        ->and($reparsed->head)->toBe($inv->head)
        ->and($reparsed->contentDirectory)->toBe($inv->contentDirectory)
        ->and($reparsed->manifest)->toBe($inv->manifest)
        ->and(array_keys($reparsed->versions))->toBe(array_keys($inv->versions));
});

test('produces sorted top-level keys for byte-stable output', function (): void {
    $inv = InventoryReader::fromFilesystem(new Ottosmops\Ocfl\Filesystem\LocalFilesystem(), goodFixtureForWriter('minimal_one_version_one_file'));
    $json = InventoryWriter::toJson($inv);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    $keys = array_keys($decoded);

    $sorted = $keys;
    sort($sorted);

    expect($keys)->toBe($sorted);
});

test('produces sorted manifest and state digest keys', function (): void {
    $inv = InventoryReader::fromFilesystem(new Ottosmops\Ocfl\Filesystem\LocalFilesystem(), goodFixtureForWriter('spec-ex-full'));
    $json = InventoryWriter::toJson($inv);

    /** @var array{manifest: array<string, mixed>, versions: array<string, array{state: array<string, mixed>}>} $decoded */
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    $manifestKeys = array_keys($decoded['manifest']);
    $sorted = $manifestKeys;
    sort($sorted);
    expect($manifestKeys)->toBe($sorted);

    foreach ($decoded['versions'] as $version) {
        $stateKeys = array_keys($version['state']);
        $sortedState = $stateKeys;
        sort($sortedState);
        expect($stateKeys)->toBe($sortedState);
    }
});

test('omits contentDirectory when it equals the default', function (): void {
    $inv = InventoryReader::fromFilesystem(new Ottosmops\Ocfl\Filesystem\LocalFilesystem(), goodFixtureForWriter('minimal_one_version_one_file'));
    $json = InventoryWriter::toJson($inv);

    expect($json)->not->toContain('"contentDirectory"');
});

test('emits contentDirectory when it differs from default', function (): void {
    $inv = InventoryReader::fromFilesystem(new Ottosmops\Ocfl\Filesystem\LocalFilesystem(), goodFixtureForWriter('minimal_content_dir_called_stuff'));
    $json = InventoryWriter::toJson($inv);

    expect($json)->toContain('"contentDirectory": "stuff"');
});

test('omits user when null', function (): void {
    $inv = InventoryReader::fromFilesystem(new Ottosmops\Ocfl\Filesystem\LocalFilesystem(), goodFixtureForWriter('minimal_no_content'));
    $json = InventoryWriter::toJson($inv);

    // whether or not this fixture has user, re-read and verify consistent absence/presence
    /** @var array{versions: array<string, array{user?: mixed}>} $decoded */
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    foreach ($decoded['versions'] as $versionName => $versionBlock) {
        $origBlock = $inv->versions[$versionName];
        expect(array_key_exists('user', $versionBlock))->toBe($origBlock->user !== null);
    }
});

test('formats created timestamps as RFC3339 UTC', function (): void {
    $inv = InventoryReader::fromFilesystem(new Ottosmops\Ocfl\Filesystem\LocalFilesystem(), goodFixtureForWriter('minimal_one_version_one_file'));
    $json = InventoryWriter::toJson($inv);

    expect($json)->toContain('"created": "2019-01-01T02:03:04Z"');
});

test('output has trailing newline', function (): void {
    $inv = InventoryReader::fromFilesystem(new Ottosmops\Ocfl\Filesystem\LocalFilesystem(), goodFixtureForWriter('minimal_one_version_one_file'));
    $json = InventoryWriter::toJson($inv);

    expect(substr($json, -1))->toBe("\n");
});
