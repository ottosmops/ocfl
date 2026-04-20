<?php

declare(strict_types=1);

use Ottosmops\Ocfl\Digest;
use Ottosmops\Ocfl\DigestAlgorithm;

test('computes sha512 digest of a string', function (): void {
    expect(Digest::ofString('content', DigestAlgorithm::Sha512))
        ->toBe(hash('sha512', 'content'));
});

test('computes sha256 digest of a string', function (): void {
    expect(Digest::ofString('content', DigestAlgorithm::Sha256))
        ->toBe(hash('sha256', 'content'));
});

test('returns lowercase hexadecimal per OCFL spec section 7.2', function (): void {
    $result = Digest::ofString('ABC', DigestAlgorithm::Sha512);

    expect($result)->toMatch('/^[a-f0-9]+$/');
});

test('computes sha512 digest of a file via streaming', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'ocfl-');
    file_put_contents($path, 'hello world');

    expect(Digest::ofFile($path, DigestAlgorithm::Sha512))
        ->toBe(hash('sha512', 'hello world'));

    unlink($path);
});

test('compares digest strings case-insensitively per OCFL spec', function (): void {
    // Per OCFL 1.1 §3.5.1: digest equality MUST be case-insensitive.
    expect(Digest::equals('ABCDEF', 'abcdef'))->toBeTrue()
        ->and(Digest::equals('abcdef', 'abcdee'))->toBeFalse();
});

test('throws when hashing a non-existent file', function (): void {
    expect(fn () => Digest::ofFile('/tmp/does-not-exist-' . uniqid(), DigestAlgorithm::Sha512))
        ->toThrow(RuntimeException::class);
});

test('exposes primary algorithms allowed by OCFL spec', function (): void {
    expect(DigestAlgorithm::Sha512->isPrimary())->toBeTrue()
        ->and(DigestAlgorithm::Sha256->isPrimary())->toBeTrue()
        ->and(DigestAlgorithm::Sha1->isPrimary())->toBeFalse()
        ->and(DigestAlgorithm::Md5->isPrimary())->toBeFalse();
});
