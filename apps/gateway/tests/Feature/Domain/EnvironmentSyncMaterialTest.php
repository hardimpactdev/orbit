<?php

declare(strict_types=1);

use App\Actions\Instances\SynchronizeInstanceEnvironmentAction;
use App\Domain\Instances\Environment\EnvironmentSyncExecution;
use App\Domain\Instances\Environment\EnvironmentSyncMaterial;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;

function environmentMaterialOperation(): Operation
{
    [, $node, , $instance] = deployment_api_fixture();
    $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $node->id,
        'domain' => 'proof.example.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $instance->update(['status' => 'active', 'provisioning_step' => 'active', 'source_layout' => 'release', 'source_is_laravel' => false]);
    $instance->environmentValues()->create(['env_key' => 'API_TOKEN', 'env_value' => 'synthetic-secret']);

    return new Operation(['kind' => 'instance.sync_environment', 'target_type' => 'instance',
        'target_id' => $instance->id, 'node_id' => $node->id,
        'input' => ['instance_id' => $instance->id]]);
}

it('renders protected content after an in-memory workload key check', function (): void {
    $operation = environmentMaterialOperation();
    $preflight = Mockery::mock(InstanceOperationPreflight::class);
    $preflight->shouldReceive('readWritableEnvironment')->once()->andReturn('API_TOKEN=old');
    app()->instance(InstanceOperationPreflight::class, $preflight);
    $material = app(EnvironmentSyncMaterial::class)->render($operation, false);
    expect($material['contents'])->toBe("API_TOKEN=\"synthetic-secret\"\n")
        ->and($material['keys'])->toBe(['API_TOKEN'])
        ->and($operation->state)->toBeNull();
});

it('preserves a workload key which has not been imported rather than overwriting its file', function (): void {
    $operation = environmentMaterialOperation();
    $preflight = Mockery::mock(InstanceOperationPreflight::class);
    $preflight->shouldReceive('readWritableEnvironment')->once()->andReturn("API_TOKEN=old\nUNOWNED=value\n");
    app()->instance(InstanceOperationPreflight::class, $preflight);
    expect(fn () => app(EnvironmentSyncMaterial::class)->render($operation, false))
        ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('env.sync_would_drop_keys'));
});

it('refuses a substituted Node or Instance before reading protected material', function (string $field): void {
    $operation = environmentMaterialOperation();
    if ($field === 'node') {
        $operation->node_id++;
    } else {
        $operation->input = [...$operation->input, 'instance_id' => $operation->target_id + 1];
    }
    $preflight = Mockery::mock(InstanceOperationPreflight::class);
    $preflight->shouldNotReceive('readWritableEnvironment');
    app()->instance(InstanceOperationPreflight::class, $preflight);
    expect(fn () => app(EnvironmentSyncMaterial::class)->render($operation, false))
        ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('operation.execution_forbidden'));
})->with(['node', 'instance']);

