<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Processes\RestartProcessesRequest;
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

function createProcessRestartLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

describe('process:restart base contract', function (): void {
    it('restarts a named gateway-local app process and records a durable event', function (): void {
        createProcessRestartLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite']);
        $remoteShell = new ProcessRestartRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'restarted', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:restart', [
            'name' => 'vite',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($remoteShell->scripts)->toBe(["sudo supervisorctl restart 'orbit_docs_main_vite'"])
            ->and($payload['success']['data']['runtimes'][0]['state'])->toBe('running')
            ->and($payload['success']['data']['runtimes'][0]['events'][0]['type'])->toBe('stopped')
            ->and(ProcessEvent::query()->where('event', 'started')->where('unit_name', 'orbit_docs_main_vite')->exists())->toBeTrue();
    });

    it('restarts all processes in process order for a workspace context', function (): void {
        createProcessRestartLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'queue', 'sort_order' => 20]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'sort_order' => 10]);
        app()->instance(RemoteShell::class, new ProcessRestartRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $exitCode = Artisan::call('process:restart', [
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
        createProcessRestartLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'sort_order' => 10]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'queue', 'sort_order' => 20]);
        app()->instance(RemoteShell::class, new ProcessRestartRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'failed', durationMs: 1),
        ]));

        $exitCode = Artisan::call('process:restart', [
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('process.runtime_action_failed')
            ->and($payload['error']['meta']['partial_state'])->toBe('partially_restarted')
            ->and(array_column($payload['error']['data']['runtimes'], 'state'))->toBe(['running', 'failed']);
    });

    it('rejects unknown callers before side effects', function (): void {
        createProcessRestartLocalNode('weird');
        $remoteShell = new ProcessRestartRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:restart', [
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

        createProcessRestartLocalNode('app');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            RestartProcessesRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'runtimes' => [
                            [
                                'process' => 'vite',
                                'app' => 'docs',
                                'workspace' => null,
                                'runtime_unit' => 'orbit_docs_main_vite',
                                'state' => 'running',
                                'events' => [['type' => 'stopped'], ['type' => 'started']],
                            ],
                        ],
                    ],
                    'meta' => [],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('process:restart', [
            'name' => 'vite',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['runtimes'][0]['state'])->toBe('running');
    });

    it('renders human progress and success prose', function (): void {
        createProcessRestartLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite']);
        app()->instance(RemoteShell::class, new ProcessRestartRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $this->artisan('process:restart', [
            'name' => 'vite',
            '--app' => 'docs',
        ])
            ->expectsOutputToContain('┌  Restarting Processes')
            ->expectsOutputToContain('○  Resolve runtime units')
            ->expectsOutputToContain('○  Restart runtime units')
            ->expectsOutputToContain('○  Record process events')
            ->expectsOutputToContain('└  Working...')
            ->expectsOutput("Process 'vite' restarted for app 'docs'")
            ->assertSuccessful();
    });
});

final class ProcessRestartRemoteShell implements RemoteShell
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
