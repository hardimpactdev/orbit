<?php

declare(strict_types=1);

use App\Enums\WorkspaceLifecyclePhase;
use App\Http\Gateway\Requests\Workspaces\AddWorkspaceStepRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\WorkspaceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createWorkspaceStepAddLocalNode(string $role = 'gateway'): Node
{
    config(['orbit.is_gateway' => $role === 'gateway']);

    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

function createWorkspaceStepAddApp(): App
{
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);

    return App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
}

describe('workspace step add commands', function (): void {
    it('adds setup steps for gateway callers', function (): void {
        createWorkspaceStepAddLocalNode('gateway');
        createWorkspaceStepAddApp();

        $exitCode = Artisan::call('workspace-setup-step:add', [
            '--app' => 'docs',
            '--command' => 'composer install',
            '--timeout' => '300',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['result']['action'])->toBe('added')
            ->and($payload['success']['data']['step'])->toMatchArray([
                'app' => 'docs',
                'phase' => 'setup',
                'order' => 1,
                'command' => 'composer install',
                'timeout_seconds' => 300,
            ])
            ->and($payload['success']['data']['step'])->not->toHaveKeys(['name', 'working_directory', 'env_overrides', 'on_failure'])
            ->and(WorkspaceStep::query()->count())->toBe(1);
    });

    it('inserts teardown steps before and after existing anchors', function (): void {
        createWorkspaceStepAddLocalNode('gateway');
        $app = createWorkspaceStepAddApp();
        $first = WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'phase' => WorkspaceLifecyclePhase::Teardown,
            'sort_order' => 1,
            'command' => 'dropdb docs',
        ]);

        Artisan::call('workspace-teardown-step:add', [
            '--app' => 'docs',
            '--command' => 'notify cleanup',
            '--before' => (string) $first->id,
            '--json' => true,
        ]);
        $second = WorkspaceStep::query()->where('command', 'notify cleanup')->firstOrFail();
        Artisan::call('workspace-teardown-step:add', [
            '--app' => 'docs',
            '--command' => 'rm -rf storage/logs',
            '--after' => (string) $first->id,
            '--json' => true,
        ]);

        $ordered = WorkspaceStep::query()
            ->where('app_id', $app->id)
            ->where('phase', WorkspaceLifecyclePhase::Teardown)
            ->orderBy('sort_order')
            ->pluck('command')
            ->all();

        expect($second->refresh()->sort_order)->toBe(1)
            ->and($ordered)->toBe(['notify cleanup', 'dropdb docs', 'rm -rf storage/logs']);
    });

    it('is additive and does not converge by command text', function (): void {
        createWorkspaceStepAddLocalNode('gateway');
        createWorkspaceStepAddApp();

        Artisan::call('workspace-setup-step:add', ['--app' => 'docs', '--command' => 'composer install', '--json' => true]);
        Artisan::call('workspace-setup-step:add', ['--app' => 'docs', '--command' => 'composer install', '--json' => true]);

        expect(WorkspaceStep::query()->count())->toBe(2);
    });

    it('validates timeout and insertion flags before writing', function (): void {
        createWorkspaceStepAddLocalNode('gateway');
        $app = createWorkspaceStepAddApp();
        $step = WorkspaceStep::factory()->create(['app_id' => $app->id, 'phase' => WorkspaceLifecyclePhase::Setup]);

        $timeoutExit = Artisan::call('workspace-setup-step:add', [
            '--app' => 'docs',
            '--command' => 'composer install',
            '--timeout' => '0',
            '--json' => true,
        ]);
        $timeoutPayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $positionExit = Artisan::call('workspace-setup-step:add', [
            '--app' => 'docs',
            '--command' => 'npm install',
            '--before' => (string) $step->id,
            '--after' => (string) $step->id,
            '--json' => true,
        ]);
        $positionPayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($timeoutExit)->toBe(1)
            ->and($timeoutPayload['error']['code'])->toBe('validation_failed')
            ->and($timeoutPayload['error']['meta']['field'])->toBe('timeout')
            ->and($positionExit)->toBe(1)
            ->and($positionPayload['error']['code'])->toBe('workspace.invalid_position')
            ->and(WorkspaceStep::query()->count())->toBe(1);
    });

    it('returns step-not-found for anchors outside the app and phase', function (): void {
        createWorkspaceStepAddLocalNode('gateway');
        createWorkspaceStepAddApp();

        $exitCode = Artisan::call('workspace-setup-step:add', [
            '--app' => 'docs',
            '--command' => 'npm install',
            '--before' => '999',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('workspace.step_not_found')
            ->and($payload['error']['meta']['phase'])->toBe('setup');
    });

    it('forwards control callers through the typed gateway request', function (): void {
        createWorkspaceStepAddLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        $mock = MockClient::global([
            AddWorkspaceStepRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'result' => ['action' => 'added'],
                        'step' => [
                            'id' => 12,
                            'app' => 'docs',
                            'phase' => 'setup',
                            'order' => 1,
                            'command' => 'composer install',
                            'timeout_seconds' => 600,
                        ],
                    ],
                    'meta' => [],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('workspace-setup-step:add', [
            '--app' => 'docs',
            '--command' => 'composer install',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['step']['id'])->toBe(12);
        $mock->assertSent(fn (AddWorkspaceStepRequest $request): bool => $request->phase === WorkspaceLifecyclePhase::Setup
            && $request->app === 'docs'
            && $request->command === 'composer install'
            && $request->resolveEndpoint() === '/api/workspaces/steps/setup');
    });
});
