<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Cli\Command;

use Ottosmops\Ocfl\Validation\ObjectValidator;
use Ottosmops\Ocfl\Validation\ValidationIssue;
use Ottosmops\Ocfl\Validation\ValidationReport;

final class ValidateCommand implements Command
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
            fwrite($stderr, "validate: missing <path>\n");

            return 2;
        }

        $report = (new ObjectValidator())->validate($path);

        if ($options->json) {
            fwrite($stdout, self::toJson($report) . "\n");
        } else {
            fwrite($stdout, self::toHuman($report, $path));
        }

        return $report->isValid() ? 0 : 1;
    }

    private static function toJson(ValidationReport $report): string
    {
        return json_encode([
            'valid' => $report->isValid(),
            'errors' => array_map(self::issueToArray(...), $report->errors()),
            'warnings' => array_map(self::issueToArray(...), $report->warnings()),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string>
     */
    private static function issueToArray(ValidationIssue $issue): array
    {
        return [
            'code' => $issue->code->value,
            'message' => $issue->message,
            'path' => $issue->path,
        ];
    }

    private static function toHuman(ValidationReport $report, string $path): string
    {
        $lines = [];

        if ($report->isValid()) {
            $lines[] = "\033[32m✓\033[0m {$path} is valid";
        } else {
            $lines[] = "\033[31m✗\033[0m {$path} is invalid";
        }

        foreach ($report->errors() as $issue) {
            $where = $issue->path === '' ? '' : " ({$issue->path})";
            $lines[] = "  \033[31merror\033[0m [{$issue->code->value}] {$issue->message}{$where}";
        }

        foreach ($report->warnings() as $issue) {
            $where = $issue->path === '' ? '' : " ({$issue->path})";
            $lines[] = "  \033[33mwarn\033[0m  [{$issue->code->value}] {$issue->message}{$where}";
        }

        return implode("\n", $lines) . "\n";
    }
}
