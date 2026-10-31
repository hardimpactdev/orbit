<?php

declare(strict_types=1);

use App\Models\InstanceDeployment;
use Tests\Support\Orb220DeploymentApiFixture;

beforeEach(function (): void {
    $this->fixture = Orb220DeploymentApiFixture::create();
    $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip]);
});

it('lists recorded deployments newest first and returns phases only on the detail', function (): void {
    $instance = $this->fixture->instance;
    InstanceDeployment::query()->create(['instance_id' => $instance->id, 'kind' => 'deploy', 'status' => 'failed', 'started_at' => now()->subMinute()]);
    $deployment = InstanceDeployment::query()->create([
        'instance_id' => $instance->id, 'kind' => 'deploy', 'status' => 'succeeded', 'branch' => 'main',
        'release' => 'fresh', 'commit' => str_repeat('a', 40), 'started_at' => now()->subSeconds(5), 'finished_at' => now(),
        'triggered_by' => $this->fixture->caller->name,
        'events' => [['type' => 'phase', 'phase' => 'activation', 'status' => 'succeeded']],
    ]);

    $this->getJson("/api/v1/instances/{$instance->id}/deployments")->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $deployment->id)
        ->assertJsonPath('data.0.status', 'succeeded')->assertJsonMissingPath('data.0.events');
    $show = $this->getJson("/api/v1/deployments/{$deployment->id}")->assertOk()
        ->assertJsonPath('data.id', $deployment->id)->assertJsonPath('data.status', 'succeeded');
    expect(array_column($show->json('data.events'), 'type'))->toContain('phase');
});

it('returns a recorded failure with its failed step and error code', function (): void {
    $deployment = InstanceDeployment::query()->create([
        'instance_id' => $this->fixture->instance->id, 'kind' => 'deploy', 'status' => 'failed',
        'started_at' => now(), 'finished_at' => now(), 'failed_step' => 'preparation', 'error_code' => 'deployment.prepare_failed',
    ]);

    $this->getJson("/api/v1/deployments/{$deployment->id}")->assertOk()
        ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failed_step', 'preparation')
        ->assertJsonPath('data.error_code', 'deployment.prepare_failed');
});

it('returns a recorded rollback as its own deployment row', function (): void {
    $deployment = InstanceDeployment::query()->create([
        'instance_id' => $this->fixture->instance->id, 'kind' => 'rollback', 'status' => 'succeeded',
        'started_at' => now(), 'finished_at' => now(), 'triggered_by' => $this->fixture->caller->name,
    ]);

    $this->getJson("/api/v1/deployments/{$deployment->id}")->assertOk()
        ->assertJsonPath('data.kind', InstanceDeployment::Rollback)->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.triggered_by', $this->fixture->caller->name);
});

it('answers an unknown deployment id and an instance with no history', function (): void {
    $this->getJson('/api/v1/deployments/999999')->assertNotFound()->assertJsonPath('error.code', 'http.404');
    $this->getJson("/api/v1/instances/{$this->fixture->instance->id}/deployments")->assertOk()->assertJsonPath('data', []);
});
