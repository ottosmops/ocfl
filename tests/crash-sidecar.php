<?php

declare(strict_types=1);

// Standalone regression, no Stashd or test framework: php tests/crash-sidecar.php
spl_autoload_register(static function (string $class): void {
    $prefix = 'Ottosmops\\Ocfl\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Filesystem\Filesystem;
use Ottosmops\Ocfl\Filesystem\LocalFilesystem;
use Ottosmops\Ocfl\Inventory\InventorySidecar;

final class InterruptedFilesystem implements Filesystem
{
    private LocalFilesystem $local;
    public function __construct() { $this->local = new LocalFilesystem(); }
    public function write(string $path, string $contents): void
    {
        if (str_contains(basename($path), 'inventory.json.sha512')) {
            $this->local->write($path, substr($contents, 0, 4));
            posix_kill(getmypid(), 9);
            exit(99);
        }
        $this->local->write($path, $contents);
    }
    public function read(string $path): string { return $this->local->read($path); }
    public function copy(string $source, string $destination): void { $this->local->copy($source, $destination); }
    public function move(string $source, string $destination): void { $this->local->move($source, $destination); }
    public function delete(string $path): void { $this->local->delete($path); }
    public function deleteDirectory(string $path): void { $this->local->deleteDirectory($path); }
    public function createDirectory(string $path): void { $this->local->createDirectory($path); }
    public function fileExists(string $path): bool { return $this->local->fileExists($path); }
    public function directoryExists(string $path): bool { return $this->local->directoryExists($path); }
    public function listDirectory(string $path): array { return $this->local->listDirectory($path); }
    public function listFilesRecursively(string $path): array { return $this->local->listFilesRecursively($path); }
    public function digestFile(string $path, DigestAlgorithm $algorithm): string { return $this->local->digestFile($path, $algorithm); }
}

if (($argv[1] ?? '') === 'writer') {
    InventorySidecar::writeFor(new InterruptedFilesystem(), $argv[2], DigestAlgorithm::Sha512);
    exit(99);
}

$root = sys_get_temp_dir() . '/ocfl-sidecar-crash-' . bin2hex(random_bytes(12));
$fs = new LocalFilesystem();
$fs->createDirectory($root);
try {
    $fs->write($root . '/inventory.json', 'previous inventory');
    InventorySidecar::writeFor($fs, $root, DigestAlgorithm::Sha512);
    $previous = $fs->read($root . '/inventory.json.sha512');
    $fs->write($root . '/inventory.json', 'next inventory');
    $process = proc_open([PHP_BINARY, __FILE__, 'writer', $root], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $exit = proc_close($process);
    if ($exit === 0 || $exit === 99) {
        throw new RuntimeException('Writer was not killed abruptly.');
    }
    if ($fs->read($root . '/inventory.json.sha512') !== $previous) {
        throw new RuntimeException('Crash destroyed the previously acknowledged inventory checksum.');
    }
    InventorySidecar::writeFor($fs, $root, DigestAlgorithm::Sha512);
    if (!InventorySidecar::verify($fs, $root, DigestAlgorithm::Sha512)) {
        throw new RuntimeException('Successful replacement did not publish the next checksum.');
    }
    echo "PASS: SIGKILL preserves old sidecar; successful replacement verifies.\n";
} finally {
    $fs->deleteDirectory($root);
}
