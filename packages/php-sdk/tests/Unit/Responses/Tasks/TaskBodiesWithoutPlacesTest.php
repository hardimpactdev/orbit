<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Operations\OperationResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
use Orbit\Sdk\Responses\Tasks\TasksStatusResponse;

/**
 * The task places view is removed (docs/reference/old-paths.md#the-task-vm-lifecycle): the Gateway sends no `capacity` in task:status
 * and no `capacity_wait` on a task or an Operation, and the SDK reads and writes neither.
 *
 * @return array{data: array<string, mixed>, request_id: string}
 */
function task_body_fixture(string $name): array
{
    $fixture = json_decode((string) file_get_contents(__DIR__."/../../../../fixtures/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);

    return ['data' => $fixture['body']['data'], 'request_id' => $fixture['body']['meta']['request_id']];
}

it('reads a task:status body without capacity', function (): void {
    ['data' => $data, 'request_id' => $requestId] = task_body_fixture('tasks/task-status/enabled');

    $status = TasksStatusResponse::fromGatewayData($data, $requestId);

    expect($data)->not->toHaveKey('capacity')
        ->and($status->enabled)->toBeTrue()
        ->and($status->toArray())->not->toHaveKey('capacity');
});

it('reads a task body without capacity_wait', function (): void {
    ['data' => $data, 'request_id' => $requestId] = task_body_fixture('tasks/task-show/preview');

    $group = TaskGroupResponse::fromGatewayData($data, $requestId);

    expect($data)->not->toHaveKey('capacity_wait')
        ->and($group->previewUrl)->toBe('https://task-1.orbit.test')
        ->and($group->toArray())->not->toHaveKey('capacity_wait');
});

it('reads an Operation body without capacity_wait', function (): void {
    ['data' => $data, 'request_id' => $requestId] = task_body_fixture('operations/operation-show/overdue');

    $operation = OperationResponse::fromGatewayData($data, $requestId);

    expect($data)->not->toHaveKey('capacity_wait')
        ->and($operation->id)->toBe($data['id'])
        ->and($operation->toArray())->not->toHaveKey('capacity_wait');
});