function transferEnvironmentMaterial(): array
{
    $instance = dev935_instance();
    $instance->update(['provisioning_step' => 'active', 'source_is_laravel' => false]);
    $destination = Node::query()->create(['name' => 'destination', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.50', 'wireguard_ip' => '10.44.0.50']);
    $destination->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'domain' => 'source.example.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $transfer = InstanceTransfer::query()->create(['instance_id' => $instance->id, 'source_node_id' => $instance->node_id, 'destination_node_id' => $destination->id, 'destination_name' => 'default', 'destination_path' => '/fast/apps/proof/default', 'destination_domain' => 'destination.example.test', 'source_layout' => 'checkout', 'source_path' => $instance->checkout_path, 'source_route_id' => $route->id, 'status' => 'in_progress', 'current_step' => 'destination-checkout-created']);
    $parent = Operation::query()->create(['kind' => 'instance.transfer', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id, 'status' => 'running', 'sequence' => 1, 'input' => ['instance_id' => $instance->id, 'transfer_id' => $transfer->id, 'destination_node_id' => $destination->id]]);
    $operation = new Operation(['kind' => 'instance.sync_environment', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $destination->id, 'input' => ['instance_id' => $instance->id]]);
    $operation->setAttribute('parent_operation_id', $parent->id);

    return [$operation, $instance, $transfer, $parent, $route];
}

it('imports only absent source keys and retains rollback inventory before destination rendering', function (): void {
    [$operation, $instance, $transfer] = transferEnvironmentMaterial();
    $instance->environmentValues()->create(['env_key' => 'PRESERVED', 'env_value' => 'gateway-value']);
    $reader = Mockery::mock(InstanceEnvironmentReader::class);
    $reader->shouldReceive('read')->twice()->withArgs(fn ($context): bool => $context->nodeId === $transfer->source_node_id && $context->path === $transfer->source_path)->andReturn("PRESERVED=source-value\nIMPORTED=synthetic-source-value\n");
    app()->instance(InstanceEnvironmentReader::class, $reader);
    $preflight = Mockery::mock(InstanceOperationPreflight::class);
    $preflight->shouldReceive('assertEnvironmentWritable')->once()->withArgs(fn ($context, $capacity): bool => $context->nodeId === $transfer->destination_node_id && $context->path === $transfer->destination_path);
    app()->instance(InstanceOperationPreflight::class, $preflight);
    $material = app(EnvironmentSyncMaterial::class);
    $material->prepare($operation);
    $material->prepare($operation);
    expect($transfer->refresh()->imported_environment_keys)->toBe(['IMPORTED'])->and(app(InstanceEnvironmentStore::class)->values($instance))->toBe(['IMPORTED' => 'synthetic-source-value', 'PRESERVED' => 'gateway-value']);
    $rendered = $material->render($operation, false);
    expect($rendered['values']['PRESERVED'])->toBe('gateway-value')->and($operation->state)->toBeNull()->and($material->context($operation)->routeDomain)->toBe('destination.example.test');
});

it('rejects a substituted destination parent before importing source environment', function (): void {
    [$operation, , , $parent] = transferEnvironmentMaterial();
    $parent->update(['input' => [...$parent->input, 'instance_id' => $operation->target_id + 1]]);
    $reader = Mockery::mock(InstanceEnvironmentReader::class);
    $reader->shouldNotReceive('read');
    app()->instance(InstanceEnvironmentReader::class, $reader);
    expect(fn () => app(EnvironmentSyncMaterial::class)->prepare($operation))->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('operation.execution_forbidden'));
});

it('does not use a web-root Route as the Instance own destination domain', function (): void {
    [$operation, , , , $route] = transferEnvironmentMaterial();
    $route->update(['web_root' => 'nested/public']);
    expect(app(EnvironmentSyncMaterial::class)->context($operation)->routeDomain)->toBeNull();
});

it('refuses using caller input to substitute transfer ancestry', function (): void {
    [$operation] = transferEnvironmentMaterial();
    $operation->input = [...$operation->input, 'parent_operation_id' => $operation->getAttribute('parent_operation_id')];
    $operation->setAttribute('parent_operation_id', null);
    $reader = Mockery::mock(InstanceEnvironmentReader::class);
    $reader->shouldNotReceive('read');
    app()->instance(InstanceEnvironmentReader::class, $reader);

    expect(fn () => app(EnvironmentSyncMaterial::class)->prepare($operation))
        ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('operation.execution_forbidden'));
});

it('uses stamped nested destination ancestry before transfer cutover', function (): void {
    [$operation, $instance, $transfer, $parent] = transferEnvironmentMaterial();
    $destination = Operation::query()->create(['kind' => 'instance.transfer_destination', 'target_type' => 'instance',
        'target_id' => $instance->id, 'node_id' => $transfer->destination_node_id, 'status' => 'running', 'sequence' => 1,
        'input' => ['instance_id' => $instance->id, 'transfer_id' => $transfer->id]]);
    $destination->forceFill(['parent_operation_id' => $parent->id, 'child_key' => 'destination'])->save();
    $operation->setAttribute('parent_operation_id', $destination->id);

    $context = app(EnvironmentSyncMaterial::class)->context($operation);
    expect($context->nodeId)->toBe($transfer->destination_node_id)->and($context->path)->toBe($transfer->destination_path);
    $destination->update(['input' => [...$destination->input, 'transfer_id' => 'substituted']]);
    expect(fn () => app(EnvironmentSyncMaterial::class)->context($operation))
        ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('operation.execution_forbidden'));
});

