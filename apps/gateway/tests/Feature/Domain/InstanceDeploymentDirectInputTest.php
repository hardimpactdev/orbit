<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\InstanceDeploymentDirectInput;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\ProjectDevelopmentDeployStep;

beforeEach(function (): void {
    $this->instance = dev935_instance();
    dev935_steps($this->instance, [new DevelopmentDeployStep('install', 'private deploy command', 60, true)]);
    $this->operation = Operation::query()->create([
        'kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->instance->node_id, 'status' => OperationStatus::Queued,
        'triggered_by' => 'operator',
        'input' => ['instance_id' => $this->instance->id,
            'deployment' => app(InstanceDeploymentIntent::class)->snapshot($this->instance)],
    ]);
});

it('returns the bound safe deployment input without private commands or environment', function (): void {
    $this->instance->environmentValues()->create(['env_key' => 'API_TOKEN', 'env_value' => 'private deployment token']);
    $input = app(InstanceDeploymentDirectInput::class)->input($this->operation);
    expect($input['instance_id'])->toBe($this->instance->id)
        ->and($input['project_id'])->toBe($this->instance->project_id)
        ->and($input['deployment']['node_user'])->toBe($this->instance->node->user)
        ->and($input['deployment']['steps'][0]['command_digest'])->toBe(hash('sha256', 'private deploy command'))
        ->and($input['release_name'])->toBe(InstanceDeploymentIntent::releaseName($this->operation))
        ->and($input['commit'])->toBeNull()
        ->and(json_encode($input))->not->toContain('private deploy command', 'private deployment token');
});

it('refuses changed deployment placement identity list and account before an effect', function (string $change): void {
    match ($change) {
        'node' => $this->operation->update(['node_id' => null]),
        'kind' => $this->operation->update(['kind' => 'instance.rollback']),
        'project' => $this->instance->project->update(['repository_url' => 'https://github.com/example/changed.git']),
        'home' => $this->instance->update(['checkout_path' => '/changed/checkout']),
        'node account' => $this->instance->node->update(['user' => 'changed']),
        'command' => ProjectDevelopmentDeployStep::query()->where('project_id', $this->instance->project_id)->update(['command' => 'changed command']),
        'timeout' => ProjectDevelopmentDeployStep::query()->where('project_id', $this->instance->project_id)->update(['timeout_seconds' => 61]),
        'required' => ProjectDevelopmentDeployStep::query()->where('project_id', $this->instance->project_id)->update(['required' => false]),
    };
    expect(fn () => app(InstanceDeploymentDirectInput::class)->input($this->operation->fresh()))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('deployment.identity_changed'));
})->with(['node', 'kind', 'project', 'home', 'node account', 'command', 'timeout', 'required']);

it('accepts map key order without weakening ordered step bindings', function (): void {
    $input = $this->operation->input;
    $input['deployment'] = array_reverse($input['deployment'], true);
    $this->operation->update(['input' => $input]);
    expect(app(InstanceDeploymentDirectInput::class)->input($this->operation)['deployment']['steps'][0]['name'])->toBe('install');
});
