<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Processes\StartProcessesRequest;
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

function createProcessStartLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

describe('process:start base contract', function (): void {
    it('starts a named gateway-local app process and records a durable event', function (): void {
        createProcessStartLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite']);
        $remoteShell = new ProcessStartRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'started', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:start', [
            'name' => 'vite',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($remoteShell->scripts)->toBe(["sudo supervisorctl start 'orbit_docs_main_vite'"])
            ->and($payload['success']['data']['runtimes'][0]['state'])->toBe('running')
            ->and($payload['success']['data']['runtimes'][0]['event']['type'])->toBe('started')
            ->and(ProcessEvent::query()->where('event', 'started')->where('unit_name', 'orbit_docs_main_vite')->exists())->toBeTrue();
    });

    it('starts all processes in process order for a workspace context', function (): void {
        createProcessStartLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'queue', 'sort_order' => 20]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'sort_order' => 10]);
        $remoteShell = new ProcessStartRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:start', [
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
            ])
            ->and($remoteShell->scripts)->toBe([
                "sudo supervisorctl start 'orbit_docs_feature-docs_vite'",
                "sudo supervisorctl start 'orbit_docs_feature-docs_queue'",
            ]);
    });

    it('reports partial bulk failures with runtime data and preserves successful events', function (): void {
        createProcessStartLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'sort_order' => 10]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'queue', 'sort_order' => 20]);
        app()->instance(RemoteShell::class, new ProcessStartRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'failed', durationMs: 1),
        ]));

        $exitCode = Artisan::call('process:start', [
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('process.runtime_action_failed')
            ->and($payload['error']['meta']['partial_state'])->toBe('partially_started')
            ->and(array_column($payload['error']['data']['runtimes'], 'state'))->toBe(['running', 'failed'])
            ->and(ProcessEvent::query()->where('event', 'started')->count())->toBe(1);
    });

    it('does not mutate process intent while starting runtime units', function (): void {
        createProcessStartLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite']);
        app()->instance(RemoteShell::class, new ProcessStartRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));
        $processCount = Process::query()->count();

        Artisan::call('process:start', [
            'name' => 'vite',
            '--app' => 'docs',
            '--json' => true,
        ]);

        expect(Process::query()->count())->toBe($processCount);
    });

    it('rejects validation and not found failures before runtime side effects', function (array $arguments, string $field, string $code): void {
        createProcessStartLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite']);
        $remoteShell = new ProcessStartRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:start', $arguments + ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe($code)
            ->and($payload['error']['meta']['field'] ?? 'name')->toBe($field)
            ->and($remoteShell->scripts)->toBe([]);
    })->with([
        'missing app' => [['name' => 'vite'], 'app', 'validation_failed'],
        'not found' => [['name' => 'queue', '--app' => 'docs'], 'name', 'process.not_found'],
    ]);

    it('denies unknown callers before side effects', function (): void {
        createProcessStartLocalNode('weird');
        $remoteShell = new ProcessStartRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:start', [
            'name' => 'vite',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($remoteShell->scripts)->toBe([]);
    });

    it('forwards control and app callers through the typed gateway request', function (string $role): void {
        config(['orbit.is_gateway' => false]);

        createProcessStartLocalNode($role);

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            StartProcessesRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'runtimes' => [
                            [
                                'process' => 'vite',
                                'app' => 'docs',
                                'workspace' => null,
                                'runtime_unit' => 'orbit_docs_main_vite',
                                'state' => 'running',
                                'event' => ['type' => 'started'],
                            ],
                        ],
                    ],
                    'meta' => [],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('process:start', [
            'name' => 'vite',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['runtimes'][0]['process'])->toBe('vite');
    })->with(['control', 'app']);

    it('renders human progress and success prose', function (): void {
        createProcessStartLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite']);
        app()->instance(RemoteShell::class, new ProcessStartRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $this->artisan('process:start', [
            'name' => 'vite',
            '--app' => 'docs',
        ])
            ->expectsOutputToContain('┌  Starting Processes')
            ->expectsOutputToContain('○  Resolve runtime units')
            ->expectsOutputToContain('○  Start runtime units')
            ->expectsOutputToContain('○  Record process events')
            ->expectsOutputToContain('└  Working...')
            ->expectsOutput("Process 'vite' started for app 'docs'")
            ->assertSuccessful();
    });
});

final class ProcessStartRemoteShell implements RemoteShell
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