function environmentRouteMaterial(): array
{
    $operation = environmentMaterialOperation();
    $instance = Instance::query()->findOrFail($operation->target_id);
    $route = $instance->authoritativeRoute();
    $candidate = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id,
        'domain' => 'candidate.example.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending',
        'replaces_route_id' => $route->id, 'replacement_step' => 'reserved']);
    $candidate->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $parent = Operation::query()->create(['kind' => 'route.update', 'target_type' => 'route', 'target_id' => $route->id,
        'node_id' => $instance->node_id, 'status' => 'running', 'sequence' => 1,
        'input' => ['route_id' => $route->id, 'candidate_route_id' => $candidate->id, 'target_instance_ids' => [$instance->id]]]);
    $operation->setAttribute('parent_operation_id', $parent->id);
    $operation->input = [...$operation->input, 'route_domain' => 'candidate'];

    return [$operation, $instance, $route, $candidate, $parent];
}

it('renders the exact admitted candidate and authoritative domains without persisting values', function (): void {
    [$operation, $instance, $route, $candidate] = environmentRouteMaterial();
    $instance->environmentValues()->create(['env_key' => 'APP_URL', 'env_value' => 'https://{{instance.domain}}']);
    $preflight = Mockery::mock(InstanceOperationPreflight::class);
    $preflight->shouldReceive('assertEnvironmentWritable')->twice();
    app()->instance(InstanceOperationPreflight::class, $preflight);
    $material = app(EnvironmentSyncMaterial::class);
    expect($material->render($operation, false)['contents'])->toContain('https://candidate.example.test');
    $operation->input = [...$operation->input, 'route_domain' => 'authoritative'];
    expect($material->render($operation, false)['contents'])->toContain('https://proof.example.test');
    $route->update(['status' => 'retiring']);
    $candidate->update(['status' => 'activating']);
    expect($material->context($operation)->routeId)->toBe($candidate->id)->and($operation->state)->toBeNull();
});

it('keeps web-root rollback URL-only without reading or rewriting main environment material', function (): void {
    [$operation, , $route, , $parent] = environmentRouteMaterial();
    $route->update(['web_root' => 'previous/public']);
    $parent->update(['input' => [...$parent->input, 'candidate_route_id' => null]]);
    $operation->input = [...$operation->input, 'route_domain' => 'authoritative'];
    $operation->setAttribute('child_key', 'synchronize-web-root-urls.rollback.'.$operation->target_id);
    $preflight = Mockery::mock(InstanceOperationPreflight::class);
    $preflight->shouldNotReceive('readWritableEnvironment', 'assertEnvironmentWritable');
    app()->instance(InstanceOperationPreflight::class, $preflight);
    $descriptors = app(EnvironmentSyncExecution::class)->context($operation)['descriptors'];
    expect(array_column($descriptors, 'key'))->not->toContain('environment.write', 'environment.testing');
});

it('keeps retained environment synchronization synchronous with no child admission', function (): void {
    $operation = environmentMaterialOperation();
    $instance = Instance::query()->findOrFail($operation->target_id);
    $preflight = Mockery::mock(InstanceOperationPreflight::class);
    $preflight->shouldReceive('readWritableEnvironment')->once()->andReturn('API_TOKEN=old');
    app()->instance(InstanceOperationPreflight::class, $preflight);
    $writer = Mockery::mock(InstanceEnvironmentWriter::class);
    $writer->shouldReceive('write')->once()->andReturn(InstanceEnvironmentWriteResult::changed());
    app()->instance(InstanceEnvironmentWriter::class, $writer);
    $result = app(SynchronizeInstanceEnvironmentAction::class)->execute($instance);
    expect($result->changed)->toBeTrue()->and(Operation::query()->count())->toBe(0);
});

