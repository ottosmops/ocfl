<?php

declare(strict_types=1);

use Ottosmops\Ocfl\Digest;
use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\StagedContent;

test('StagedContent constructor requires sourcePath or inlineBytes', function (): void {
    expect(fn () => new StagedContent())->toThrow(RuntimeException::class);
});

test('StagedContent can be constructed from inline bytes only', function (): void {
    $staged = new StagedContent(inlineBytes: 'abc');

    expect($staged)->toBeInstanceOf(StagedContent::class);
});

test('Digest::ofFile rejects a path that is a directory', function (): void {
    $dir = sys_get_temp_dir() . '/ocfl-digest-dir-' . uniqid();
    mkdir($dir);

    try {
        expect(fn () => Digest::ofFile($dir, DigestAlgorithm::Sha512))
            ->toThrow(RuntimeException::class);
    } finally {
        rmdir($dir);
    }
});

test('Digest::ofString handles empty input', function (): void {
    expect(Digest::ofString('', DigestAlgorithm::Sha512))
        ->toBe(hash('sha512', ''));
});
