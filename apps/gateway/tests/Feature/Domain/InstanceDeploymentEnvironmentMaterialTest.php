<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\InstanceDeploymentEnvironmentMaterial;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;

beforeEach(function (): void {
    $this->instance = dev935_instance();
    $this->instance->update(['provisioning_step' => 'active', 'source_is_laravel' => false, 'environment_owned_keys' => ['RETIRED']]);
    $this->instance->environmentValues()->create(['env_key' => 'API_TOKEN', 'env_value' => 'synthetic-private-token']);
    Instance::query()->whereKey($this->instance->id)->update(['environment_owned_keys' => json_encode(['RETIRED'])]);
    $this->operation = Operation::query()->create([
        'kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->instance->node_id, 'status' => OperationStatus::Running,
        'triggered_by' => 'operator',
        'input' => ['instance_id' => $this->instance->id, 'deployment' => app(InstanceDeploymentIntent::class)->snapshot($this->instance)],
    ]);
});

it('reads deployment environment material from records without running a Node command', function (): void {
    $material = app(InstanceDeploymentEnvironmentMaterial::class);
    expect($material->target($this->operation))->toBe([
        'base' => $this->instance->checkout_path, 'user' => $this->instance->node->user,
        'environment' => 'development', 'domain' => null,
    ])->and(json_encode($material->target($this->operation)))->not->toContain('synthetic-private-token');
    $private = $material->read($this->operation);
    expect($private['values'])->toBe(['API_TOKEN' => 'synthetic-private-token'])
        ->and($private['owned_keys'])->toBe(['RETIRED'])->and($private['testing'])->toBeNull()
        ->and($private['required_capacity'])->toBeGreaterThan(0);
});

it('refuses private deployment material after target changes cancellation or completion', function (string $change): void {
    match ($change) {
        'node' => $this->operation->update(['node_id' => null]),
        'command' => $this->instance->node->update(['user' => 'foreign']),
        'cancel' => $this->operation->update(['cancel_requested_at' => now()]),
        'finished' => $this->operation->update(['status' => OperationStatus::Succeeded]),
    };
    expect(fn () => app(InstanceDeploymentEnvironmentMaterial::class)->read($this->operation->fresh()))->toThrow(ResourceOperationException::class);
})->with(['node', 'command', 'cancel', 'finished']);
