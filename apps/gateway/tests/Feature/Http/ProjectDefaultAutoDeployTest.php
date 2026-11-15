<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
    ]));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $this->project = Project::query()->create(['name' => 'app', 'slug' => 'app', 'repository_url' => 'https://github.com/example/app.git', 'default_branch' => 'main', 'root' => 'public']);
});

it('is on for a new Project and shows in project:show', function (): void {
    $this->getJson('/api/v1/projects/'.$this->project->id)->assertOk()->assertJsonPath('data.default_auto_deploy', true);
});

it('turns the automatic deploy of the container default off and on with project:update', function (): void {
    $this->patchJson('/api/v1/projects/'.$this->project->id, ['default_auto_deploy' => false])->assertOk()->assertJsonPath('data.default_auto_deploy', false);
    expect($this->project->refresh()->default_auto_deploy)->toBeFalse();

    $this->patchJson('/api/v1/projects/'.$this->project->id, ['default_auto_deploy' => true])->assertOk()->assertJsonPath('data.default_auto_deploy', true);
    expect($this->project->refresh()->default_auto_deploy)->toBeTrue();
});

it('refuses a value that is no boolean', function (): void {
    $this->patchJson('/api/v1/projects/'.$this->project->id, ['default_auto_deploy' => 'yes'])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['default_auto_deploy']]]);
    expect($this->project->refresh()->default_auto_deploy)->toBeTrue();
});
