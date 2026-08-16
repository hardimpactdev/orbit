<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\AppSetupStep;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const APP_SETUP_STEP_CALLER_WG_IP = '10.6.0.96';

function createAppSetupStepCallerNode(): Node
{
    return Node::factory()->create([
        'name' => 'app-setup-step-caller',
        'host' => APP_SETUP_STEP_CALLER_WG_IP,
        'wireguard_address' => APP_SETUP_STEP_CALLER_WG_IP,
    ]);
}

function grantAppSetupStepAccess(Node $caller, Node $appNode, array $permissions): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function createAppSetupStepTarget(): array
{
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    $app = App::factory()->create([
        'name' => 'docs',
    ]);
    $instance = Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/orbit/apps/'.$app->name,
            document_root: 'public',
            domain: null,
        ),
    ]);

    return [$node, $app, $instance];
}

describe('AppSetupStepController', function (): void {
    it('includes the selected instance when permission is denied', function (): void {
        [$node] = createAppSetupStepTarget();
        $caller = createAppSetupStepCallerNode();
        grantAppSetupStepAccess($caller, $node, ['instance:read']);

        $this
            ->call(
                'POST',
                '/api/instances/docs.development/setup-steps',
                ['command' => 'composer install'],
                [],
                [],
                [
                    'REMOTE_ADDR' => APP_SETUP_STEP_CALLER_WG_IP,
                    'CONTENT_TYPE' => 'application/json',
                ],
            )
            ->assertForbidden()
            ->assertJsonPath('error.meta.missing_permission', 'instance:write')
            ->assertJsonPath('error.meta.instance', 'development');
    });

    it('creates instance setup steps for authorized callers', function (): void {
        [$node] = createAppSetupStepTarget();
        $caller = createAppSetupStepCallerNode();
        grantAppSetupStepAccess($caller, $node, ['instance:write']);

        $response = $this->call(
            'POST',
            '/api/instances/docs/setup-steps',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => APP_SETUP_STEP_CALLER_WG_IP,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'command' => 'composer install',
                'timeout' => 900,
            ], JSON_THROW_ON_ERROR),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.result.action', 'added')
            ->assertJsonPath('success.data.step.app', 'docs')
            ->assertJsonPath('success.data.step.instance', 'development')
            ->assertJsonPath('success.data.step.order', 1)
            ->assertJsonPath('success.data.step.timeout_seconds', 900);
    });

    it('lists setup steps with instance read permission', function (): void {
        [$node, $app, $instance] = createAppSetupStepTarget();
        $caller = createAppSetupStepCallerNode();
        grantAppSetupStepAccess($caller, $node, ['instance:read']);
        AppSetupStep::factory()->create([
            'instance_id' => $instance->id,
            'command' => 'php artisan migrate',
            'sort_order' => 1,
        ]);

        $response = $this->call(
            'GET',
            '/api/instances/docs/setup-steps',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => APP_SETUP_STEP_CALLER_WG_IP,
            ],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.steps.0.app', 'docs')
            ->assertJsonPath('success.data.steps.0.instance', 'development')
            ->assertJsonPath('success.data.steps.0.command', 'php artisan migrate');
    });

    it('removes setup steps with destructive consent', function (): void {
        [$node, $app, $instance] = createAppSetupStepTarget();
        $caller = createAppSetupStepCallerNode();
        grantAppSetupStepAccess($caller, $node, ['instance:write']);
        $step = AppSetupStep::factory()->create([
            'instance_id' => $instance->id,
            'sort_order' => 1,
        ]);

        $response = $this->call(
            'DELETE',
            "/api/instances/docs/setup-steps/{$step->id}",
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => APP_SETUP_STEP_CALLER_WG_IP,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'destructive_consent' => true,
            ], JSON_THROW_ON_ERROR),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.result.action', 'removed')
            ->assertJsonPath('success.meta.remaining_step_count', 0);

        expect(AppSetupStep::query()->whereKey($step->id)->exists())->toBeFalse();
    });

    it('keeps setup steps isolated between instances', function (): void {
        [$node, $app, $development] = createAppSetupStepTarget();
        $production = Instance::factory()->for($app)->create([
            'name' => 'production',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $node->id,
                node: $node->name,
                path: '/srv/docs',
                document_root: 'public',
                domain: 'docs.example.com',
            ),
        ]);
        AppSetupStep::factory()->create([
            'instance_id' => $development->id,
            'command' => 'composer install',
        ]);
        AppSetupStep::factory()->create([
            'instance_id' => $production->id,
            'command' => 'php artisan migrate --force',
        ]);
        $caller = createAppSetupStepCallerNode();
        grantAppSetupStepAccess($caller, $node, ['instance:read']);

        $response = $this->call(
            'GET',
            '/api/instances/docs.production/setup-steps',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_SETUP_STEP_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'success.data.steps')
            ->assertJsonPath('success.data.steps.0.instance', 'production')
            ->assertJsonPath('success.data.steps.0.command', 'php artisan migrate --force');
    });

    it('requires a concrete selector without exposing a hidden sibling', function (): void {
        [$visibleNode, $app, $development] = createAppSetupStepTarget();
        $hiddenNode = Node::factory()->appDev()->create(['name' => 'app-hidden']);
        $production = Instance::factory()->for($app)->create([
            'name' => 'production',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $hiddenNode->id,
                node: $hiddenNode->name,
                path: '/srv/docs',
                document_root: 'public',
                domain: 'docs.example.com',
            ),
        ]);
        AppSetupStep::factory()->create([
            'instance_id' => $development->id,
            'command' => 'composer install',
        ]);
        AppSetupStep::factory()->create([
            'instance_id' => $production->id,
            'command' => 'php artisan migrate --force',
        ]);
        $caller = createAppSetupStepCallerNode();
        grantAppSetupStepAccess($caller, $visibleNode, ['instance:read']);

        $response = $this->call(
            'GET',
            '/api/instances/docs/setup-steps',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_SETUP_STEP_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'instance_required');

        expect($response->json('error.meta'))
            ->not->toHaveKey('instances')->and($response->content())
            ->not->toContain('production');
    });

    it('does not reveal whether an unauthorized explicit sibling exists', function (): void {
        [$visibleNode, $app] = createAppSetupStepTarget();
        $hiddenNode = Node::factory()->appDev()->create(['name' => 'app-hidden']);
        Instance::factory()->for($app)->create([
            'name' => 'production',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $hiddenNode->id,
                node: $hiddenNode->name,
                path: '/srv/docs',
                document_root: 'public',
                domain: 'docs.example.com',
            ),
        ]);
        $caller = createAppSetupStepCallerNode();
        grantAppSetupStepAccess($caller, $visibleNode, ['instance:read']);

        $hidden = $this->call(
            'GET',
            '/api/instances/docs.production/setup-steps',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_SETUP_STEP_CALLER_WG_IP],
        );
        $missing = $this->call(
            'GET',
            '/api/instances/docs.does-not-exist/setup-steps',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_SETUP_STEP_CALLER_WG_IP],
        );
        $normalize = static function (TestResponse $response): array {
            /** @var array<string, mixed> $error */
            $error = $response->json('error');
            unset($error['message']);

            if (is_array($error['meta'] ?? null)) {
                $error['meta']['instance'] = '<selector>';
            }

            return $error;
        };

        $hidden->assertUnprocessable();
        $missing->assertUnprocessable();

        expect($normalize($hidden))
            ->toBe($normalize($missing))
            ->and($hidden->json('error.code'))
            ->toBe('validation_failed')
            ->and($hidden->json('error.meta'))
            ->not->toHaveKeys(['instances', 'missing_permission', 'serving_node']);
    });
});
