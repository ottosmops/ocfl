<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Cli\Command;

/**
 * Contract for a single `ocfl` subcommand. Writes human or JSON output to
 * $stdout, diagnostics to $stderr, and returns a process exit code.
 */
interface Command
{
    /**
     * @param  list<string>  $args  the command's own arguments (without the
     *                              subcommand name itself)
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public function run(array $args, $stdout, $stderr): int;
}
