<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Processes\ProcessRuntime;
use App\Http\Gateway\Requests\Processes\ShowProcessLogsRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createProcessLogsLocalNode(string $role = 'gateway'): Node
{
    $node = Node::factory()->create([
        'name' => "local-{$role}",
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);

    if ($role === 'gateway') {
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'gateway',
            'status' => 'active',
            'settings' => [],
        ]);
    }

    return $node;
}

describe('process:logs base contract', function (): void {
    it('reads bounded gateway-local process logs as JSON', function (): void {
        createProcessLogsLocalNode('gateway');
        $node = Node::factory()->appDev()->create();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'runtime' => ProcessRuntime::Supervisor]);
        $remoteShell = new ProcessLogsRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "2026-04-30T12:00:00Z Vite ready\nplain line\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:logs', [
            'name' => 'vite',
            '--app' => 'docs',
            '--lines' => 2,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($remoteShell->scripts[0])->toBe("sudo tail -n 2 '/home/orbit/.config/orbit/logs/orbit_docs_main_vite.log'")
            ->and($payload['success']['data']['logs']['runtime_unit'])->toBe('orbit_docs_main_vite')
            ->and($payload['success']['data']['logs']['lines'][0])->toBe([
                'timestamp' => '2026-04-30T12:00:00Z',
                'message' => 'Vite ready',
            ])
            ->and($payload['success']['meta']['line_count'])->toBe(2);
    });

    it('reads workspace logs in follow mode through tail -F for human output', function (): void {
        createProcessLogsLocalNode('gateway');
        $node = Node::factory()->appDev()->create();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'runtime' => ProcessRuntime::Supervisor]);
        $remoteShell = new ProcessLogsRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "Vite ready\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $this->artisan('process:logs', [
            'name' => 'vite',
            '--app' => 'docs',
            '--workspace' => 'feature-docs',
            '--lines' => 1,
            '--follow' => true,
        ])
            ->expectsOutputToContain('  ┌  Streaming Process Logs')
            ->expectsOutputToContain('  │')
            ->expectsOutputToContain('  ○  Resolve runtime unit')
            ->expectsOutputToContain('  ○  Open log stream')
            ->expectsOutputToContain('  ●  Resolved runtime unit')
            ->expectsOutputToContain('  └  Log stream opened')
            ->expectsOutput('Vite ready')
            ->assertSuccessful();

        expect($remoteShell->scripts[0])->toBe("sudo tail -n 1 -F '/home/orbit/.config/orbit/logs/orbit_docs_feature-docs_vite.log'");
    });

    it('rejects invalid input before log reads', function (array $arguments, string $field): void {
        createProcessLogsLocalNode('gateway');
        $remoteShell = new ProcessLogsRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:logs', $arguments + ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe($field)
            ->and($remoteShell->scripts)->toBe([]);
    })->with([
        'missing name' => [['--app' => 'docs'], 'name'],
        'invalid lines' => [['name' => 'vite', '--app' => 'docs', '--lines' => 0], 'lines'],
        'json follow' => [['name' => 'vite', '--app' => 'docs', '--follow' => true], 'json'],
    ]);

    it('reports process not found and log read failures', function (array $results, string $code): void {
        createProcessLogsLocalNode('gateway');
        $node = Node::factory()->appDev()->create();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        if ($code === 'process.log_read_failed') {
            Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'runtime' => ProcessRuntime::Supervisor]);
        }
        app()->instance(RemoteShell::class, new ProcessLogsRemoteShell($results));

        $exitCode = Artisan::call('process:logs', [
            'name' => 'vite',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe($code);
    })->with([
        'not found' => [[], 'process.not_found'],
        'read failed' => [[new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'missing', durationMs: 1)], 'process.log_read_failed'],
    ]);

    it('denies unknown callers before side effects', function (): void {
        createProcessLogsLocalNode('weird');
        $remoteShell = new ProcessLogsRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:logs', [
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

        createProcessLogsLocalNode('app');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ShowProcessLogsRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'logs' => [
                            'process' => 'vite',
                            'app' => 'docs',
                            'workspace' => null,
                            'runtime_unit' => 'orbit_docs_main_vite',
                            'lines' => [['timestamp' => null, 'message' => 'Vite ready']],
                        ],
                    ],
                    'meta' => ['line_count' => 1],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('process:logs', [
            'name' => 'vite',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['logs']['lines'][0]['message'])->toBe('Vite ready');
    });
});

describe('process:logs runtime routing', function (): void {
    it('reads docker logs for docker runtime processes', function (): void {
        createProcessLogsLocalNode('gateway');
        $node = Node::factory()->appDev()->create();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'runtime_kind' => AppRuntimeKind::Php]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'queue', 'runtime' => ProcessRuntime::Docker]);
        $remoteShell = new ProcessLogsRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "2026-04-30T12:00:00Z queue ready\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:logs', [
            'name' => 'queue',
            '--app' => 'docs',
            '--lines' => 1,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($remoteShell->scripts[0])->toContain('docker logs')
            ->and($remoteShell->scripts[0])->toContain('orbit_docs_main_queue')
            ->and($payload['success']['data']['logs']['runtime_unit'])->toBe('orbit_docs_main_queue')
            ->and($payload['success']['data']['logs']['lines'][0])->toBe([
                'timestamp' => '2026-04-30T12:00:00Z',
                'message' => 'queue ready',
            ]);
    });

    it('reads supervisor logs for supervisor runtime processes', function (): void {
        createProcessLogsLocalNode('gateway');
        $node = Node::factory()->appDev()->create();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'runtime_kind' => AppRuntimeKind::Static]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'runtime' => ProcessRuntime::Supervisor]);
        $remoteShell = new ProcessLogsRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "2026-04-30T12:00:00Z Vite ready\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:logs', [
            'name' => 'vite',
            '--app' => 'docs',
            '--lines' => 1,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($remoteShell->scripts[0])->toBe("sudo tail -n 1 '/home/orbit/.config/orbit/logs/orbit_docs_main_vite.log'")
            ->and($payload['success']['data']['logs']['runtime_unit'])->toBe('orbit_docs_main_vite');
    });

    it('follows docker logs for docker runtime processes with --follow', function (): void {
        createProcessLogsLocalNode('gateway');
        $node = Node::factory()->appDev()->create();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'runtime_kind' => AppRuntimeKind::Php]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'queue', 'runtime' => ProcessRuntime::Docker]);
        $remoteShell = new ProcessLogsRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "queue ready\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $this->artisan('process:logs', [
            'name' => 'queue',
            '--app' => 'docs',
            '--lines' => 1,
            '--follow' => true,
        ])
            ->expectsOutput('queue ready')
            ->assertSuccessful();

        expect($remoteShell->scripts[0])->toContain('docker logs')
            ->and($remoteShell->scripts[0])->toContain('--follow')
            ->and($remoteShell->scripts[0])->toContain('orbit_docs_main_queue');
    });
});

final class ProcessLogsRemoteShell implements RemoteShell
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
