<?php

declare(strict_types=1);

use App\Domain\AgentView\AgentStateView;
use App\Http\Middleware\RequireNodeAccess;
use App\Http\Requests\Instances\RegisterInstanceRequest;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(LazilyRefreshDatabase::class);

it('refuses new host registration before checkout observation or any record', function (): void {
    $this->withoutMiddleware(RequireNodeAccess::class);
    $node = Node::withoutEvents(fn () => Node::query()->create([
        'name' => 'development', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '10.44.0.7', 'wireguard_ip' => '10.44.0.7',
        'settings' => ['apps' => ['path' => '/home/orbit/apps']],
    ]));
    $node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Tool', 'slug' => 'tool', 'type' => 'node-package',
        'repository_url' => 'https://github.com/example/tool.git', 'default_branch' => 'main', 'root' => '.']);
    app()->instance(AgentStateView::class, Mockery::mock(AgentStateView::class));
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->postJson('/api/v1/instances/register', ['project_id' => $project->id, 'source_path' => '/home/orbit/apps/tool/default'])
        ->assertStatus(409)->assertJsonPath('error.code', 'instance.container_required');

    $this->assertDatabaseCount('instances', 0);
    $this->assertDatabaseCount('routes', 0);
    $this->assertDatabaseCount('operations', 0);
});

it('refuses the removed include_worktrees field on registration', function (): void {
    $request = RegisterInstanceRequest::create('/api/v1/instances/register', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
        'source_path' => '/srv/orbit/apps/acme/main', 'project_id' => 4, 'include_worktrees' => false,
    ], JSON_THROW_ON_ERROR));

    expect(fn () => $request->validationData())->toThrow(ValidationException::class);
});

it('accepts a registration body without include_worktrees', function (): void {
    $request = RegisterInstanceRequest::create('/api/v1/instances/register', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
        'source_path' => '/srv/orbit/apps/acme/main', 'project_id' => 4,
    ], JSON_THROW_ON_ERROR));

    expect($request->validationData())->toBe(['source_path' => '/srv/orbit/apps/acme/main', 'project_id' => 4]);
});
