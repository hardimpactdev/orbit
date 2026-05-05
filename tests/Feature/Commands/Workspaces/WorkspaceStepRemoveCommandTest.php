<?php

declare(strict_types=1);

use App\Enums\WorkspaceLifecyclePhase;
use App\Http\Gateway\Requests\Workspaces\RemoveWorkspaceStepRequest;
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

function createWorkspaceStepRemoveLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'is_local' => true,
    ]);
}

function createWorkspaceStepRemoveApp(): App
{
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);

    return App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
}

describe('workspace step remove commands', function (): void {
    it('removes setup steps and compacts ordering for gateway callers', function (): void {
        createWorkspaceStepRemoveLocalNode('gateway');
        $app = createWorkspaceStepRemoveApp();
        WorkspaceStep::factory()->create(['app_id' => $app->id, 'phase' => WorkspaceLifecyclePhase::Setup, 'sort_order' => 1, 'command' => 'composer install']);
        $removed = WorkspaceStep::factory()->create(['app_id' => $app->id, 'phase' => WorkspaceLifecyclePhase::Setup, 'sort_order' => 2, 'command' => 'npm install']);
        WorkspaceStep::factory()->create(['app_id' => $app->id, 'phase' => WorkspaceLifecyclePhase::Setup, 'sort_order' => 3, 'command' => 'npm build']);

        $exitCode = Artisan::call('workspace-setup-step:remove', [
            '--app' => 'docs',
            '--step' => (string) $removed->id,
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['result'])->toBe(['action' => 'removed'])
            ->and($payload['success']['data']['step'])->toMatchArray([
                'id' => $removed->id,
                'app' => 'docs',
                'phase' => 'setup',
                'order' => 2,
                'command' => 'npm install',
            ])
            ->and($payload['success']['meta']['remaining_step_count'])->toBe(2)
            ->and(WorkspaceStep::query()->whereKey($removed->id)->exists())->toBeFalse()
            ->and(WorkspaceStep::query()->where('app_id', $app->id)->where('phase', WorkspaceLifecyclePhase::Setup)->orderBy('sort_order')->pluck('sort_order')->all())->toBe([1, 2]);
    });

    it('removes teardown steps and renders the empty-list human hint', function (): void {
        createWorkspaceStepRemoveLocalNode('gateway');
        $app = createWorkspaceStepRemoveApp();
        $removed = WorkspaceStep::factory()->create(['app_id' => $app->id, 'phase' => WorkspaceLifecyclePhase::Teardown, 'sort_order' => 1, 'command' => 'dropdb docs']);

        $exitCode = Artisan::call('workspace-teardown-step:remove', [
            '--app' => 'docs',
            '--step' => (string) $removed->id,
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("Removed teardown step {$removed->id} from app 'docs'.")
            ->and($output)->toContain("App 'docs' now has no workspace teardown steps.")
            ->and(WorkspaceStep::query()->count())->toBe(0);
    });

    it('requires destructive consent before deleting', function (): void {
        createWorkspaceStepRemoveLocalNode('gateway');
        $app = createWorkspaceStepRemoveApp();
        $step = WorkspaceStep::factory()->create(['app_id' => $app->id, 'phase' => WorkspaceLifecyclePhase::Setup]);

        $exitCode = Artisan::call('workspace-setup-step:remove', [
            '--app' => 'docs',
            '--step' => (string) $step->id,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('force')
            ->and(WorkspaceStep::query()->whereKey($step->id)->exists())->toBeTrue();
    });

    it('validates step ids and phase-scoped lookups', function (): void {
        createWorkspaceStepRemoveLocalNode('gateway');
        $app = createWorkspaceStepRemoveApp();
        $teardown = WorkspaceStep::factory()->create(['app_id' => $app->id, 'phase' => WorkspaceLifecyclePhase::Teardown]);

        $invalidExit = Artisan::call('workspace-setup-step:remove', [
            '--app' => 'docs',
            '--step' => '0',
            '--force' => true,
            '--json' => true,
        ]);
        $invalidPayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $wrongPhaseExit = Artisan::call('workspace-setup-step:remove', [
            '--app' => 'docs',
            '--step' => (string) $teardown->id,
            '--force' => true,
            '--json' => true,
        ]);
        $wrongPhasePayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($invalidExit)->toBe(1)
            ->and($invalidPayload['error']['meta']['field'])->toBe('step')
            ->and($wrongPhaseExit)->toBe(1)
            ->and($wrongPhasePayload['error']['code'])->toBe('workspace.step_not_found')
            ->and($wrongPhasePayload['error']['meta']['phase'])->toBe('setup')
            ->and(WorkspaceStep::query()->count())->toBe(1);
    });

    it('rejects app-node callers before prompts or deletes', function (): void {
        createWorkspaceStepRemoveLocalNode('app');
        $app = createWorkspaceStepRemoveApp();
        $step = WorkspaceStep::factory()->create(['app_id' => $app->id, 'phase' => WorkspaceLifecyclePhase::Setup]);

        $exitCode = Artisan::call('workspace-setup-step:remove', [
            '--app' => 'docs',
            '--step' => (string) $step->id,
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('caller_role_not_allowed')
            ->and(WorkspaceStep::query()->whereKey($step->id)->exists())->toBeTrue();
    });

    it('forwards control callers through the typed gateway request', function (): void {
        createWorkspaceStepRemoveLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        $mock = MockClient::global([
            RemoveWorkspaceStepRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'result' => ['action' => 'removed'],
                        'step' => [
                            'id' => 12,
                            'app' => 'docs',
                            'phase' => 'setup',
                            'order' => 1,
                            'command' => 'composer install',
                            'timeout_seconds' => 600,
                        ],
                    ],
                    'meta' => ['remaining_step_count' => 0],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('workspace-setup-step:remove', [
            '--app' => 'docs',
            '--step' => '12',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['step']['id'])->toBe(12);
        $mock->assertSent(fn (RemoveWorkspaceStepRequest $request): bool => $request->phase === WorkspaceLifecyclePhase::Setup
            && $request->app === 'docs'
            && $request->step === 12
            && $request->resolveEndpoint() === '/api/workspaces/steps/setup/12');
    });
});
