<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Cli\Command;

use FilesystemIterator;
use Ottosmops\Ocfl\OcflObject;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class CommitCommand implements Command
{
    /**
     * @param  list<string>  $args
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public function run(array $args, $stdout, $stderr): int
    {
        $options = Options::parse($args);
        $path = $options->positional[0] ?? null;

        if ($path === null) {
            fwrite($stderr, "commit: usage: ocfl commit <path> --from=<dir> [--message=] [--user=] [--user-address=]\n");

            return 2;
        }

        $from = $options->value('from');
        if ($from === null) {
            fwrite($stderr, "commit: --from=<source-directory> is required\n");

            return 2;
        }

        if (! is_dir($from)) {
            fwrite($stderr, "commit: source directory does not exist: {$from}\n");

            return 2;
        }

        $object = OcflObject::open($path);
        $builder = $object->newVersion();

        // Use the source directory as the canonical logical state of the
        // next version: add everything under $from, and remove anything in
        // the head that isn't present in $from (so the commit produces a
        // state that mirrors the on-disk snapshot).
        $sourcePaths = self::collectFiles($from);
        foreach ($sourcePaths as $logicalPath => $absolute) {
            $builder->addFile($logicalPath, $absolute);
        }

        if ($object->head() !== '') {
            $existing = $object->logicalPaths($object->head());
            foreach ($existing as $existingPath) {
                if (! isset($sourcePaths[$existingPath])) {
                    $builder->removeFile($existingPath);
                }
            }
        }

        $message = $options->value('message');
        if ($message !== null) {
            $builder->withMessage($message);
        }

        $userName = $options->value('user');
        if ($userName !== null) {
            $builder->withUser($userName, $options->value('user-address'));
        }

        $updated = $builder->commit();

        fwrite($stdout, "committed {$updated->head()} to {$path}\n");

        return 0;
    }

    /**
     * @return array<string, string> logicalPath → absolute source path
     */
    private static function collectFiles(string $directory): array
    {
        $out = [];
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        $prefix = strlen($directory) + 1;
        foreach ($iter as $entry) {
            /** @var SplFileInfo $entry */
            if (! $entry->isFile()) {
                continue;
            }
            $logical = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), $prefix));
            $out[$logical] = $entry->getPathname();
        }

        ksort($out);

        return $out;
    }
}
