<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceNativeDeploymentResult;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

function native_deploy_result_fixture(bool $development = true): array
{
    $instance = $development ? dev935_instance() : deployment_api_fixture()[3];
    $operation = Operation::query()->create([
        'kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'status' => OperationStatus::Running,
        'input' => ['instance_id' => $instance->id, 'deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance)],
    ]);
    $release = ['name' => InstanceDeploymentIntent::releaseName($operation), 'commit' => str_repeat('a', 40)];
    $previous = ['name' => 'previous', 'commit' => str_repeat('b', 40)];

    return [$operation, [
        'instance_id' => $instance->id, 'commit' => str_repeat('a', 40),
        'release' => $development ? null : $release, 'previous' => $development ? null : $previous,
        'selected_release' => $development ? null : $release, 'selection_confirmed' => true,
        'failed_step' => null, 'warnings' => 0, 'output_tail' => null,
    ]];
}

it('projects a native development checkout without inventing a production release', function (): void {
    [$operation, $payload] = native_deploy_result_fixture(true);
    $result = InstanceNativeDeploymentResult::from($operation, 'succeeded', null, $payload)->deployment();
    expect($result->succeeded)->toBeTrue()->and($result->commit)->toBe(str_repeat('a', 40))
        ->and($result->release)->toBeNull()->and($result->selectedRelease)->toBeNull();
});

it('records a failed development step and cancellation without a production release', function (): void {
    [$operation, $payload] = native_deploy_result_fixture();
    $payload['failed_step'] = 'before_activation';
    $failed = InstanceNativeDeploymentResult::from($operation, 'failed', 'deployment.step_failed', $payload)->deployment();
    expect($failed->succeeded)->toBeFalse()->and($failed->selectedRelease)->toBeNull();
    $cancelled = InstanceNativeDeploymentResult::from($operation, 'cancelled', null, $payload)->deployment();
    expect($cancelled->failure?->errorCode)->toBe('deployment.cancelled');
});

it('refuses forged native identity selection and terminal outcome shapes', function (string $change): void {
    [$operation, $payload] = native_deploy_result_fixture();
    $status = 'succeeded';
    $code = null;
    match ($change) {
        'instance' => $payload['instance_id']++,
        'candidate' => $payload['release'] = ['name' => 'foreign', 'commit' => str_repeat('a', 40)],
        'selected' => $payload['selected_release'] = ['name' => 'foreign', 'commit' => str_repeat('a', 40)],
        'unknown success' => $payload['selection_confirmed'] = false,
        'supplied path' => $payload['release'] = ['path' => '/foreign'],
        'commit type' => $payload['commit'] = 123,
        'unbounded warnings' => $payload['warnings'] = 65,
        'private extra' => $payload['program'] = 'private-program',
        'error success' => $code = 'deployment.failed',
        'failure without code' => $status = 'failed',
        'nonterminal' => $status = 'running',
    };
    expect(fn () => InstanceNativeDeploymentResult::from($operation, $status, $code, $payload))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.result_invalid'));
})->with(['instance', 'candidate', 'selected', 'unknown success', 'supplied path', 'commit type', 'unbounded warnings', 'private extra', 'error success', 'failure without code', 'nonterminal']);

it('refuses a native commit that differs from an admitted pin', function (): void {
    [$operation, $payload] = native_deploy_result_fixture();
    $operation->input = [...$operation->input, 'commit' => str_repeat('c', 40)];
    expect(fn () => InstanceNativeDeploymentResult::from($operation, 'succeeded', null, $payload))->toThrow(ResourceOperationException::class);
});
