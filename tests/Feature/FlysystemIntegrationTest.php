<?php

declare(strict_types=1);

use League\Flysystem\Filesystem as LeagueFilesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Ottosmops\Ocfl\Filesystem\FlysystemFilesystem;
use Ottosmops\Ocfl\OcflObject;
use Ottosmops\Ocfl\Storage\FlatDirectStorageLayout;
use Ottosmops\Ocfl\Storage\StorageRoot;

test('creates, commits, and reopens an object entirely through Flysystem', function (): void {
    $adapter = new InMemoryFilesystemAdapter();
    $league = new LeagueFilesystem($adapter);
    $fs = new FlysystemFilesystem($league);

    $root = StorageRoot::create('/ocfl-root', new FlatDirectStorageLayout(), $fs);

    $object = $root->createObject('my-object')
        ->newVersion()
        ->addContents('hello.txt', 'Hello, Flysystem!')
        ->withMessage('Initial import via Flysystem')
        ->withUser('Alice', 'mailto:alice@example.com')
        ->commit();

    expect($object->head())->toBe('v1')
        ->and($object->readContent('v1', 'hello.txt'))->toBe('Hello, Flysystem!')
        ->and($league->fileExists('ocfl-root/my-object/inventory.json'))->toBeTrue()
        ->and($league->fileExists('ocfl-root/my-object/v1/content/hello.txt'))->toBeTrue();

    $reopened = StorageRoot::open('/ocfl-root', $fs);

    expect($reopened->listObjects())->toBe(['my-object']);
    $retrieved = $reopened->getObject('my-object');
    expect($retrieved->readContent('v1', 'hello.txt'))->toBe('Hello, Flysystem!');
});

test('supports multi-version commits with dedup on a Flysystem backend', function (): void {
    $league = new LeagueFilesystem(new InMemoryFilesystemAdapter());
    $fs = new FlysystemFilesystem($league);

    $object = OcflObject::create('/obj', 'urn:test:multi', fs: $fs)
        ->newVersion()
        ->addContents('a.txt', 'shared bytes')
        ->commit()
        ->newVersion()
        ->addContents('b.txt', 'shared bytes') // identical digest → dedup
        ->commit();

    expect($object->head())->toBe('v2')
        // b.txt should resolve back to v1's content path.
        ->and($object->resolveContentPath('v2', 'b.txt'))->toBe('v1/content/a.txt')
        ->and($league->fileExists('obj/v2/content/b.txt'))->toBeFalse()
        ->and($league->fileExists('obj/v1/content/a.txt'))->toBeTrue();
});

test('validates an object stored in Flysystem', function (): void {
    $league = new LeagueFilesystem(new InMemoryFilesystemAdapter());
    $fs = new FlysystemFilesystem($league);

    $object = OcflObject::create('/obj', 'urn:test:validate', fs: $fs)
        ->newVersion()
        ->addContents('hello.txt', 'hi')
        ->withMessage('add hello')
        ->withUser('Alice', 'mailto:alice@example.com')
        ->commit();

    $report = $object->validate();
    expect($report->isValid())->toBeTrue();
});
