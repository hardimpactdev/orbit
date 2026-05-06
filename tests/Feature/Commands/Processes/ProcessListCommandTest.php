<?php

declare(strict_types=1);

use App\Enums\ProcessCrashNotification;
use App\Enums\ProcessRestartPolicy;
use App\Http\Gateway\Requests\Processes\ListProcessesRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process as ProcessFacade;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createProcessListLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'is_local' => true,
    ]);
}

describe('process:list base contract', function (): void {
    it('lists gateway-local app processes as JSON', function (): void {
        createProcessListLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create([
            'app_id' => $app->id,
            'name' => 'queue',
            'command' => 'php artisan queue:work',
            'restart_policy' => ProcessRestartPolicy::Always,
            'crash_notification' => ProcessCrashNotification::None,
            'sort_order' => 20,
        ]);
        Process::factory()->create([
            'app_id' => $app->id,
            'name' => 'vite',
            'command' => 'npm run dev',
            'restart_policy' => ProcessRestartPolicy::Never,
            'crash_notification' => ProcessCrashNotification::AgentIde,
            'sort_order' => 10,
        ]);

        $exitCode = Artisan::call('process:list', ['--json' => true, '--app' => 'docs']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['context'])->toBe(['app' => 'docs', 'workspace' => null])
            ->and(array_column($payload['success']['data']['processes'], 'name'))->toBe(['vite', 'queue'])
            ->and($payload['success']['data']['processes'][0]['runtime_unit'])->toBe('orbit_docs_main_vite')
            ->and($payload['success']['data']['processes'][0]['last_event'])->toBeNull()
            ->and($payload['success']['meta'])->toBe([]);
    });

    it('lists inherited processes for a workspace context', function (): void {
        createProcessListLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'sort_order' => 1]);

        $exitCode = Artisan::call('process:list', [
            '--json' => true,
            '--app' => 'docs',
            '--workspace' => 'feature-docs',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['context'])->toBe(['app' => 'docs', 'workspace' => 'feature-docs'])
            ->and($payload['success']['data']['processes'][0]['runtime_unit'])->toBe('orbit_docs_feature-docs_vite');
    });

    it('renders human process output and empty state', function (): void {
        createProcessListLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'command' => 'npm run dev', 'sort_order' => 1]);

        $this->artisan('process:list', ['--app' => 'docs'])
            ->expectsOutputToContain('Processes for docs')
            ->expectsOutputToContain('vite')
            ->assertSuccessful();

        Process::query()->delete();

        $this->artisan('process:list', ['--app' => 'docs'])
            ->expectsOutput('No processes found.')
            ->assertSuccessful();
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        createProcessListLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ListProcessesRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'context' => ['app' => 'docs', 'workspace' => null],
                        'processes' => [
                            [
                                'name' => 'queue',
                                'command' => 'php artisan queue:work',
                                'restart_policy' => 'always',
                                'crash_notification' => 'none',
                                'runtime_unit' => 'orbit_docs_main_queue',
                                'last_event' => null,
                            ],
                        ],
                    ],
                    'meta' => [],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('process:list', [
            '--json' => true,
            '--app' => 'docs',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['processes'][0]['name'])->toBe('queue');
    });

    it('preserves structured gateway authorization failures', function (): void {
        createProcessListLocalNode('app');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ListProcessesRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'This node is not authorized to read process intent.',
                    'meta' => ['caller_role' => 'app'],
                ],
            ], 403),
        ]);

        $exitCode = Artisan::call('process:list', ['--json' => true, '--app' => 'docs']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('authorization_failed')
            ->and($payload['error']['meta']['caller_role'])->toBe('app');
    });

    it('rejects unknown local caller roles before gateway calls', function (): void {
        createProcessListLocalNode('weird');

        $exitCode = Artisan::call('process:list', ['--json' => true, '--app' => 'docs']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('caller_role_not_allowed')
            ->and($payload['error']['meta']['caller_role'])->toBe('unknown');
    });

    it('renders validation and gateway unavailable failures', function (): void {
        createProcessListLocalNode('gateway');
        Node::factory()->create(['role' => 'app']);

        $exitCode = Artisan::call('process:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('app');

        Node::query()->delete();
        createProcessListLocalNode('control');

        $exitCode = Artisan::call('process:list', ['--json' => true, '--app' => 'docs']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable');
    });

    it('does not mutate process registry state or run external processes', function (): void {
        ProcessFacade::fake();
        ProcessFacade::preventStrayProcesses();

        createProcessListLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->count(2)->create(['app_id' => $app->id]);

        $processCount = DB::table('processes')->count();

        $this->artisan('process:list', ['--app' => 'docs'])->assertSuccessful();

        expect(DB::table('processes')->count())->toBe($processCount);
        ProcessFacade::assertNothingRan();
    });
});
