<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Models\InstanceDeployment;
use App\Models\Route;
use Tests\Support\Orb220DeploymentApiFixture;
use Tests\Support\ProductionSmokeRoute;

it('reads the actual retained selection with successful history and project routes without dispatch', function (): void {
    $fixture = Orb220DeploymentApiFixture::create();
    $instance = $fixture->instance;
    $history = InstanceDeployment::query()->create(['instance_id' => $instance->id, 'release' => 'initial',
        'commit' => str_repeat('a', 40), 'kind' => 'rollback', 'status' => 'succeeded', 'started_at' => now()]);
    ProductionSmokeRoute::create($instance);
    $this->withServerVariables(['REMOTE_ADDR' => $fixture->caller->wireguard_ip])->getJson('/api/v1/instances/'.$instance
        ->id.'/smoke/context')
        ->assertOk()->assertJsonPath('data.production', true)->assertJsonPath('data.base_urls', ['https://smoke.example.test'])
        ->assertJsonPath('data.selected', ['deployment_id' => $history->id, 'commit' => str_repeat('a', 40), 'release' => 'initial'])
        ->assertJsonPath('data.retained_releases', ['fresh', 'initial']);
    expect($fixture->deployment->invocations)->toBe(0);
});

it('returns no selected receipt without matching success evidence', function (string $status, string $release, string $commit): void {
    $fixture = Orb220DeploymentApiFixture::create();
    InstanceDeployment::query()->create(['instance_id' => $fixture->instance->id, 'release' => $release, 'commit' => $commit,
        'status' => $status, 'started_at' => now()]);
    $this->withServerVariables(['REMOTE_ADDR' => $fixture->caller->wireguard_ip])->getJson('/api/v1/instances/'.$fixture
        ->instance->id.'/smoke/context')
        ->assertOk()->assertJsonPath('data.selected', null);
})->with([['failed', 'initial', str_repeat('a', 40)], ['succeeded', 'other', str_repeat('a', 40)], ['succeeded', 'initial',
    str_repeat('b', 40)]]);

it('reports non production when active development roles or instance state disqualify it', function (string $change): void {
    $fixture = Orb220DeploymentApiFixture::create();
    if ($change === 'role') {
        $fixture->owner->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    } elseif ($change === 'node') {
        $fixture->owner->update(['status' => 'removing']);
    } else {
        $fixture->instance->update(['status' => 'reserved']);
    }
    $this->withServerVariables(['REMOTE_ADDR' => $fixture->caller->wireguard_ip])->getJson('/api/v1/instances/'.$fixture
        ->instance->id.'/smoke/context')
        ->assertOk()->assertJsonPath('data.production', false)->assertJsonPath('data.selected', null)
        ->assertJsonPath('data.retained_releases', []);
})->with(['role', 'node', 'instance']);

it('requires gateway access and an authenticated peer for context', function (): void {
    $fixture = Orb220DeploymentApiFixture::create();
    $url = '/api/v1/instances/'.$fixture->instance->id.'/smoke/context';
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->getJson($url)->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $fixture->owner->wireguard_ip])->getJson($url)->assertForbidden();
});

it('does not treat an unretained live release as a selected receipt', function (): void {
    $fixture = Orb220DeploymentApiFixture::create();
    $fixture->deployment->current = 'unretained';
    InstanceDeployment::query()->create(['instance_id' => $fixture->instance->id, 'release' => 'unretained',
        'commit' => str_repeat('a', 40), 'status' => 'succeeded', 'started_at' => now()]);
    $this->withServerVariables(['REMOTE_ADDR' => $fixture->caller->wireguard_ip])->getJson('/api/v1/instances/'.$fixture
        ->instance->id.'/smoke/context')
        ->assertOk()->assertJsonPath('data.selected', null);
});

it('excludes private Routes from hosted production smoke context', function (): void {
    $fixture = Orb220DeploymentApiFixture::create();
    $route = Route::query()->create([
        'project_id' => $fixture->instance->project_id,
        'node_id' => $fixture->owner->id,
        'domain' => 'smoke.orbit',
        'status' => 'pending',
        'publication' => 'private',
        'provenance' => 'explicit',
    ]);
    $route->targets()->create(['instance_id' => $fixture->instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => $fixture->caller->wireguard_ip])
        ->getJson('/api/v1/instances/'.$fixture->instance->id.'/smoke/context')
        ->assertOk()->assertJsonPath('data.base_urls', []);
});
