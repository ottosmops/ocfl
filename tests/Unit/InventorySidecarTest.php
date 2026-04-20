<?php

declare(strict_types=1);

use Ottosmops\Ocfl\Digest;
use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Inventory\InventorySidecar;
use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\OcflException;

function makeInventoryDir(): string
{
    $path = sys_get_temp_dir() . '/ocfl-sidecar-' . uniqid();
    mkdir($path, 0o755, true);

    return $path;
}

function cleanupDir(string $path): void
{
    if (! is_dir($path)) {
        return;
    }
    foreach (glob($path . '/*') ?: [] as $file) {
        is_file($file) && unlink($file);
    }
    rmdir($path);
}

test('sidecar filename matches the digest algorithm', function (): void {
    expect(InventorySidecar::filename(DigestAlgorithm::Sha512))->toBe('inventory.json.sha512')
        ->and(InventorySidecar::filename(DigestAlgorithm::Sha256))->toBe('inventory.json.sha256');
});

test('writes sidecar with digest and filename separated by space', function (): void {
    $dir = makeInventoryDir();

    try {
        file_put_contents($dir . '/inventory.json', '{"id":"test"}');

        $sidecarPath = InventorySidecar::writeFor($dir, DigestAlgorithm::Sha512);
        $contents = (string) file_get_contents($sidecarPath);

        $expectedDigest = hash('sha512', '{"id":"test"}');
        expect($contents)->toBe("{$expectedDigest} inventory.json\n");
    } finally {
        cleanupDir($dir);
    }
});

test('verifies a valid sidecar against the inventory file', function (): void {
    $dir = makeInventoryDir();

    try {
        file_put_contents($dir . '/inventory.json', 'some content');
        InventorySidecar::writeFor($dir, DigestAlgorithm::Sha512);

        expect(InventorySidecar::verify($dir, DigestAlgorithm::Sha512))->toBeTrue();
    } finally {
        cleanupDir($dir);
    }
});

test('reports verification failure when inventory changes after sidecar', function (): void {
    $dir = makeInventoryDir();

    try {
        file_put_contents($dir . '/inventory.json', 'original');
        InventorySidecar::writeFor($dir, DigestAlgorithm::Sha512);

        file_put_contents($dir . '/inventory.json', 'tampered');

        expect(InventorySidecar::verify($dir, DigestAlgorithm::Sha512))->toBeFalse();
    } finally {
        cleanupDir($dir);
    }
});

test('reads digest from real OCFL fixture sidecar', function (): void {
    $fixture = __DIR__ . '/../fixtures/ocfl/1.1/good-objects/minimal_one_version_one_file';
    $digest = InventorySidecar::readDigest($fixture, DigestAlgorithm::Sha512);

    expect($digest)->toHaveLength(128) // sha512 hex
        ->and(Digest::equals($digest, Digest::ofFile($fixture . '/inventory.json', DigestAlgorithm::Sha512)))
        ->toBeTrue();
});

test('throws when sidecar file is missing', function (): void {
    $dir = makeInventoryDir();

    try {
        try {
            InventorySidecar::readDigest($dir, DigestAlgorithm::Sha512);
        } catch (OcflException $e) {
            expect($e->errorCode)->toBe(ErrorCode::E058);

            return;
        }
        throw new RuntimeException('expected OcflException');
    } finally {
        cleanupDir($dir);
    }
});

test('throws when sidecar is malformed', function (): void {
    $dir = makeInventoryDir();

    try {
        file_put_contents($dir . '/inventory.json', 'x');
        file_put_contents($dir . '/inventory.json.sha512', "garbage without filename\n");

        try {
            InventorySidecar::readDigest($dir, DigestAlgorithm::Sha512);
            throw new RuntimeException('expected OcflException');
        } catch (OcflException $e) {
            expect($e)->toBeInstanceOf(OcflException::class);
        }
    } finally {
        cleanupDir($dir);
    }
});
