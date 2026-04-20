<?php

declare(strict_types=1);

use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\ValidationIssue;
use Ottosmops\Ocfl\Validation\ValidationReport;

test('fresh report is valid with no errors and no warnings', function (): void {
    $report = new ValidationReport();

    expect($report->isValid())->toBeTrue()
        ->and($report->hasWarnings())->toBeFalse()
        ->and($report->errors())->toBe([])
        ->and($report->warnings())->toBe([])
        ->and($report->errorCodes())->toBe([])
        ->and($report->warningCodes())->toBe([]);
});

test('adding errors flips validity and exposes codes', function (): void {
    $report = new ValidationReport();
    $report->addError(ErrorCode::E040, 'nope', 'v2');
    $report->addError(ErrorCode::E060, 'mismatch');

    expect($report->isValid())->toBeFalse()
        ->and($report->errorCodes())->toBe([ErrorCode::E040, ErrorCode::E060])
        ->and($report->hasError(ErrorCode::E040))->toBeTrue()
        ->and($report->hasError(ErrorCode::E107))->toBeFalse()
        ->and($report->errors()[0])->toBeInstanceOf(ValidationIssue::class)
        ->and($report->errors()[0]->path)->toBe('v2');
});

test('warnings flip hasWarnings but leave validity intact', function (): void {
    $report = new ValidationReport();
    $report->addWarning(ErrorCode::W004, 'sha256');

    expect($report->isValid())->toBeTrue()
        ->and($report->hasWarnings())->toBeTrue()
        ->and($report->warningCodes())->toBe([ErrorCode::W004])
        ->and($report->hasWarning(ErrorCode::W004))->toBeTrue()
        ->and($report->hasWarning(ErrorCode::W013))->toBeFalse();
});
