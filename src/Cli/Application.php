<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Cli;

use Ottosmops\Ocfl\Cli\Command\Command;
use Ottosmops\Ocfl\Cli\Command\InfoCommand;
use Ottosmops\Ocfl\Cli\Command\ListCommand;
use Ottosmops\Ocfl\Cli\Command\ValidateCommand;
use Throwable;

/**
 * Tiny argv dispatcher for the `ocfl` CLI.
 *
 * Zero external dependencies — parses the first positional argument as the
 * subcommand and passes the remainder to the matching Command object.
 *
 * Exit codes:
 *   0 success
 *   1 operational failure (e.g., validation reported errors)
 *   2 usage error (unknown command, bad arguments)
 */
final class Application
{
    private const COMMANDS = [
        'validate' => ValidateCommand::class,
        'info' => InfoCommand::class,
        'list' => ListCommand::class,
    ];

    /**
     * @param  list<string>  $argv
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public function run(array $argv, $stdout, $stderr): int
    {
        $args = array_slice($argv, 1);

        if ($args === [] || $args[0] === '--help' || $args[0] === '-h' || $args[0] === 'help') {
            $target = $args === [] ? $stderr : $stdout;
            fwrite($target, $this->usage());

            return $args === [] ? 2 : 0;
        }

        $name = $args[0];

        if (! isset(self::COMMANDS[$name])) {
            fwrite($stderr, "ocfl: unknown command '{$name}'\n\n" . $this->usage());

            return 2;
        }

        /** @var class-string<Command> $class */
        $class = self::COMMANDS[$name];
        $command = new $class();

        try {
            return $command->run(array_values(array_slice($args, 1)), $stdout, $stderr);
        } catch (Throwable $e) {
            fwrite($stderr, "ocfl: {$e->getMessage()}\n");

            return 3;
        }
    }

    private function usage(): string
    {
        return <<<USAGE
        ocfl — OCFL 1.1 command-line tool

        Usage:
          ocfl <command> [options] <path>

        Commands:
          validate <path>        Validate an OCFL object at <path>.
          info <path>            Print metadata about an OCFL object.
          list <storage-root>    List every object id under a storage root.

        Global options:
          --json                 Emit machine-readable JSON on stdout.
          --help                 Show this help.

        Exit codes:
          0 success · 1 object invalid · 2 usage error · 3 runtime error


        USAGE;
    }
}
