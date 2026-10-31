<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\InstanceDeploymentExecution;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

function runtimeDeployment(bool $executor, bool $rollback = false): array
{
    [, $node, , $instance] = deployment_api_fixture();
    config(['dependency_detection.services_node_id' => $executor ? $node->id : null, 'operation_executor.worker_node_ids' => []]);
    $operation = Operation::query()->create(['kind' => $rollback ? 'instance.rollback' : 'instance.deploy', 'target_type' => 'instance',
        'target_id' => $instance->id, 'node_id' => $node->id, 'status' => 'running', 'worker' => 'proof', 'sequence' => 1,
        'started_at' => now(), 'input' => ['instance_id' => $instance->id, 'release' => 'retained',
            'deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance, $rollback)]]);

    return [$operation, $instance];
}

it('publishes the PHP limits module after packages and before pool/cache effects', function (bool $rollback): void {
    [$operation, $instance] = runtimeDeployment(false, $rollback);
    $instance->update(['selected_php_version' => '8.5', ...ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5')->attributes()]);
    $operation->update(['input' => [...$operation->input, 'deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance, $rollback)]]);
    $home = $operation->input['deployment']['home'];
    $release = ['name' => $rollback ? 'retained' : InstanceDeploymentIntent::releaseName($operation), 'commit' => str_repeat('a', 40)];
    $operation->update(['state' => ['executor_runs' => [($rollback ? 'source.retained' : 'source.prepare') => ['result' => ['release' => $release]]]]]);
    $execution = app(InstanceDeploymentExecution::class);
    $descriptors = array_values(array_filter($execution->context($operation)['descriptors'], fn (array $item): bool => str_starts_with($item['step'], 'php_refresh')));
    expect(array_column($descriptors, 'key'))->toBe(['child.php_refresh.packages', 'php.profile', 'php.converge', 'php.refresh']);
    $profile = $descriptors[1];
    expect($profile['name'])->toBe('php.configure-runtime')->and($profile['input']['version'])->toBe('8.5')
        ->and($profile['input']['runtime_ini'])->toContain('post_max_size = 64M', 'upload_max_filesize = 64M', 'opcache.enable = On');
    expect($execution->received($operation, ['key' => 'php.profile'], ['changed' => true, 'reloaded' => true]))->toBe(['changed' => true]);
    expect(fn () => $execution->beforeRun($operation, ['key' => 'php.converge']))
        ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('operation.completion_unproven'));
})->with([false, true]);
