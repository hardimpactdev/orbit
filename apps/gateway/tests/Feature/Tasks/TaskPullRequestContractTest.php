<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskPullRequestMergeProjection;
use App\Domain\Tasks\TaskPullRequestReadyProjection;
use App\Http\Requests\Tasks\TaskPullRequestMergeRequest;
use App\Http\Requests\Tasks\TaskPullRequestReadyRequest;
use App\Models\Operation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

const PR_HEAD = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

function pr_operation(array $input): Operation
{
    return (new Operation)->forceFill(['input' => $input]);
}

it('registers the ready and merge steps as native Swamp kinds with their workflows and token keys', function (): void {
    $kinds = app(DirectOperation::class)->definitions();

    expect($kinds)->toHaveKeys([OperationKinds::TasksPrReady, OperationKinds::TasksPrMerge])
        ->and($kinds[OperationKinds::TasksPrReady]['workflow'])->toBe('operation-task-pr-ready')
        ->and($kinds[OperationKinds::TasksPrMerge]['workflow'])->toBe('operation-task-pr-merge')
        ->and($kinds[OperationKinds::TasksPrReady]['projection'])->toBe(TaskPullRequestReadyProjection::class)
        ->and($kinds[OperationKinds::TasksPrMerge]['projection'])->toBe(TaskPullRequestMergeProjection::class)
        ->and($kinds[OperationKinds::TasksPrReady]['execution_copies'])->toBe([['vault' => 'orbit-control-inputs', 'key_pattern' => '^pr-ready-write-[a-f0-9-]{36}$']])
        ->and($kinds[OperationKinds::TasksPrMerge]['execution_copies'])->toBe([['vault' => 'orbit-control-inputs', 'key_pattern' => '^pr-merge-write-[a-f0-9-]{36}$']]);
});

it('routes the two steps on a task group', function (): void {
    $routes = app('router')->getRoutes();

    expect($routes->getByName('tasks:pr-ready')?->uri())->toBe('api/v1/task-groups/{group}/pr-ready')
        ->and($routes->getByName('tasks:pr-merge')?->uri())->toBe('api/v1/task-groups/{group}/pr-merge')
        ->and($routes->getByName('tasks:pr-ready')?->methods())->toContain('POST')
        ->and($routes->getByName('tasks:pr-merge')?->methods())->toContain('POST');
});

it('requires an exact head and a mode for the ready step', function (): void {
    $rules = (new TaskPullRequestReadyRequest)->rules();

    expect(Validator::make(['head_sha' => PR_HEAD, 'mode' => 'manual'], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['head_sha' => PR_HEAD, 'mode' => 'automatic'], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['head_sha' => PR_HEAD], $rules)->fails())->toBeTrue()
        ->and(Validator::make(['head_sha' => PR_HEAD, 'mode' => 'both'], $rules)->fails())->toBeTrue()
        ->and(Validator::make(['head_sha' => 'main', 'mode' => 'manual'], $rules)->fails())->toBeTrue()
        ->and(Validator::make(['head_sha' => strtoupper(PR_HEAD), 'mode' => 'manual'], $rules)->fails())->toBeTrue();
});

it('requires an exact head for the merge step and refuses unknown fields', function (): void {
    $rules = (new TaskPullRequestMergeRequest)->rules();
    $request = TaskPullRequestMergeRequest::create('/api/v1/task-groups/1/pr-merge', 'POST', server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['head_sha' => PR_HEAD, 'method' => 'merge'], JSON_THROW_ON_ERROR));

    expect(Validator::make(['head_sha' => PR_HEAD], $rules)->passes())->toBeTrue()
        ->and(Validator::make([], $rules)->fails())->toBeTrue()
        ->and(Validator::make(['head_sha' => substr(PR_HEAD, 0, 7)], $rules)->fails())->toBeTrue()
        ->and(fn () => $request->validationData())->toThrow(ValidationException::class);
});

