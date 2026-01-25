<?php

use App\Data\Provision\StepResult;

it('creates successful result', function () {
    $result = StepResult::success();

    expect($result->isSuccess())->toBeTrue();
    expect($result->isFailed())->toBeFalse();
    expect($result->error)->toBeNull();
    expect($result->data)->toBe([]);
});

it('creates successful result with data', function () {
    $result = StepResult::success(['key' => 'value']);

    expect($result->isSuccess())->toBeTrue();
    expect($result->data)->toBe(['key' => 'value']);
});

it('creates failed result', function () {
    $result = StepResult::failed('Something went wrong');

    expect($result->isFailed())->toBeTrue();
    expect($result->isSuccess())->toBeFalse();
    expect($result->error)->toBe('Something went wrong');
});
