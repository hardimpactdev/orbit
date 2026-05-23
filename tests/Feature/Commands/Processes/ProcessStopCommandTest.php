<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Processes\ProcessRuntime;
use App\Http\Gateway\Requests\Processes\StopProcessesRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProcessEvent;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createProcessStopLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

describe('process:stop base contract', function (): void {
    it('stops a named gateway-local app process and records a durable event', function (): void {
        createProcessStopLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'runtime' => ProcessRuntime::Supervisor]);
        $remoteShell = new ProcessStopRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'stopped', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:stop', [
            'name' => 'vite',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($remoteShell->scripts)->toBe(["sudo supervisorctl stop 'orbit_docs_main_vite'"])
            ->and($payload['success']['data']['runtimes'][0]['state'])->toBe('stopped')
            ->and($payload['success']['data']['runtimes'][0]['event']['type'])->toBe('stopped')
            ->and(ProcessEvent::query()->where('event', 'stopped')->where('unit_name', 'orbit_docs_main_vite')->exists())->toBeTrue();
    });

    it('stops all processes in process order for a workspace context', function (): void {
        createProcessStopLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'queue', 'sort_order' => 20, 'runtime' => ProcessRuntime::Supervisor]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'sort_order' => 10, 'runtime' => ProcessRuntime::Supervisor]);
        app()->instance(RemoteShell::class, new ProcessStopRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $exitCode = Artisan::call('process:stop', [
            '--app' => 'docs',
            '--workspace' => 'feature-docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(array_column($payload['success']['data']['runtimes'], 'process'))->toBe(['vite', 'queue'])
            ->and(array_column($payload['success']['data']['runtimes'], 'runtime_unit'))->toBe([
                'orbit_docs_feature-docs_vite',
                'orbit_docs_feature-docs_queue',
            ]);
    });

    it('reports partial bulk failures with runtime data', function (): void {
        createProcessStopLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'sort_order' => 10, 'runtime' => ProcessRuntime::Supervisor]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'queue', 'sort_order' => 20, 'runtime' => ProcessRuntime::Supervisor]);
        app()->instance(RemoteShell::class, new ProcessStopRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'failed', durationMs: 1),
        ]));

        $exitCode = Artisan::call('process:stop', [
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('process.runtime_action_failed')
            ->and($payload['error']['meta']['partial_state'])->toBe('partially_stopped')
            ->and(array_column($payload['error']['data']['runtimes'], 'state'))->toBe(['stopped', 'failed']);
    });

    it('rejects unknown callers before side effects', function (): void {
        createProcessStopLocalNode('weird');
        $remoteShell = new ProcessStopRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:stop', [
            'name' => 'vite',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($remoteShell->scripts)->toBe([]);
    });

    it('forwards app callers through the typed gateway request', function (): void {
        config(['orbit.is_gateway' => false]);

        createProcessStopLocalNode('app');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            StopProcessesRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'runtimes' => [
                            [
                                'process' => 'vite',
                                'app' => 'docs',
                                'workspace' => null,
                                'runtime_unit' => 'orbit_docs_main_vite',
                                'state' => 'stopped',
                                'event' => ['type' => 'stopped'],
                            ],
                        ],
                    ],
                    'meta' => [],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('process:stop', [
            'name' => 'vite',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['runtimes'][0]['state'])->toBe('stopped');
    });

    it('renders human progress and success prose', function (): void {
        createProcessStopLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'runtime' => ProcessRuntime::Supervisor]);
        app()->instance(RemoteShell::class, new ProcessStopRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $this->artisan('process:stop', [
            'name' => 'vite',
            '--app' => 'docs',
        ])
            ->expectsOutputToContain('┌  Stopping Processes')
            ->expectsOutputToContain('○  Resolve runtime units')
            ->expectsOutputToContain('○  Stop runtime units')
            ->expectsOutputToContain('○  Record process events')
            ->expectsOutputToContain('└  Working...')
            ->expectsOutput("Process 'vite' stopped for app 'docs'")
            ->assertSuccessful();
    });
});

describe('process:stop runtime routing', function (): void {
    it('dispatches docker stop for docker runtime processes', function (): void {
        createProcessStopLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'runtime_kind' => AppRuntimeKind::Php]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'queue', 'runtime' => ProcessRuntime::Docker]);
        $remoteShell = new ProcessStopRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'stopped', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:stop', [
            'name' => 'queue',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($remoteShell->scripts[0])->toContain('docker stop')
            ->and($remoteShell->scripts[0])->toContain('orbit_docs_main_queue')
            ->and($payload['success']['data']['runtimes'][0]['state'])->toBe('stopped');
    });

    it('dispatches supervisorctl stop for supervisor runtime processes', function (): void {
        createProcessStopLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'runtime_kind' => AppRuntimeKind::Static]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'runtime' => ProcessRuntime::Supervisor]);
        $remoteShell = new ProcessStopRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'stopped', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:stop', [
            'name' => 'vite',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($remoteShell->scripts[0])->toBe("sudo supervisorctl stop 'orbit_docs_main_vite'")
            ->and($payload['success']['data']['runtimes'][0]['state'])->toBe('stopped');
    });
});

final class ProcessStopRemoteShell implements RemoteShell
{
    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
        public array $scripts = [],
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
