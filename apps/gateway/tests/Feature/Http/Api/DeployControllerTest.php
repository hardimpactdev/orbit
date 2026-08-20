<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\DeployStep;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\OperationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const DEPLOY_API_CALLER_WG_IP = '10.6.0.89';

/**
 * @param  list<string>  $permissions
 * @return array{caller: Node, node: Node, app: App, instance: Instance}
 */
function createDeployApiFixture(string $executionContext, array $permissions): array
{
    $node = createTestAppHostNode([
        'name' => 'app-prod-1',
        'host' => '10.6.0.7',
    ], 'app-prod');

    $app = App::factory()->create([
        'name' => 'docs',
    ]);
    $instance = Instance::factory()->create([
        'app_id' => $app->id,
        'name' => 'production',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/srv/docs',
            document_root: 'public',
            domain: 'docs.example.com',
        ),
    ]);

    $caller = Node::factory()->create([
        'name' => "deploy-api-{$executionContext}",
        'status' => 'active',
        'wireguard_address' => DEPLOY_API_CALLER_WG_IP,
    ]);

    if ($executionContext === 'app-dev') {
        NodeRoleAssignment::factory()->create([
            'node_id' => $caller->id,
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
    }

    NodeAccess::query()->create([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $node->id,
        'permissions' => $permissions,
        'custom_permissions' => [],
    ]);

    return compact('caller', 'node', 'app', 'instance');
}

it('lists deployment steps for a caller with deploy read on the app node', function (): void {
    createDeployApiFixture('control', ['deploy:read']);

    $response = $this->call(
        'GET',
        '/api/deploy/steps',
        [
            'instance' => 'docs',
        ],
        [],
        [],
        [
            'REMOTE_ADDR' => DEPLOY_API_CALLER_WG_IP,
        ],
    );

    $response
        ->assertOk()
        ->assertJsonPath('success.data.steps', [])
        ->assertJsonPath('success.meta.app', 'docs')
        ->assertJsonPath('success.meta.instance', 'production')
        ->assertJsonPath('success.meta.count', 0);
});

it('denies deployment writes without deploy step before side effects', function (): void {
    ['node' => $node] = createDeployApiFixture('control', ['deploy:read']);

    $response = $this->call(
        'POST',
        '/api/deploy/steps',
        [
            'instance' => 'docs',
            'command' => 'php artisan migrate --force',
        ],
        [],
        [],
        [
            'REMOTE_ADDR' => DEPLOY_API_CALLER_WG_IP,
        ],
    );

    $response
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed')
        ->assertJsonPath('error.meta.missing_permission', 'deploy:step')
        ->assertJsonPath('error.meta.serving_node', $node->name);

    expect(DeployStep::query()->count())->toBe(0);
});

it('allows app-dev role callers when they hold the deployment grant', function (): void {
    createDeployApiFixture('app-dev', ['deploy:step']);

    $response = $this->call(
        'POST',
        '/api/deploy/steps',
        [
            'instance' => 'docs',
            'command' => 'php artisan migrate --force',
            'title' => 'Run migrations',
        ],
        [],
        [],
        [
            'REMOTE_ADDR' => DEPLOY_API_CALLER_WG_IP,
        ],
    );

    $response
        ->assertOk()
        ->assertJsonPath('success.data.step.app', 'docs')
        ->assertJsonPath('success.data.step.instance', 'production')
        ->assertJsonPath('success.data.step.title', 'Run migrations')
        ->assertJsonPath('success.meta.action', 'created');

    $step = DeployStep::query()->firstOrFail();
    $entry = Activity::query()->latest('id')->firstOrFail();

    expect($entry->event)
        ->toBe('api:POST /deploy/steps')
        ->and($entry->subject_type)
        ->toBe(DeployStep::class)
        ->and($entry->subject_id)
        ->toBe($step->id)
        ->and(json_encode($entry->properties->all(), JSON_THROW_ON_ERROR))
        ->not->toContain('php artisan migrate');
});

it('declares the exact activity contract for all six deploy actions', function (): void {
    createDeployApiFixture('control', ['deploy:read', 'deploy:step', 'deploy:run']);
    $server = ['REMOTE_ADDR' => DEPLOY_API_CALLER_WG_IP];

    $this->call('POST', '/api/deploy/steps', ['instance' => 'docs'], server: $server)->assertBadRequest();
    $this->call('GET', '/api/deploy/steps', ['instance' => 'docs'], server: $server)->assertOk();
    $this->call('DELETE', '/api/deploy/steps/999', ['instance' => 'docs'], server: $server)->assertBadRequest();
    $this->call('POST', '/api/deploy/run', ['instance' => 'docs'], server: $server)->assertAccepted();

    $runActivity = Activity::query()->where('event', 'api:POST /deploy/run')->firstOrFail();
    $runProperties = $runActivity->properties->all();

    expect($runActivity->subject_type)
        ->toBeNull()
        ->and($runProperties)
        ->toMatchArray([
            'app' => 'docs',
            'instance' => 'production',
            'status' => 'queued',
        ])
        ->and($runProperties)
        ->not->toHaveKey('run_id');

    $this->call('GET', '/api/deploy/history', ['instance' => 'docs'], server: $server)->assertOk();
    $this->call('GET', '/api/deploy/log/not-a-run', ['instance' => 'docs'], server: $server)->assertBadRequest();

    expect(Activity::query()->orderBy('id')->pluck('event')->all())->toBe([
        'api:POST /deploy/steps',
        'api:GET /deploy/steps',
        'api:DELETE /deploy/steps/{step}',
        'api:POST /deploy/run',
        'api:GET /deploy/history',
        'api:GET /deploy/log/{run}',
    ]);
});

it('returns canonical destructive consent metadata before removing a deployment step', function (): void {
    ['instance' => $instance] = createDeployApiFixture('control', ['deploy:step']);
    $step = DeployStep::query()->create([
        'instance_id' => $instance->id,
        'title' => 'Run migrations',
        'command' => 'php artisan migrate --force',
        'sort_order' => 10,
        'timeout_seconds' => 600,
    ]);

    $response = $this->call(
        'DELETE',
        '/api/deploy/steps/Run%20migrations',
        [
            'instance' => 'docs',
        ],
        [],
        [],
        [
            'REMOTE_ADDR' => DEPLOY_API_CALLER_WG_IP,
        ],
    );

    $response
        ->assertBadRequest()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.field', 'force')
        ->assertJsonPath('error.meta.reason', 'destructive_consent_required');

    expect($step->fresh())->not->toBeNull();
});

it('authorizes deployment against the concrete app instance node', function (): void {
    $instanceNode = createTestAppHostNode(['name' => 'production-instance-node'], role: 'app-prod');
    $app = App::factory()->create([
        'name' => 'billing',
    ]);
    Instance::factory()->create([
        'app_id' => $app->id,
        'name' => 'production',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $instanceNode->id,
            node: $instanceNode->name,
            path: '/srv/billing',
            document_root: 'public',
            domain: 'billing.example.com',
        ),
    ]);
    $caller = Node::factory()->create([
        'name' => 'deployment-operator',
        'status' => 'active',
        'wireguard_address' => DEPLOY_API_CALLER_WG_IP,
    ]);
    NodeAccess::query()->create([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $instanceNode->id,
        'permissions' => ['deploy:read'],
        'custom_permissions' => [],
    ]);

    $response = $this->call(
        'GET',
        '/api/deploy/steps',
        ['instance' => 'billing.production'],
        [],
        [],
        ['REMOTE_ADDR' => DEPLOY_API_CALLER_WG_IP],
    );

    $response
        ->assertOk()
        ->assertJsonPath('success.meta.app', 'billing')
        ->assertJsonPath('success.meta.instance', 'production');
});

it('starts deploy run as a durable operation for WebSocket progress', function (): void {
    ['caller' => $caller, 'node' => $node] = createDeployApiFixture('control', ['deploy:run']);

    $response = $this->call(
        'POST',
        '/api/deploy/run',
        [
            'instance' => 'docs',
        ],
        [],
        [],
        [
            'REMOTE_ADDR' => DEPLOY_API_CALLER_WG_IP,
        ],
    );

    $response
        ->assertAccepted()
        ->assertJsonStructure([
            'success' => [
                'data' => [
                    'operation' => ['uuid', 'stream_descriptor_url', 'events_url'],
                ],
            ],
        ]);

    $operation = OperationRun::query()->findOrFail($response->json('success.data.operation.uuid'));

    expect($operation->operation_type)
        ->toBe('deploy.run')
        ->and($operation->caller_node_id)
        ->toBe($caller->id)
        ->and($operation->target_node_id)
        ->toBe($node->id);
});
