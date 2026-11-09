<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
use Orbit\Sdk\Responses\Tasks\TasksStatusResponse;

/** @return array{data: array<string, mixed>, request_id: string} */
function task_place_fixture(string $name): array
{
    $fixture = json_decode((string) file_get_contents(__DIR__."/../../../../fixtures/tasks/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);

    return ['data' => $fixture['body']['data'], 'request_id' => $fixture['body']['meta']['request_id']];
}

it('keeps the task places of each host and the task queue of task:status', function (): void {
    ['data' => $data, 'request_id' => $requestId] = task_place_fixture('task-status/capacity');

    $status = TasksStatusResponse::fromGatewayData($data, $requestId);

    expect($status->capacity->hosts[0]->taskFree)->toBe(0)
        ->and($status->capacity->hosts[0]->holders[1]->activeOperationId)->toBe(91)
        ->and($status->capacity->queue[0]->position)->toBe(1)
        ->and($status->toArray()['capacity'])->toBe($data['capacity']);
});

it('refuses a task:status reply without its capacity', function (): void {
    ['data' => $data, 'request_id' => $requestId] = task_place_fixture('task-status/enabled');
    unset($data['capacity']);

    TasksStatusResponse::fromGatewayData($data, $requestId);
})->throws(GatewayApiException::class, 'invalid task place');

it('keeps the queue position, holders, last activity and preview URL of a group', function (): void {
    ['data' => $waiting, 'request_id' => $requestId] = task_place_fixture('task-show/waiting');
    ['data' => $preview] = task_place_fixture('task-show/preview');

    $group = TaskGroupResponse::fromGatewayData($waiting, $requestId);

    expect($group->capacityWait?->position)->toBe(1)
        ->and($group->toArray()['capacity_wait'])->toBe($waiting['capacity_wait'])
        ->and($group->lastActivityAt)->toBe('2026-11-09T11:55:00+00:00')
        ->and(TaskGroupResponse::fromGatewayData($preview, $requestId)->previewUrl)->toBe('https://task-1.orbit.test');
});

it('keeps the capacity_wait of a waiting Operation', function (): void {
    ['data' => $group] = task_place_fixture('task-show/waiting');
    $operation = OperationResponse::fromGatewayData(['id' => 95, 'kind' => 'tasks.provision', 'target_type' => 'task_group', 'target_id' => 1,
        'status' => 'waiting', 'sequence' => 2, 'input' => ['capacity_request' => ['commit' => 'abc']], 'poll_after_seconds' => 20,
        'capacity_wait' => $group['capacity_wait']], 'request');

    expect($operation->capacityWait?->toArray())->toBe($group['capacity_wait'])
        ->and($operation->toArray()['poll_after_seconds'])->toBe(20)
        ->and($operation->toArray()['input'])->toBe(['capacity_request' => ['commit' => 'abc']]);
});
