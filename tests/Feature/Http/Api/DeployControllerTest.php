<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\DeployStep;
use App\Models\Node;
use App\Models\NodeAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const DEPLOY_API_CALLER_WG_IP = '10.6.0.89';

/**
 * @param  list<string>  $permissions
 * @return array{caller: Node, node: Node, app: App}
 */
function createDeployApiFixture(string $callerRole, array $permissions): array
{
    $node = createTestAppHostNode([
        'name' => 'app-prod-1',
        'role' => 'app',
        'environment' => 'production',
        'host' => '10.6.0.7',
    ], 'app-production');

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'environment' => 'production',
        'path' => '/srv/docs',
    ]);

    $caller = Node::factory()->create([
        'name' => "deploy-api-{$callerRole}",
        'role' => $callerRole,
        'status' => 'active',
        'wireguard_address' => DEPLOY_API_CALLER_WG_IP,
    ]);

    NodeAccess::query()->create([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $node->id,
        'permissions' => $permissions,
        'custom_permissions' => [],
    ]);

    return compact('caller', 'node', 'app');
}

it('lists deployment steps for a caller with deploy read on the app node', function (): void {
    createDeployApiFixture('control', ['deploy:read']);

    $response = $this->call('GET', '/api/deploy/steps', [
        'app' => 'docs',
    ], [], [], [
        'REMOTE_ADDR' => DEPLOY_API_CALLER_WG_IP,
    ]);

    $response->assertOk()
        ->assertJsonPath('success.data.steps', [])
        ->assertJsonPath('success.meta.app', 'docs')
        ->assertJsonPath('success.meta.count', 0);
});

it('denies deployment writes without deploy step before side effects', function (): void {
    ['node' => $node] = createDeployApiFixture('control', ['deploy:read']);

    $response = $this->call('POST', '/api/deploy/steps', [
        'app' => 'docs',
        'command' => 'php artisan migrate --force',
    ], [], [], [
        'REMOTE_ADDR' => DEPLOY_API_CALLER_WG_IP,
    ]);

    $response->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed')
        ->assertJsonPath('error.meta.missing_permission', 'deploy:step')
        ->assertJsonPath('error.meta.serving_node', $node->name);

    expect(DeployStep::query()->count())->toBe(0);
});

it('allows role app callers when they hold the deployment grant', function (): void {
    createDeployApiFixture('app', ['deploy:step']);

    $response = $this->call('POST', '/api/deploy/steps', [
        'app' => 'docs',
        'command' => 'php artisan migrate --force',
        'title' => 'Run migrations',
    ], [], [], [
        'REMOTE_ADDR' => DEPLOY_API_CALLER_WG_IP,
    ]);

    $response->assertOk()
        ->assertJsonPath('success.data.step.app', 'docs')
        ->assertJsonPath('success.data.step.title', 'Run migrations')
        ->assertJsonPath('success.meta.action', 'created');
});
