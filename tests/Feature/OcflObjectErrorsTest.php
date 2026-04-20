<?php

declare(strict_types=1);

use Ottosmops\Ocfl\OcflObject;
use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\OcflException;

function errDir(): string
{
    $p = sys_get_temp_dir() . '/ocfl-obj-err-' . uniqid();
    mkdir($p, 0o755, true);

    return $p;
}

function errCleanup(string $dir): void
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

test('open throws E003 on a non-existent path', function (): void {
    try {
        OcflObject::open('/tmp/does-not-exist-' . uniqid());
    } catch (OcflException $e) {
        expect($e->errorCode)->toBe(ErrorCode::E003);

        return;
    }

    throw new RuntimeException('expected OcflException');
});

test('create refuses to initialise a non-empty directory', function (): void {
    $dir = errDir();

    try {
        file_put_contents($dir . '/blocker.txt', 'in the way');

        try {
            OcflObject::create($dir, 'urn:test:blocked');
            throw new RuntimeException('expected OcflException');
        } catch (OcflException $e) {
            expect($e->errorCode)->toBe(ErrorCode::E001);
        }
    } finally {
        errCleanup($dir);
    }
});

test('create can recover the id via PendingMeta across a reopen', function (): void {
    $dir = errDir();

    try {
        $path = $dir . '/obj';
        OcflObject::create($path, 'urn:test:persist');

        // Reopen in a fresh OcflObject — id must round-trip via the
        // pending-meta file written by create().
        $reopened = OcflObject::open($path);
        expect($reopened->id())->toBe('urn:test:persist')
            ->and($reopened->head())->toBe('');
    } finally {
        errCleanup($dir);
    }
});
