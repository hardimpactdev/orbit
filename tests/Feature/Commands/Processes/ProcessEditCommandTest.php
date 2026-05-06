<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\ProcessCrashNotification;
use App\Enums\ProcessRestartPolicy;
use App\Http\Gateway\Requests\Processes\EditProcessRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
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

function createProcessEditLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'is_local' => true,
    ]);
}

describe('process:edit base contract', function (): void {
    it('updates process intent and re-renders main and workspace runtime units', function (): void {
        createProcessEditLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app', 'name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/home/orbit/apps/docs']);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id, 'path' => '/home/orbit/apps/docs/workspaces/feature-docs']);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'command' => 'npm run dev', 'sort_order' => 1]);

        $remoteShell = new ProcessEditRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:edit', [
            'name' => 'vite',
            '--app' => 'docs',
            '--command' => 'npm run dev -- --host=0.0.0.0',
            '--restart-policy' => 'always',
            '--crash-notification' => 'agent_ide',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $process = Process::query()->where('name', 'vite')->firstOrFail();

        expect($exitCode)->toBe(0)
            ->and($process->command)->toBe('npm run dev -- --host=0.0.0.0')
            ->and($process->restart_policy)->toBe(ProcessRestartPolicy::Always)
            ->and($process->crash_notification)->toBe(ProcessCrashNotification::AgentIde)
            ->and($payload['success']['data']['changed'])->toBe(['command', 'restart_policy', 'crash_notification'])
            ->and($payload['success']['data']['runtime_units'])->toBe([
                ['name' => 'orbit_docs_main_vite', 'context' => 'main'],
                ['name' => 'orbit_docs_feature-docs_vite', 'context' => 'feature-docs'],
            ])
            ->and($remoteShell->scripts[1])->toContain('/etc/supervisor/conf.d/orbit_docs_main_vite.conf')
            ->and($remoteShell->scripts[2])->toContain('/etc/supervisor/conf.d/orbit_docs_feature-docs_vite.conf');
    });

    it('restarts runtime units when requested', function (): void {
        createProcessEditLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'queue', 'command' => 'php artisan queue:work', 'sort_order' => 1]);
        $remoteShell = new ProcessEditRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:edit', [
            'name' => 'queue',
            '--app' => 'docs',
            '--command' => 'php artisan queue:work --tries=1',
            '--restart' => true,
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and(collect($remoteShell->scripts)->contains(fn (string $script): bool => str_contains($script, 'sudo supervisorctl restart')))->toBeTrue()
            ->and(collect($remoteShell->scripts)->contains(fn (string $script): bool => str_contains($script, 'orbit_docs_main_queue')))->toBeTrue();
    });

    it('returns success with warnings when re-rendering fails after intent update', function (): void {
        createProcessEditLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'command' => 'npm run dev']);
        app()->instance(RemoteShell::class, new ProcessEditRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'failed', durationMs: 1),
        ]));

        $exitCode = Artisan::call('process:edit', [
            'name' => 'vite',
            '--app' => 'docs',
            '--command' => 'npm run dev -- --host=0.0.0.0',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(Process::query()->where('name', 'vite')->value('command'))->toBe('npm run dev -- --host=0.0.0.0')
            ->and($payload['success']['meta']['warnings'][0]['code'])->toBe('process.runtime_unit_missing');
    });

    it('rejects invalid input before changing intent', function (array $arguments, string $field, string $code): void {
        createProcessEditLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'command' => 'npm run dev']);
        app()->instance(RemoteShell::class, new ProcessEditRemoteShell([]));

        $before = Process::query()->where('name', 'vite')->value('command');

        $exitCode = Artisan::call('process:edit', $arguments + ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe($code)
            ->and($payload['error']['meta']['field'] ?? 'name')->toBe($field)
            ->and(Process::query()->where('name', 'vite')->value('command'))->toBe($before);
    })->with([
        'missing app' => [['name' => 'vite', '--command' => 'npm run dev -- --host=0.0.0.0'], 'app', 'validation_failed'],
        'missing name' => [['--app' => 'docs', '--command' => 'npm run dev -- --host=0.0.0.0'], 'name', 'validation_failed'],
        'no editable fields' => [['name' => 'vite', '--app' => 'docs'], 'editable_fields', 'validation_failed'],
        'invalid restart' => [['name' => 'vite', '--app' => 'docs', '--restart-policy' => 'sometimes'], 'restart_policy', 'validation_failed'],
        'not found' => [['name' => 'queue', '--app' => 'docs', '--command' => 'php artisan queue:work'], 'name', 'process.not_found'],
    ]);

    it('denies app and unknown callers before side effects', function (string $role): void {
        createProcessEditLocalNode($role);
        $remoteShell = new ProcessEditRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:edit', [
            'name' => 'vite',
            '--app' => 'docs',
            '--command' => 'npm run dev',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('caller_role_not_allowed')
            ->and($remoteShell->scripts)->toBe([]);
    })->with(['app', 'weird']);

    it('forwards configured control callers through the typed gateway request', function (): void {
        createProcessEditLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            EditProcessRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'process' => [
                            'name' => 'vite',
                            'app' => 'docs',
                            'command' => 'npm run dev',
                            'restart_policy' => 'never',
                            'crash_notification' => 'none',
                        ],
                        'changed' => ['command'],
                        'runtime_units' => [
                            ['name' => 'orbit_docs_main_vite', 'context' => 'main'],
                        ],
                    ],
                    'meta' => ['warnings' => []],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('process:edit', [
            'name' => 'vite',
            '--app' => 'docs',
            '--command' => 'npm run dev',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['changed'])->toBe(['command']);
    });

    it('renders human progress and success prose', function (): void {
        createProcessEditLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite', 'command' => 'npm run dev']);
        app()->instance(RemoteShell::class, new ProcessEditRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $this->artisan('process:edit', [
            'name' => 'vite',
            '--app' => 'docs',
            '--command' => 'npm run dev -- --host=0.0.0.0',
        ])
            ->expectsOutput('┌ Editing Process')
            ->expectsOutput('○ Validate process')
            ->expectsOutput('○ Apply and verify process change')
            ->expectsOutput('○ Render runtime units')
            ->expectsOutput('└ Process updated')
            ->expectsOutput("Process 'vite' updated for app 'docs'")
            ->assertSuccessful();
    });
});

final class ProcessEditRemoteShell implements RemoteShell
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
