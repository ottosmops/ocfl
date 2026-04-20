<?php

declare(strict_types=1);

use Ottosmops\Ocfl\Cli\Application;
use Ottosmops\Ocfl\Storage\FlatDirectStorageLayout;
use Ottosmops\Ocfl\Storage\StorageRoot;

/**
 * @param  list<string>  $argv
 * @return array{exit: int, stdout: string, stderr: string}
 */
function runCli(array $argv): array
{
    $stdout = fopen('php://memory', 'w+') ?: throw new RuntimeException('fopen stdout');
    $stderr = fopen('php://memory', 'w+') ?: throw new RuntimeException('fopen stderr');

    $exitCode = (new Application())->run($argv, $stdout, $stderr);

    rewind($stdout);
    rewind($stderr);

    return [
        'exit' => $exitCode,
        'stdout' => (string) stream_get_contents($stdout),
        'stderr' => (string) stream_get_contents($stderr),
    ];
}

function cliWorkDir(): string
{
    $path = sys_get_temp_dir() . '/ocfl-cli-' . uniqid();
    mkdir($path, 0o755, true);

    return $path;
}

function cliCleanup(string $dir): void
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

test('prints usage when invoked with no arguments', function (): void {
    $result = runCli(['ocfl']);

    expect($result['exit'])->toBe(2)
        ->and($result['stderr'])->toContain('Usage:')
        ->and($result['stderr'])->toContain('validate')
        ->and($result['stderr'])->toContain('info')
        ->and($result['stderr'])->toContain('list');
});

test('prints help with --help', function (): void {
    $result = runCli(['ocfl', '--help']);

    expect($result['exit'])->toBe(0)
        ->and($result['stdout'])->toContain('Usage:')
        ->and($result['stdout'])->toContain('OCFL 1.1');
});

test('rejects unknown subcommand', function (): void {
    $result = runCli(['ocfl', 'nonsense']);

    expect($result['exit'])->toBe(2)
        ->and($result['stderr'])->toContain("unknown command 'nonsense'");
});

test('validate succeeds on a good-object fixture', function (): void {
    $fixture = __DIR__ . '/../fixtures/ocfl/1.1/good-objects/minimal_one_version_one_file';
    $result = runCli(['ocfl', 'validate', $fixture]);

    expect($result['exit'])->toBe(0)
        ->and($result['stdout'])->toContain('valid')
        ->and($result['stderr'])->toBe('');
});

test('validate fails on a bad-object fixture with exit code 1', function (): void {
    $fixture = __DIR__ . '/../fixtures/ocfl/1.1/bad-objects/E025_wrong_digest_algorithm';
    $result = runCli(['ocfl', 'validate', $fixture]);

    expect($result['exit'])->toBe(1)
        ->and($result['stdout'])->toContain('E025');
});

test('validate --json outputs machine-readable report', function (): void {
    $fixture = __DIR__ . '/../fixtures/ocfl/1.1/bad-objects/E036_no_id';
    $result = runCli(['ocfl', 'validate', '--json', $fixture]);

    expect($result['exit'])->toBe(1);

    /** @var array{valid: bool, errors: list<array{code: string}>} $decoded */
    $decoded = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
    expect($decoded)
        ->toHaveKey('valid')
        ->and($decoded['valid'])->toBeFalse()
        ->and($decoded)->toHaveKey('errors')
        ->and($decoded['errors'][0])->toHaveKey('code')
        ->and($decoded['errors'][0]['code'])->toBe('E036');
});

test('info prints object metadata', function (): void {
    $fixture = __DIR__ . '/../fixtures/ocfl/1.1/good-objects/spec-ex-full';
    $result = runCli(['ocfl', 'info', $fixture]);

    expect($result['exit'])->toBe(0)
        ->and($result['stdout'])->toContain('ark:/12345/bcd987')
        ->and($result['stdout'])->toContain('v1')
        ->and($result['stdout'])->toContain('v2')
        ->and($result['stdout'])->toContain('v3');
});

test('info --json prints structured metadata', function (): void {
    $fixture = __DIR__ . '/../fixtures/ocfl/1.1/good-objects/spec-ex-full';
    $result = runCli(['ocfl', 'info', '--json', $fixture]);

    expect($result['exit'])->toBe(0);

    /** @var array{id: string, head: string, versions: list<string>} $decoded */
    $decoded = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['id'])->toBe('ark:/12345/bcd987')
        ->and($decoded['head'])->toBe('v3')
        ->and($decoded['versions'])->toBe(['v1', 'v2', 'v3']);
});

test('list enumerates object ids in a storage root', function (): void {
    $dir = cliWorkDir();

    try {
        $root = StorageRoot::create($dir, new FlatDirectStorageLayout());
        foreach (['alpha', 'beta'] as $id) {
            $root->createObject($id)
                ->newVersion()
                ->addContents('x.txt', $id)
                ->commit();
        }

        $result = runCli(['ocfl', 'list', $dir]);

        expect($result['exit'])->toBe(0)
            ->and($result['stdout'])->toContain('alpha')
            ->and($result['stdout'])->toContain('beta');
    } finally {
        cliCleanup($dir);
    }
});

test('list --json returns an array of ids', function (): void {
    $dir = cliWorkDir();

    try {
        $root = StorageRoot::create($dir, new FlatDirectStorageLayout());
        $root->createObject('only-one')
            ->newVersion()
            ->addContents('x.txt', 'x')
            ->commit();

        $result = runCli(['ocfl', 'list', '--json', $dir]);

        expect($result['exit'])->toBe(0);
        $decoded = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
        expect($decoded)->toBe(['only-one']);
    } finally {
        cliCleanup($dir);
    }
});

test('validate reports the fixture path clearly on error', function (): void {
    $result = runCli(['ocfl', 'validate', '/path/does/not/exist']);

    expect($result['exit'])->toBe(1)
        ->and($result['stdout'])->toContain('E003');
});

test('info errors gracefully on a non-object path', function (): void {
    $result = runCli(['ocfl', 'info', '/tmp']);

    expect($result['exit'])->toBeGreaterThan(0)
        ->and($result['stderr'])->not->toBe('');
});
