<?php

declare(strict_types=1);

use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Filesystem\LocalFilesystem;

function fsWorkDir(): string
{
    $path = sys_get_temp_dir() . '/ocfl-localfs-' . uniqid();
    mkdir($path, 0o755, true);

    return $path;
}

function fsCleanup(string $dir): void
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

test('read returns file contents', function (): void {
    $dir = fsWorkDir();

    try {
        file_put_contents($dir . '/a.txt', 'hello');
        expect((new LocalFilesystem())->read($dir . '/a.txt'))->toBe('hello');
    } finally {
        fsCleanup($dir);
    }
});

test('read throws when file does not exist', function (): void {
    expect(fn () => (new LocalFilesystem())->read('/tmp/missing-' . uniqid()))
        ->toThrow(RuntimeException::class);
});

test('write creates intermediate directories', function (): void {
    $dir = fsWorkDir();

    try {
        (new LocalFilesystem())->write($dir . '/nested/deep/file.txt', 'bytes');

        expect(is_file($dir . '/nested/deep/file.txt'))->toBeTrue()
            ->and(file_get_contents($dir . '/nested/deep/file.txt'))->toBe('bytes');
    } finally {
        fsCleanup($dir);
    }
});

test('copy creates parent directories for the destination', function (): void {
    $dir = fsWorkDir();

    try {
        file_put_contents($dir . '/src.txt', 'x');
        (new LocalFilesystem())->copy($dir . '/src.txt', $dir . '/nested/dst.txt');

        expect(is_file($dir . '/nested/dst.txt'))->toBeTrue();
    } finally {
        fsCleanup($dir);
    }
});

test('copy throws on missing source', function (): void {
    $dir = fsWorkDir();

    try {
        expect(fn () => (new LocalFilesystem())->copy($dir . '/missing', $dir . '/dst'))
            ->toThrow(RuntimeException::class);
    } finally {
        fsCleanup($dir);
    }
});

test('move relocates a file', function (): void {
    $dir = fsWorkDir();

    try {
        file_put_contents($dir . '/a.txt', 'a');
        (new LocalFilesystem())->move($dir . '/a.txt', $dir . '/sub/b.txt');

        expect(is_file($dir . '/a.txt'))->toBeFalse()
            ->and(is_file($dir . '/sub/b.txt'))->toBeTrue();
    } finally {
        fsCleanup($dir);
    }
});

test('delete removes a file but is a no-op if already absent', function (): void {
    $dir = fsWorkDir();

    try {
        $fs = new LocalFilesystem();
        file_put_contents($dir . '/a.txt', 'a');
        $fs->delete($dir . '/a.txt');
        expect(is_file($dir . '/a.txt'))->toBeFalse();

        // Second delete on a now-absent file is silent.
        $fs->delete($dir . '/a.txt');
    } finally {
        fsCleanup($dir);
    }
});

test('deleteDirectory removes recursively and tolerates missing paths', function (): void {
    $dir = fsWorkDir();

    try {
        mkdir($dir . '/sub/inner', 0o755, true);
        file_put_contents($dir . '/sub/a.txt', 'a');
        file_put_contents($dir . '/sub/inner/b.txt', 'b');

        (new LocalFilesystem())->deleteDirectory($dir . '/sub');
        expect(is_dir($dir . '/sub'))->toBeFalse();

        (new LocalFilesystem())->deleteDirectory($dir . '/sub'); // no-op
    } finally {
        fsCleanup($dir);
    }
});

test('listDirectory returns immediate entry names and an empty list on missing path', function (): void {
    $dir = fsWorkDir();

    try {
        file_put_contents($dir . '/a.txt', 'a');
        mkdir($dir . '/sub');

        $names = (new LocalFilesystem())->listDirectory($dir);
        sort($names);
        expect($names)->toBe(['a.txt', 'sub'])
            ->and((new LocalFilesystem())->listDirectory($dir . '/does-not-exist'))->toBe([]);
    } finally {
        fsCleanup($dir);
    }
});

test('listFilesRecursively returns an empty list when the path is absent', function (): void {
    expect((new LocalFilesystem())->listFilesRecursively('/tmp/missing-' . uniqid()))
        ->toBe([]);
});

test('digestFile computes a sha512 digest and throws on missing file', function (): void {
    $dir = fsWorkDir();

    try {
        file_put_contents($dir . '/a.txt', 'content');
        expect((new LocalFilesystem())->digestFile($dir . '/a.txt', DigestAlgorithm::Sha512))
            ->toBe(hash('sha512', 'content'));

        expect(fn () => (new LocalFilesystem())->digestFile($dir . '/missing', DigestAlgorithm::Sha512))
            ->toThrow(RuntimeException::class);
    } finally {
        fsCleanup($dir);
    }
});

test('fileExists and directoryExists distinguish files from directories', function (): void {
    $dir = fsWorkDir();

    try {
        $fs = new LocalFilesystem();
        file_put_contents($dir . '/a.txt', 'a');

        expect($fs->fileExists($dir . '/a.txt'))->toBeTrue()
            ->and($fs->fileExists($dir))->toBeFalse()
            ->and($fs->directoryExists($dir))->toBeTrue()
            ->and($fs->directoryExists($dir . '/a.txt'))->toBeFalse();
    } finally {
        fsCleanup($dir);
    }
});
