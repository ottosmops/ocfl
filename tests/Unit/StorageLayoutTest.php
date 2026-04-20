<?php

declare(strict_types=1);

use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Storage\FlatDirectStorageLayout;
use Ottosmops\Ocfl\Storage\HashedNTupleStorageLayout;

test('flat direct layout uses the identifier verbatim as a single segment', function (): void {
    $layout = new FlatDirectStorageLayout();

    expect($layout->resolveObjectPath('object-1'))->toBe('object-1')
        ->and($layout->resolveObjectPath('abc123'))->toBe('abc123');
});

test('flat direct layout rejects identifiers containing path separators', function (): void {
    $layout = new FlatDirectStorageLayout();

    expect(fn () => $layout->resolveObjectPath('bad/id'))->toThrow(InvalidArgumentException::class);
});

test('flat direct layout rejects empty or dot identifiers', function (): void {
    $layout = new FlatDirectStorageLayout();

    expect(fn () => $layout->resolveObjectPath(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $layout->resolveObjectPath('.'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $layout->resolveObjectPath('..'))->toThrow(InvalidArgumentException::class);
});

test('flat direct layout reports its spec extension name', function (): void {
    expect((new FlatDirectStorageLayout())->extensionName())
        ->toBe('0002-flat-direct-storage-layout');
});

test('hashed n-tuple layout splits a hash into fixed-size tuples', function (): void {
    $layout = new HashedNTupleStorageLayout(
        digestAlgorithm: DigestAlgorithm::Sha256,
        tupleSize: 3,
        numberOfTuples: 3,
        shortObjectRoot: false,
    );

    $hash = hash('sha256', 'my-object-id');
    $expected = substr($hash, 0, 3) . '/' . substr($hash, 3, 3) . '/' . substr($hash, 6, 3) . '/' . $hash;

    expect($layout->resolveObjectPath('my-object-id'))->toBe($expected);
});

test('hashed n-tuple layout with shortObjectRoot=true omits the hash leaf', function (): void {
    $layout = new HashedNTupleStorageLayout(
        digestAlgorithm: DigestAlgorithm::Sha256,
        tupleSize: 3,
        numberOfTuples: 3,
        shortObjectRoot: true,
    );

    $hash = hash('sha256', 'my-object-id');
    $leaf = substr($hash, 9);
    $expected = substr($hash, 0, 3) . '/' . substr($hash, 3, 3) . '/' . substr($hash, 6, 3) . '/' . $leaf;

    expect($layout->resolveObjectPath('my-object-id'))->toBe($expected);
});

test('hashed n-tuple layout is deterministic across calls', function (): void {
    $layout = new HashedNTupleStorageLayout();

    expect($layout->resolveObjectPath('same-id'))->toBe($layout->resolveObjectPath('same-id'))
        ->and($layout->resolveObjectPath('a'))->not->toBe($layout->resolveObjectPath('b'));
});

test('hashed n-tuple layout rejects configurations with insufficient hash material', function (): void {
    expect(fn () => new HashedNTupleStorageLayout(
        digestAlgorithm: DigestAlgorithm::Sha256,
        tupleSize: 32,
        numberOfTuples: 3, // 96 chars needed, sha256 produces 64
    ))->toThrow(InvalidArgumentException::class);
});

test('hashed n-tuple layout reports its spec extension name', function (): void {
    expect((new HashedNTupleStorageLayout())->extensionName())
        ->toBe('0004-hashed-n-tuple-storage-layout');
});
