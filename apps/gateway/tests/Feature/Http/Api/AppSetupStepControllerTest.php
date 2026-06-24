<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\AppSetupStep;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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
        'node_id' => $node->id,
    ]);

    return [$node, $app];
}

describe('AppSetupStepController', function (): void {
    it('creates app setup steps for authorized callers', function (): void {
        [$node] = createAppSetupStepTarget();
        $caller = createAppSetupStepCallerNode();
        grantAppSetupStepAccess($caller, $node, ['app:write']);

        $response = $this->call(
            'POST',
            '/api/apps/docs/setup-steps',
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
            ->assertJsonPath('success.data.step.order', 1)
            ->assertJsonPath('success.data.step.timeout_seconds', 900);
    });

    it('lists setup steps with app read permission', function (): void {
        [$node, $app] = createAppSetupStepTarget();
        $caller = createAppSetupStepCallerNode();
        grantAppSetupStepAccess($caller, $node, ['app:read']);
        AppSetupStep::factory()->create([
            'app_id' => $app->id,
            'command' => 'php artisan migrate',
            'sort_order' => 1,
        ]);

        $response = $this->call(
            'GET',
            '/api/apps/docs/setup-steps',
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
            ->assertJsonPath('success.data.steps.0.command', 'php artisan migrate');
    });

    it('removes setup steps with destructive consent', function (): void {
        [$node, $app] = createAppSetupStepTarget();
        $caller = createAppSetupStepCallerNode();
        grantAppSetupStepAccess($caller, $node, ['app:write']);
        $step = AppSetupStep::factory()->create([
            'app_id' => $app->id,
            'sort_order' => 1,
        ]);

        $response = $this->call(
            'DELETE',
            "/api/apps/docs/setup-steps/{$step->id}",
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
});