function createEnvironmentMaterial(): array
{
    $source = dev935_instance('create-environment');
    $instance = Instance::query()->create(['project_id' => $source->project_id, 'node_id' => $source->node_id,
        'name' => 'creating', 'checkout_path' => '/fast/apps/create-environment/creating', 'source_layout' => 'checkout',
        'status' => 'source_resolved', 'provisioning_step' => 'php-selected', 'source_is_laravel' => false,
        'source_prepare_id' => '11111111-1111-4111-8111-111111111111', 'branch' => 'creating', 'starting_commit' => str_repeat('a', 40)]);
    $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id,
        'generation_basis_node_id' => $instance->node_id, 'domain' => 'creating.example.test', 'provenance' => 'generated', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $parent = Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'status' => 'running', 'input' => ['instance_id' => $instance->id,
            'project_id' => $instance->project_id, 'checkout' => $instance->checkout_path, 'prepare_id' => $instance->source_prepare_id,
            'route_id' => $route->id, 'route_domain' => $route->domain]]);
    $child = new Operation(['kind' => 'instance.sync_environment', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'input' => ['instance_id' => $instance->id]]);
    $child->setAttribute('parent_operation_id', $parent->id);

    return [$child, $instance, $parent, $route];
}

it('refuses a resolved create source without its live recorded owner and placement', function (string $change): void {
    [$child, $instance, $parent, $route] = createEnvironmentMaterial();
    match ($change) {
        'missing parent' => $child->setAttribute('parent_operation_id', null),
        'wrong kind' => $parent->update(['kind' => 'instance.deploy']),
        'queued parent' => $parent->update(['status' => 'queued']),
        'failed parent' => $parent->update(['status' => 'failed']),
        'cancelled parent' => $parent->update(['status' => 'cancelled']),
        'succeeded parent' => $parent->update(['status' => 'succeeded']),
        'foreign target' => $parent->update(['target_id' => $instance->id + 1]),
        'foreign node' => $parent->update(['node_id' => dev935_instance('foreign-create-owner')->node_id]),
        'foreign project' => $parent->update(['input' => [...$parent->input, 'project_id' => $instance->project_id + 1]]),
        'changed checkout' => $parent->update(['input' => [...$parent->input, 'checkout' => '/fast/apps/other']]),
        'another preparation' => $parent->update(['input' => [...$parent->input, 'prepare_id' => 'another-attempt']]),
        'changed route' => $parent->update(['input' => [...$parent->input, 'route_id' => $route->id + 1]]),
        'changed domain' => $parent->update(['input' => [...$parent->input, 'route_domain' => 'changed.example.test']]),
        'retiring route' => $route->update(['status' => 'retiring']),
        'unfinished source' => $instance->update(['status' => 'checkout_prepared']),
    };
    $preflight = Mockery::mock(InstanceOperationPreflight::class);
    $preflight->shouldNotReceive('readWritableEnvironment', 'assertEnvironmentWritable');
    app()->instance(InstanceOperationPreflight::class, $preflight);

    expect(fn () => app(EnvironmentSyncMaterial::class)->render($child, false))->toThrow(ResourceOperationException::class);
})->with(['missing parent', 'wrong kind', 'queued parent', 'failed parent', 'cancelled parent', 'succeeded parent',
    'foreign target', 'foreign node', 'foreign project', 'changed checkout', 'another preparation', 'changed route', 'changed domain', 'retiring route', 'unfinished source']);

it('rechecks the create parent before projecting an environment write receipt', function (): void {
    [$child, $instance, $parent] = createEnvironmentMaterial();
    $context = app(EnvironmentSyncMaterial::class)->context($child);
    $parent->update(['status' => 'failed']);

    expect(fn () => app(InstanceEnvironmentStore::class)->recordSynchronizedKeys($context, ['APP_URL']))
        ->toThrow(ResourceOperationException::class);
    expect($instance->refresh()->environment_owned_keys)->toBeNull();
});

it('keeps the recorded create owner when its reserved Route becomes authoritative', function (): void {
    [$child, $instance, , $route] = createEnvironmentMaterial();
    $context = app(EnvironmentSyncMaterial::class)->context($child);
    $route->update(['status' => 'active']);

    app(InstanceEnvironmentStore::class)->recordSynchronizedKeys($context, ['APP_URL']);
    expect($instance->refresh()->environment_owned_keys)->toBe(['APP_URL']);
    expect(app(EnvironmentSyncMaterial::class)->context($child)->samePlacement($context))->toBeTrue();
});
