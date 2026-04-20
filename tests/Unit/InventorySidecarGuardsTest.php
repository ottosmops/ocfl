<?php

declare(strict_types=1);

use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Filesystem\LocalFilesystem;
use Ottosmops\Ocfl\Inventory\InventorySidecar;
use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\OcflException;

function sidecarDir(): string
{
    $p = sys_get_temp_dir() . '/ocfl-sidecar-guard-' . uniqid();
    mkdir($p, 0o755, true);

    return $p;
}

function sidecarCleanup(string $p): void
{
    if (is_dir($p)) {
        foreach (glob($p . '/*') ?: [] as $f) {
            is_file($f) && unlink($f);
        }
        rmdir($p);
    }
}

test('writeFor throws E058 when no inventory.json is present', function (): void {
    $dir = sidecarDir();

    try {
        try {
            InventorySidecar::writeFor(new LocalFilesystem(), $dir, DigestAlgorithm::Sha512);
            throw new RuntimeException('expected OcflException');
        } catch (OcflException $e) {
            expect($e->errorCode)->toBe(ErrorCode::E058);
        }
    } finally {
        sidecarCleanup($dir);
    }
});
