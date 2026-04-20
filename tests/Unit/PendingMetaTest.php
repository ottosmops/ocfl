<?php

declare(strict_types=1);

use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Filesystem\LocalFilesystem;
use Ottosmops\Ocfl\Internal\PendingMeta;

function pendingDir(): string
{
    $p = sys_get_temp_dir() . '/ocfl-pending-' . uniqid();
    mkdir($p, 0o755, true);

    return $p;
}

function pendingCleanup(string $p): void
{
    if (! is_dir($p)) {
        return;
    }
    // Include dotfiles — PendingMeta::FILENAME starts with a dot.
    foreach (new FilesystemIterator($p, FilesystemIterator::SKIP_DOTS) as $entry) {
        /** @var SplFileInfo $entry */
        if ($entry->isFile()) {
            unlink($entry->getPathname());
        }
    }
    rmdir($p);
}

test('read returns null when no pending file exists', function (): void {
    $dir = pendingDir();

    try {
        expect(PendingMeta::read(new LocalFilesystem(), $dir))->toBeNull();
    } finally {
        pendingCleanup($dir);
    }
});

test('read returns null on malformed JSON', function (): void {
    $dir = pendingDir();

    try {
        file_put_contents($dir . '/' . PendingMeta::FILENAME, '{ not valid');

        expect(PendingMeta::read(new LocalFilesystem(), $dir))->toBeNull();
    } finally {
        pendingCleanup($dir);
    }
});

test('read falls back to sha512 when digestAlgorithm is absent', function (): void {
    $dir = pendingDir();

    try {
        file_put_contents(
            $dir . '/' . PendingMeta::FILENAME,
            (string) json_encode(['id' => 'urn:test:x']),
        );

        $meta = PendingMeta::read(new LocalFilesystem(), $dir);

        expect($meta)->not->toBeNull();
        assert($meta !== null);
        expect($meta->id)->toBe('urn:test:x')
            ->and($meta->digestAlgorithm)->toBe(DigestAlgorithm::Sha512);
    } finally {
        pendingCleanup($dir);
    }
});

test('read falls back to sha512 when digestAlgorithm is unrecognised', function (): void {
    $dir = pendingDir();

    try {
        file_put_contents(
            $dir . '/' . PendingMeta::FILENAME,
            (string) json_encode(['id' => 'urn:test:x', 'digestAlgorithm' => 'bogus']),
        );

        $meta = PendingMeta::read(new LocalFilesystem(), $dir);

        expect($meta?->digestAlgorithm)->toBe(DigestAlgorithm::Sha512);
    } finally {
        pendingCleanup($dir);
    }
});

test('write + read round-trips sha256 correctly', function (): void {
    $dir = pendingDir();

    try {
        $fs = new LocalFilesystem();
        PendingMeta::write($fs, $dir, new PendingMeta('urn:test:rt', DigestAlgorithm::Sha256));

        $round = PendingMeta::read($fs, $dir);
        expect($round?->id)->toBe('urn:test:rt')
            ->and($round?->digestAlgorithm)->toBe(DigestAlgorithm::Sha256);
    } finally {
        pendingCleanup($dir);
    }
});

test('discard removes the pending file and is a no-op when absent', function (): void {
    $dir = pendingDir();

    try {
        $fs = new LocalFilesystem();
        PendingMeta::write($fs, $dir, new PendingMeta('x', DigestAlgorithm::Sha512));

        PendingMeta::discard($fs, $dir);
        expect(is_file($dir . '/' . PendingMeta::FILENAME))->toBeFalse();

        PendingMeta::discard($fs, $dir); // no-op
    } finally {
        pendingCleanup($dir);
    }
});
