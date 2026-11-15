<?php

declare(strict_types=1);

use App\Data\Proof\StartProofRunData;
use App\Http\Requests\Proof\StartProofRunRequest;
use Illuminate\Support\Facades\Validator;

const START_PROOF_SHA = '0123456789abcdef0123456789abcdef01234567';

it('accepts only a typed range base without an all-cases request', function (mixed $base, bool $all, bool $valid): void {
    expect(Validator::make(['commit' => START_PROOF_SHA, 'base' => $base, 'all' => $all], (new StartProofRunRequest)->rules())->passes())->toBe($valid);
})->with([
    'ancestor input' => [str_repeat('a', 40), false, true],
    'sha256 input' => [str_repeat('a', 64), false, true],
    'null' => [null, false, false],
    'empty' => ['', false, false],
    'branch' => ['main', false, false],
    'short sha' => ['abcdef0', false, false],
    'uppercase' => [str_repeat('A', 40), false, false],
    'trailing newline' => [str_repeat('a', 40)."\n", false, false],
    'number' => [123, false, false],
    'all conflicts' => [str_repeat('a', 40), true, false],
]);

it('keeps the supplied range base in typed admission data', function (): void {
    expect(StartProofRunData::from(['commit' => START_PROOF_SHA, 'base' => str_repeat('a', 40)])->base)->toBe(str_repeat('a', 40));
});

it('maps report_only of the body to the data of the run, and leaves all and the commit as they are', function (array $body, bool $all, bool $reportOnly): void {
    $data = StartProofRunData::from(['commit' => START_PROOF_SHA, ...$body]);

    expect($data->commit)->toBe(START_PROOF_SHA)->and($data->all)->toBe($all)->and($data->reportOnly)->toBe($reportOnly);
})->with([
    'neither' => [[], false, false],
    'all' => [['all' => true], true, false],
    'report only' => [['report_only' => true], false, true],
    'both' => [['all' => true, 'report_only' => true], true, true],
]);

it('maps keep_failed of the body to the data of the run, off unless it is given', function (array $body, bool $keepFailed): void {
    expect(StartProofRunData::from(['commit' => START_PROOF_SHA, ...$body])->keepFailed)->toBe($keepFailed);
})->with([
    'not given' => [[], false],
    'given' => [['keep_failed' => true], true],
]);

it('accepts keep_failed as a boolean only', function (mixed $value, bool $valid): void {
    $rules = (new StartProofRunRequest)->rules();

    expect(Validator::make(['commit' => START_PROOF_SHA, 'keep_failed' => $value], $rules)->passes())->toBe($valid);
})->with([
    'true' => [true, true],
    'false' => [false, true],
    'a string' => ['yes', false],
    'a number' => [1, false],
]);

it('accepts report_only as a boolean only', function (mixed $value, bool $valid): void {
    $rules = (new StartProofRunRequest)->rules();

    expect(Validator::make(['commit' => START_PROOF_SHA, 'report_only' => $value], $rules)->passes())->toBe($valid);
})->with([
    'true' => [true, true],
    'false' => [false, true],
    'a string' => ['yes', false],
    'a number' => [1, false],
]);