it('records the ready receipt only for the admitted head and the requested review', function (): void {
    $projection = new TaskPullRequestReadyProjection;
    $operation = pr_operation(['pr_number' => 57, 'head_sha' => PR_HEAD, 'mode' => 'manual', 'reviewer' => 'nckrtl']);

    expect($projection->succeeded($operation, ['pull_request' => 57, 'head' => PR_HEAD, 'draft' => false, 'reviewers' => ['NckRtl']]))
        ->toBe(['pull_request_number' => 57, 'head_sha' => PR_HEAD, 'draft' => false, 'reviewers' => ['NckRtl']])
        ->and(fn () => $projection->succeeded($operation, ['head' => PR_HEAD, 'draft' => true, 'reviewers' => ['nckrtl']]))->toThrow(ResourceOperationException::class)
        ->and(fn () => $projection->succeeded($operation, ['head' => PR_HEAD, 'draft' => false, 'reviewers' => []]))->toThrow(ResourceOperationException::class)
        ->and(fn () => $projection->succeeded($operation, ['head' => str_repeat('0', 40), 'draft' => false, 'reviewers' => ['nckrtl']]))->toThrow(ResourceOperationException::class);

    $automatic = pr_operation(['pr_number' => 57, 'head_sha' => PR_HEAD, 'mode' => 'automatic', 'reviewer' => 'nckrtl']);

    expect($projection->succeeded($automatic, ['head' => PR_HEAD, 'draft' => false, 'reviewers' => []])['reviewers'])->toBe([]);
});

it('records the merge receipt only with a merge commit', function (): void {
    $projection = new TaskPullRequestMergeProjection;
    $operation = pr_operation(['pr_number' => 57, 'head_sha' => PR_HEAD]);
    $merge = str_repeat('c', 40);

    expect($projection->succeeded($operation, ['sha' => $merge, 'merged' => true, 'message' => 'Pull Request successfully merged']))
        ->toBe(['pull_request_number' => 57, 'head_sha' => PR_HEAD, 'merged' => true, 'merge_sha' => $merge])
        ->and(fn () => $projection->succeeded($operation, ['sha' => $merge, 'merged' => false]))->toThrow(ResourceOperationException::class)
        ->and(fn () => $projection->succeeded($operation, ['merged' => true]))->toThrow(ResourceOperationException::class);
});

it('maps a failed step to a known error code', function (): void {
    $ready = new TaskPullRequestReadyProjection;
    $merge = new TaskPullRequestMergeProjection;

    expect($ready->failureCode(['failed_step' => 'Check the PR head', 'detail' => 'tasks_pr.head_moved']))->toBe('tasks_pr.head_moved')
        ->and($ready->failureCode(['failed_step' => 'Take the PR out of draft', 'detail' => 'gh: Resource not accessible (HTTP 403)']))->toBe('tasks_pr.ready_refused')
        ->and($ready->failureCode(['failed_step' => 'Request the review', 'detail' => 'gh: Review cannot be requested from pull request author. (HTTP 422)']))->toBe('tasks_pr.review_request_refused')
        ->and($ready->failureCode(['failed_step' => 'Read the PR', 'detail' => 'tasks_pr.bogus']))->toBe('tasks_pr.ready_refused')
        ->and($merge->failureCode(['failed_step' => 'Squash on expected head', 'detail' => 'gh: Pull Request is not mergeable (HTTP 405)']))->toBe('tasks_pr.merge_refused')
        ->and($merge->failureCode([]))->toBe('tasks_pr.merge_refused');
});

it('keeps the token key names out of the intent that the Gateway records for display', function (): void {
    $operation = pr_operation(['pr_number' => 57, 'head_sha' => PR_HEAD, 'execution_secrets' => ['write_token' => ['vault' => 'orbit-control-inputs', 'key' => 'pr-merge-write-x']]]);
    $input = (new TaskPullRequestMergeProjection)->input($operation);

    expect($input['intent'])->not->toHaveKey('execution_secrets')
        ->and($input['execution_secrets'])->toBe(['write_token' => ['vault' => 'orbit-control-inputs', 'key' => 'pr-merge-write-x']]);
});
