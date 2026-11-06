<?php

declare(strict_types=1);

use App\Data\Proof\StartProofRunData;
use App\Http\Requests\Proof\StartProofRunRequest;
use Illuminate\Support\Facades\Validator;

const START_PROOF_SHA = '0123456789abcdef0123456789abcdef01234567';

it('maps report_only of the body to the data of the run, and leaves all and the commit as they are', function (array $body, bool $all, bool $reportOnly): void {
    $data = StartProofRunData::from(['commit' => START_PROOF_SHA, ...$body]);

    expect($data->commit)->toBe(START_PROOF_SHA)->and($data->all)->toBe($all)->and($data->reportOnly)->toBe($reportOnly);
})->with([
    'neither' => [[], false, false],
    'all' => [['all' => true], true, false],
    'report only' => [['report_only' => true], false, true],
    'both' => [['all' => true, 'report_only' => true], true, true],
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
