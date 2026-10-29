<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\CloneCandidateSource;
use App\Domain\Instances\InstanceCloneCandidateInspector;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Activity;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->caller = clone_api_node('clone-api-caller');
    $this->caller->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->candidateNode = clone_api_node('clone-api-candidate');
    $this->candidateNode->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $this->destinationNode = clone_api_node('clone-api-destination');
    $project = Project::query()->create([
        'name' => 'Clone API',
        'slug' => 'clone-api',
        'repository_url' => 'https://example.test/clone-api.git',
        'default_branch' => 'main',
    ]);
    $this->candidate = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $this->candidateNode->id,
        'name' => 'candidate',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/clone-api/candidate',
        'branch' => 'main',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
    $this->cloneUrl = "/api/v1/instances/{$this->candidate->id}/clone";
});

it('returns 422 for malformed duplicate unknown or forbidden clone input before mutation', function (
    string $body,
): void {
    $sentinel = 'CLONE_API_INPUT_SENTINEL';

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            'POST',
            $this->cloneUrl,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: str_replace('__SENTINEL__', $sentinel, $body),
        );

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect(Instance::query()->count())
        ->toBe(1)
        ->and(json_encode(Activity::query()->sole()->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain($sentinel);
})->with([
    'malformed JSON' => ['{"node_id":'],
    'array body' => ['[]'],
    'duplicate member' => ['{"node_id":1,"node_id":2,"name":"target","preview_name":"preview"}'],
    'unknown member' => ['{"node_id":1,"name":"target","preview_name":"preview","unknown":"__SENTINEL__"}'],
    'target App' => ['{"node_id":1,"name":"target","preview_name":"preview","project_id":"__SENTINEL__"}'],
    'commit SHA' => ['{"node_id":1,"name":"target","preview_name":"preview","commit_sha":"__SENTINEL__"}'],
    'production user' => ['{"node_id":1,"name":"target","preview_name":"preview","user":"__SENTINEL__"}'],
    'destination path' => ['{"node_id":1,"name":"target","preview_name":"preview","destination_path":"__SENTINEL__"}'],
    'missing required members' => ['{}'],
    'malformed Node ID' => ['{"node_id":"bad","name":"target","preview_name":"preview"}'],
    'signed Node ID' => ['{"node_id":"+3","name":"target","preview_name":"preview"}'],
    'boolean Node ID' => ['{"node_id":true,"name":"target","preview_name":"preview"}'],
    'invalid target name' => ['{"node_id":1,"name":"Not Normalized","preview_name":"preview"}'],
    'invalid preview name' => ['{"node_id":1,"name":"target","preview_name":"../preview"}'],
    'invalid branch' => ['{"node_id":1,"name":"target","preview_name":"preview","branch":"../main"}'],
    'relative SQLite path' => ['{"node_id":1,"name":"target","preview_name":"preview","sqlite_source_path":"database.sqlite"}'],
]);

it('returns 403 for malformed destination input when the caller lacks candidate Node access', function (
    mixed $nodeId,
): void {
    $caller = clone_api_node('clone-api-unprivileged-caller');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson($this->cloneUrl, [
            'node_id' => $nodeId,
            'name' => 'target',
            'preview_name' => 'preview',
        ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required')
        ->assertJsonPath('error.details.serving_node.id', $this->candidateNode->id);

    expect(Instance::query()->count())->toBe(1);
})->with([
    'signed integer string' => '+3',
    'boolean' => true,
]);

it('returns 403 before cloning when the caller lacks destination Node access', function (): void {
    $caller = clone_api_node('clone-api-direct-caller');
    $caller->accessibleNodes()->attach($this->candidateNode);
    $sqliteSourcePath = '/srv/orbit/apps/clone-api/candidate/CLONE_SQLITE_PATH_SENTINEL.sqlite';

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson($this->cloneUrl, [
            'node_id' => $this->destinationNode->id,
            'name' => 'target',
            'preview_name' => 'preview',
            'branch' => 'release',
            'sqlite_source_path' => $sqliteSourcePath,
        ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required')
        ->assertJsonPath('error.details.serving_node.id', $this->destinationNode->id);

    $activity = Activity::query()->where('command', 'instance:clone')->sole();
    expect(Instance::query()->count())
        ->toBe(1)
        ->and($activity->properties?->get('input'))
        ->toBe([
            'node_id' => $this->destinationNode->id,
            'name' => 'target',
            'preview_name' => 'preview',
            'branch' => 'release',
            'sqlite_selected' => true,
        ])
        ->and(json_encode($activity->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain($sqliteSourcePath, 'sqlite_source_path');
});

it('returns 404 for a missing candidate before clone execution', function (): void {
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson('/api/v1/instances/999999/clone', [
            'node_id' => $this->destinationNode->id,
            'name' => 'target',
            'preview_name' => 'preview',
        ])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'http.404');

    expect(Instance::query()->count())->toBe(1);
});

it('returns a replayable clone Operation and reserves its private Route before any provisioning', function (bool $clustered): void {
    app()->instance(OperationKinds::class, new OperationKinds(app(), [
        ...OperationKinds::definitions(), ...require app_path('Domain/Instances/lifecycle-operation-kinds.php'),
    ]));
    $this->candidate->project->update(['type' => 'laravel-app', 'root' => 'public']);
    $this->candidate->environmentValues()->create(['env_key' => 'REDIS_URL', 'env_value' => 'redis://user:clone-secret@example.test']);
    $this->destinationNode->update(['tld' => 'prod.orbit']);
    $this->destinationNode->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $cluster = null;
    if ($clustered) {
        $cluster = Cluster::query()->create(['name' => 'clone-cluster', 'tld' => 'cluster.orbit', 'state' => ClusterState::Active]);
        $this->destinationNode->update(['cluster_id' => $cluster->id]);
        $router = clone_api_node('clone-router');
        $router->update(['cluster_id' => $cluster->id]);
        $router->roles()->create(['cluster_id' => $cluster->id, 'role' => RoleName::Router, 'status' => LifecycleStatus::Active]);
    }
    $inspector = Mockery::mock(InstanceCloneCandidateInspector::class);
    $inspector->shouldReceive('inspect')->once()->andReturn(new CloneCandidateSource($this->candidate->id,
        'development', $this->candidate->checkout_path, 'orbit', 'main', str_repeat('a', 40), $this->candidateNode));
    app()->instance(InstanceCloneCandidateInspector::class, $inspector);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);
    Queue::fake();
    $body = ['node_id' => $this->destinationNode->id, 'name' => 'preview', 'preview_name' => 'shop.com'];
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])->withHeader('Idempotency-Key', 'clone-admission');
    $id = $this->postJson($this->cloneUrl, $body)->assertAccepted()->assertJsonPath('data.kind', 'instance.clone')
        ->assertJsonPath('data.status', 'queued')->json('data.id');
    $this->postJson($this->cloneUrl, $body)->assertAccepted()->assertJsonPath('data.id', $id);
    $operation = Operation::query()->findOrFail($id);
    $target = Instance::query()->findOrFail($operation->target_id);
    $route = $target->routes()->sole();
    expect($target->provisioning_step)->toBe('clone-reserved')->and($target->clone_completed_at)->toBeNull();
    expect($route->domain)->toBe('shop.com.prod.orbit')->and($route->cluster_id)->toBe($cluster?->id)
        ->and($route->node_id)->toBe($clustered ? null : $this->destinationNode->id)
        ->and($route->targets()->sole()->instance_id)->toBe($target->id);
    expect(json_encode($operation->toArray()))->not->toContain('clone-secret', 'redis://');
    Queue::assertNothingPushed();
})->with([false, true]);

function clone_api_node(string $name): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "{$name}.example.test",
        'wireguard_ip' => '10.44.10.'.(Node::query()->count() + 2),
        'user' => 'orbit',
    ]);
}
