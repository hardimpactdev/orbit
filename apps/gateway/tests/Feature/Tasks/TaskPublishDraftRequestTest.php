<?php

declare(strict_types=1);

use App\Data\Tasks\StartTaskPublishData;
use App\Http\Requests\Tasks\StartTaskPublishRequest;
use Illuminate\Support\Facades\Validator;

it('accepts a draft flag with the title and body of the pull request', function (): void {
    $rules = (new StartTaskPublishRequest)->rules();
    $pull = ['title' => 'Spec', 'body' => 'Chart, page and cases'];

    expect(Validator::make(['check' => 4, 'pull_request' => $pull], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['check' => 4, 'pull_request' => [...$pull, 'draft' => true]], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['check' => 4, 'pull_request' => [...$pull, 'draft' => 'yes']], $rules)->fails())->toBeTrue()
        ->and(Validator::make(['check' => 4, 'pull_request' => [...$pull, 'reviewers' => ['nckrtl']]], $rules)->fails())->toBeTrue();
});

it('keeps the draft flag in the payload the operation records', function (): void {
    $payload = StartTaskPublishData::from(['check' => 4, 'pull_request' => ['title' => 'Spec', 'body' => 'Body', 'draft' => true]]);

    expect($payload->toArray()['pull_request'])->toBe(['title' => 'Spec', 'body' => 'Body', 'draft' => true]);
});
