<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceDeployProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Models\Operation;
use App\Models\ProjectDevelopmentDeployStep;

beforeEach(function (): void {
    $this->instance = dev935_instance();
    dev935_steps($this->instance, [new DevelopmentDeployStep('install', 'private deploy command', 60, true)]);
    $this->operation = Operation::query()->create([
        'kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->instance->node_id, 'status' => OperationStatus::Queued,
        'triggered_by' => 'operator',
        'input' => ['platform' => 'linux', 'architecture' => 'amd64', 'instance_id' => $this->instance->id,
            'deployment' => app(InstanceDeploymentIntent::class)->snapshot($this->instance)],
    ]);
});

it('returns the bound safe deployment input without private commands or environment', function (): void {
    $this->instance->environmentValues()->create(['env_key' => 'API_TOKEN', 'env_value' => 'private deployment token']);
    $input = app(InstanceDeployProjection::class)->input($this->operation)['intent'];
    expect($input['instance_id'])->toBe($this->instance->id)
        ->and($input['project_id'])->toBe($this->instance->project_id)
        ->and($input['deployment']['node_user'])->toBe($this->instance->node->user)
        ->and($input['deployment']['steps'][0]['command_digest'])->toBe(hash('sha256', 'private deploy command'))
        ->and($input['release_name'])->toBe(InstanceDeploymentIntent::releaseName($this->operation))
        ->and($input['commit'])->toBe('')
        ->and(json_encode($input))->not->toContain('private deploy command', 'private deployment token');
});

it('keeps admitted accounts and programs immutable while Swamp owns fresh runtime checks', function (): void {
    $before = app(InstanceDeployProjection::class)->input($this->operation)['intent'];
    $this->instance->project->update(['repository_url' => 'https://github.com/example/changed.git']);
    $this->instance->node->update(['user' => 'changed']);
    $this->instance->update(['checkout_path' => '/changed/checkout']);
    ProjectDevelopmentDeployStep::query()->where('project_id', $this->instance->project_id)->update(['command' => 'changed command', 'timeout_seconds' => 61]);
    expect(app(InstanceDeployProjection::class)->input($this->operation->fresh())['intent'])->toBe($before)
        ->and($before)->not->toHaveKey('agent_sha256')
        ->and($before['node_id'])->toBe($this->instance->node_id)
        ->and($before['platform'])->toBe('linux')->and($before['architecture'])->toBe('amd64');
});

it('publishes captured parent runtime intent without protected descriptor fields', function (): void {
    $input = $this->operation->input;
    $input['runtime_definitions'] = ['processes' => [], 'schedules' => [], 'restart' => []];
    $input['definitions_material'] = ['$secret' => 'instance.deployment', 'id' => $this->instance->id];
    $this->operation->update(['input' => $input]);
    $safe = app(InstanceDeployProjection::class)->input($this->operation)['intent'];
    expect($safe['runtime_definitions'])->toBe($input['runtime_definitions'])
        ->and($safe)->not->toHaveKey('definitions_material');
});
