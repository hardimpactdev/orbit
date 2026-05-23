<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntime;
use App\Http\Gateway\Requests\Processes\RemoveProcessRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Key;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createProcessRemoveLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

describe('process:remove base contract', function (): void {
    it('removes process intent and cleans up main and workspace runtime units', function (): void {
        createProcessRemoveLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'command' => 'npm run dev', 'runtime' => ProcessRuntime::Supervisor]);
        $remoteShell = new ProcessRemoveRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:remove', [
            'name' => 'vite',
            '--app' => 'docs',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(Process::query()->where('name', 'vite')->exists())->toBeFalse()
            ->and($payload['success']['data']['process'])->toBe(['name' => 'vite', 'app' => 'docs'])
            ->and($payload['success']['data']['removed_runtime_units'])->toBe([
                'orbit_docs_main_vite',
                'orbit_docs_feature-docs_vite',
            ])
            ->and($payload['success']['meta']['warnings'])->toBe([])
            ->and($remoteShell->scripts[0])->toContain('/etc/supervisor/conf.d/orbit_docs_main_vite.conf')
            ->and($remoteShell->scripts[1])->toContain('/etc/supervisor/conf.d/orbit_docs_feature-docs_vite.conf');
    });

    it('dispatches docker rm for docker runtime processes', function (): void {
        createProcessRemoveLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'queue', 'runtime' => ProcessRuntime::Docker]);
        $remoteShell = new ProcessRemoveRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '{"State":{"Status":"running"},"Config":{}}', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:remove', [
            'name' => 'queue',
            '--app' => 'docs',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(Process::query()->where('name', 'queue')->exists())->toBeFalse()
            ->and($remoteShell->scripts[1])->toContain('docker rm -f')
            ->and($remoteShell->scripts[1])->toContain('orbit_docs_main_queue');
    });

    it('returns success with warnings when cleanup fails after intent removal', function (): void {
        createProcessRemoveLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'runtime' => ProcessRuntime::Supervisor]);
        app()->instance(RemoteShell::class, new ProcessRemoveRemoteShell([
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'failed', durationMs: 1),
        ]));

        $exitCode = Artisan::call('process:remove', [
            'name' => 'vite',
            '--app' => 'docs',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(Process::query()->where('name', 'vite')->exists())->toBeFalse()
            ->and($payload['success']['meta']['warnings'][0]['code'])->toBe('process.runtime_unit_extra');
    });

    it('requires force in non-interactive json mode and preserves intent', function (): void {
        createProcessRemoveLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'runtime' => ProcessRuntime::Supervisor]);
        app()->instance(RemoteShell::class, new ProcessRemoveRemoteShell([]));

        $exitCode = Artisan::call('process:remove', [
            'name' => 'vite',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('force')
            ->and(Process::query()->where('name', 'vite')->exists())->toBeTrue();
    });

    it('prompts with app and process data tables when required input is missing', function (): void {
        createProcessRemoveLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'runtime' => ProcessRuntime::Supervisor]);
        app()->instance(RemoteShell::class, new ProcessRemoveRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        DataTablePrompt::fake([Key::ENTER, Key::ENTER]);

        $this->artisan('process:remove', ['--force' => true])
            ->assertSuccessful();

        expect(Process::query()->where('name', 'vite')->exists())->toBeFalse();
    });

    it('rejects validation and not found failures before cleanup', function (array $arguments, string $field, string $code): void {
        createProcessRemoveLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'runtime' => ProcessRuntime::Supervisor]);
        $remoteShell = new ProcessRemoveRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:remove', $arguments + ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe($code)
            ->and($payload['error']['meta']['field'] ?? 'name')->toBe($field)
            ->and($remoteShell->scripts)->toBe([]);
    })->with([
        'missing app' => [['name' => 'vite', '--force' => true], 'app', 'validation_failed'],
        'missing name' => [['--app' => 'docs', '--force' => true], 'name', 'validation_failed'],
        'not found' => [['name' => 'queue', '--app' => 'docs', '--force' => true], 'name', 'process.not_found'],
    ]);

    it('denies app and unknown callers before side effects', function (string $role): void {
        createProcessRemoveLocalNode($role);
        $remoteShell = new ProcessRemoveRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:remove', [
            'name' => 'vite',
            '--app' => 'docs',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($remoteShell->scripts)->toBe([]);
    })->with(['app', 'weird']);

    it('forwards configured control callers through the typed gateway request after consent', function (): void {
        config(['orbit.is_gateway' => false]);

        createProcessRemoveLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            RemoveProcessRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'process' => ['name' => 'vite', 'app' => 'docs'],
                        'removed_runtime_units' => ['orbit_docs_main_vite'],
                    ],
                    'meta' => ['warnings' => []],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('process:remove', [
            'name' => 'vite',
            '--app' => 'docs',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['process']['name'])->toBe('vite');
    });

    it('renders human progress and success prose', function (): void {
        createProcessRemoveLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'runtime' => ProcessRuntime::Supervisor]);
        app()->instance(RemoteShell::class, new ProcessRemoveRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $this->artisan('process:remove', [
            'name' => 'vite',
            '--app' => 'docs',
            '--force' => true,
        ])
            ->expectsOutputToContain('┌  Removing Process')
            ->expectsOutputToContain('○  Validate process')
            ->expectsOutputToContain('○  Remove runtime units')
            ->expectsOutputToContain('○  Apply and verify process removal')
            ->expectsOutputToContain('└  Working...')
            ->expectsOutput("Process 'vite' removed from app 'docs'")
            ->assertSuccessful();
    });
});

final class ProcessRemoveRemoteShell implements RemoteShell
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
