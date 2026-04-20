<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Cli\Command;

use Generator;
use Ottosmops\Ocfl\Filesystem\LocalFilesystem;
use Ottosmops\Ocfl\OcflObject;

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

        // Take the source directory as the canonical logical state of the
        // next version: add every file under $from, then remove any path
        // from the previous head that isn't in $from.
        $sourceSet = [];
        foreach (self::iterateSource($from) as $logicalPath => $absolute) {
            $builder->addFile($logicalPath, $absolute);
            $sourceSet[$logicalPath] = true;
        }

        if ($object->head() !== '') {
            foreach ($object->logicalPaths($object->head()) as $existingPath) {
                if (! isset($sourceSet[$existingPath])) {
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
     * @return Generator<string, string> logicalPath → absolute source path
     */
    private static function iterateSource(string $directory): Generator
    {
        $fs = new LocalFilesystem();
        foreach ($fs->listFilesRecursively($directory) as $relative) {
            yield $relative => $directory . '/' . $relative;
        }
    }
}
