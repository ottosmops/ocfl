<?php

declare(strict_types=1);

use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Inventory\Inventory;
use Ottosmops\Ocfl\Inventory\InventoryReader;
use Ottosmops\Ocfl\Inventory\User;

function goodFixture(string $name): string
{
    return __DIR__ . '/../fixtures/ocfl/1.1/good-objects/' . $name . '/inventory.json';
}

test('parses a minimal one-version inventory', function (): void {
    $inv = InventoryReader::fromFile(goodFixture('minimal_one_version_one_file'));

    expect($inv->id)->toBe('ark:123/abc')
        ->and($inv->type)->toBe(Inventory::TYPE)
        ->and($inv->digestAlgorithm)->toBe(DigestAlgorithm::Sha512)
        ->and($inv->head)->toBe('v1')
        ->and($inv->contentDirectory)->toBe('content')
        ->and($inv->versions)->toHaveKey('v1')
        ->and($inv->versionSequence())->toBe(['v1']);
});

test('populates manifest as digest → content paths map', function (): void {
    $inv = InventoryReader::fromFile(goodFixture('minimal_one_version_one_file'));

    $digest = '43a43fe8a8a082d3b5343dfaf2fd0c8b8e370675b1f376e92e9994612c33ea255b11298269d72f797399ebb94edeefe53df243643676548f584fb8603ca53a0f';
    expect($inv->manifest[$digest] ?? null)->toBe(['v1/content/a_file.txt']);
});

test('parses version metadata including RFC3339 created timestamp', function (): void {
    $inv = InventoryReader::fromFile(goodFixture('minimal_one_version_one_file'));
    $v1 = $inv->versions['v1'];

    expect($v1->created->format(DATE_RFC3339))->toBe('2019-01-01T02:03:04+00:00')
        ->and($v1->message)->toBe('An version with one file')
        ->and($v1->user)->toBeInstanceOf(User::class)
        ->and($v1->user?->name)->toBe('A Person')
        ->and($v1->user?->address)->toBe('mailto:a_person@example.org');
});

test('populates version state as digest → logical paths map', function (): void {
    $inv = InventoryReader::fromFile(goodFixture('minimal_one_version_one_file'));
    $v1 = $inv->versions['v1'];

    $digest = '43a43fe8a8a082d3b5343dfaf2fd0c8b8e370675b1f376e92e9994612c33ea255b11298269d72f797399ebb94edeefe53df243643676548f584fb8603ca53a0f';
    expect($v1->state[$digest] ?? null)->toBe(['a_file.txt']);
});

test('respects custom contentDirectory when present', function (): void {
    $inv = InventoryReader::fromFile(goodFixture('minimal_content_dir_called_stuff'));

    expect($inv->contentDirectory)->toBe('stuff');
});

test('parses a multi-version inventory with fixity block', function (): void {
    $inv = InventoryReader::fromFile(goodFixture('spec-ex-full'));

    expect($inv->head)->toBe('v3')
        ->and($inv->versionSequence())->toBe(['v1', 'v2', 'v3'])
        ->and($inv->fixity)->toHaveKey('md5')
        ->and($inv->fixity)->toHaveKey('sha1');
});

test('accepts uppercase digests and normalises to lowercase', function (): void {
    // good-objects/minimal_uppercase_digests is valid per spec §7.2 (digests compared case-insensitively).
    $inv = InventoryReader::fromFile(goodFixture('minimal_uppercase_digests'));

    foreach (array_keys($inv->manifest) as $digest) {
        expect($digest)->toMatch('/^[a-f0-9]+$/');
    }
});

test('parses sha256 as primary digest algorithm', function (): void {
    // sha256 is permitted but warned (W004) — good for testing enum mapping.
    $path = __DIR__ . '/../fixtures/ocfl/1.1/warn-objects/W004_uses_sha256/inventory.json';
    $inv = InventoryReader::fromFile($path);

    expect($inv->digestAlgorithm)->toBe(DigestAlgorithm::Sha256);
});

test('can parse inventory from a JSON string directly', function (): void {
    $json = (string) file_get_contents(goodFixture('minimal_one_version_one_file'));
    $inv = InventoryReader::fromString($json);

    expect($inv->id)->toBe('ark:123/abc');
});
