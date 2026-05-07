<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\ProcessCrashNotification;
use App\Enums\ProcessRestartPolicy;
use App\Http\Gateway\Requests\Processes\AddProcessRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createProcessAddLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'is_local' => true,
    ]);
}

describe('process:add base contract', function (): void {
    it('creates process intent and renders main and workspace runtime units', function (): void {
        createProcessAddLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app', 'name' => 'app-1', 'user' => 'orbit']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/home/orbit/apps/docs']);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id, 'path' => '/home/orbit/apps/docs/workspaces/feature-docs']);

        $remoteShell = new ProcessAddRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:add', [
            'name' => 'vite',
            'processCommand' => 'npm run dev -- --host=0.0.0.0',
            '--app' => 'docs',
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
            ->and($payload['success']['data']['process'])->toMatchArray([
                'name' => 'vite',
                'app' => 'docs',
                'command' => 'npm run dev -- --host=0.0.0.0',
                'restart_policy' => 'always',
                'crash_notification' => 'agent_ide',
            ])
            ->and($payload['success']['data']['runtime_units'])->toBe([
                ['name' => 'orbit_docs_main_vite', 'context' => 'main'],
                ['name' => 'orbit_docs_feature-docs_vite', 'context' => 'feature-docs'],
            ])
            ->and($payload['success']['meta']['warnings'])->toBe([])
            ->and($remoteShell->scripts[1])->toContain('/etc/supervisor/conf.d/orbit_docs_main_vite.conf')
            ->and($remoteShell->scripts[2])->toContain('/etc/supervisor/conf.d/orbit_docs_feature-docs_vite.conf');
    });

    it('appends sort order and applies defaults', function (): void {
        createProcessAddLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'queue', 'sort_order' => 7]);

        app()->instance(RemoteShell::class, new ProcessAddRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        Artisan::call('process:add', [
            'name' => 'vite',
            'processCommand' => 'npm run dev',
            '--app' => 'docs',
            '--json' => true,
        ]);

        $process = Process::query()->where('name', 'vite')->firstOrFail();

        expect($process->sort_order)->toBe(8)
            ->and($process->restart_policy)->toBe(ProcessRestartPolicy::Never)
            ->and($process->crash_notification)->toBe(ProcessCrashNotification::None);
    });

    it('starts rendered runtime units when requested', function (): void {
        createProcessAddLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        $remoteShell = new ProcessAddRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:add', [
            'name' => 'queue',
            'processCommand' => 'php artisan queue:work',
            '--app' => 'docs',
            '--start' => true,
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and(collect($remoteShell->scripts)->contains(fn (string $script): bool => str_contains($script, 'sudo supervisorctl start')))->toBeTrue()
            ->and(collect($remoteShell->scripts)->contains(fn (string $script): bool => str_contains($script, 'orbit_docs_main_queue')))->toBeTrue();
    });

    it('returns success with warnings when runtime enactment fails after intent write', function (): void {
        createProcessAddLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        app()->instance(RemoteShell::class, new ProcessAddRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'failed', durationMs: 1),
        ]));

        $exitCode = Artisan::call('process:add', [
            'name' => 'vite',
            'processCommand' => 'npm run dev',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(Process::query()->where('name', 'vite')->exists())->toBeTrue()
            ->and($payload['success']['meta']['warnings'][0]['code'])->toBe('process.runtime_unit_missing');
    });

    it('rejects invalid input and duplicate names before writing new intent', function (array $arguments, string $field, string $code): void {
        createProcessAddLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'queue']);
        app()->instance(RemoteShell::class, new ProcessAddRemoteShell([]));

        $before = Process::query()->count();

        $exitCode = Artisan::call('process:add', $arguments + ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe($code)
            ->and($payload['error']['meta']['field'] ?? 'name')->toBe($field)
            ->and(Process::query()->count())->toBe($before);
    })->with([
        'missing app' => [['name' => 'vite', 'processCommand' => 'npm run dev'], 'app', 'validation_failed'],
        'missing name' => [['processCommand' => 'npm run dev', '--app' => 'docs'], 'name', 'validation_failed'],
        'missing command' => [['name' => 'vite', '--app' => 'docs'], 'command', 'validation_failed'],
        'invalid name' => [['name' => 'Bad_Name', 'processCommand' => 'npm run dev', '--app' => 'docs'], 'name', 'validation_failed'],
        'duplicate name' => [['name' => 'queue', 'processCommand' => 'php artisan queue:work', '--app' => 'docs'], 'name', 'process.name_collision'],
    ]);

    it('denies app and unknown callers before side effects', function (string $role): void {
        createProcessAddLocalNode($role);
        $remoteShell = new ProcessAddRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $exitCode = Artisan::call('process:add', [
            'name' => 'vite',
            'processCommand' => 'npm run dev',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('caller_role_not_allowed')
            ->and($remoteShell->scripts)->toBe([]);
    })->with(['app', 'weird']);

    it('forwards configured control callers through the typed gateway request', function (): void {
        createProcessAddLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            AddProcessRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'process' => [
                            'name' => 'vite',
                            'app' => 'docs',
                            'command' => 'npm run dev',
                            'restart_policy' => 'never',
                            'crash_notification' => 'none',
                        ],
                        'runtime_units' => [
                            ['name' => 'orbit_docs_main_vite', 'context' => 'main'],
                        ],
                    ],
                    'meta' => ['warnings' => []],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('process:add', [
            'name' => 'vite',
            'processCommand' => 'npm run dev',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['process']['name'])->toBe('vite');
    });

    it('renders human progress and success prose', function (): void {
        createProcessAddLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        app()->instance(RemoteShell::class, new ProcessAddRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 350),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $this->artisan('process:add', [
            'name' => 'vite',
            'processCommand' => 'npm run dev',
            '--app' => 'docs',
        ])
            ->expectsOutputToContain('  ┌  Adding Process')
            ->expectsOutputToContain('  │')
            ->expectsOutputToContain('  ○  Validate process')
            ->expectsOutputToContain('  ○  Create process intent')
            ->expectsOutputToContain('  ○  Render runtime units')
            ->expectsOutputToContain('  ●  Validated process')
            ->expectsOutputToContain('  └  Process added')
            ->expectsOutput("Process 'vite' added for app 'docs'")
            ->assertSuccessful();
    });

    it('renders a decorated process add progress tree', function (): void {
        createProcessAddLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        app()->instance(RemoteShell::class, new ProcessAddRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);

        Artisan::call('process:add', [
            'name' => 'vite',
            'processCommand' => 'npm run dev',
            '--app' => 'docs',
        ], $output);

        $text = $output->fetch();

        expect($text)
            ->toContain('┌')
            ->toContain('│')
            ->toContain('└')
            ->toContain("\e[38;5;242m○  Validate process\e[39m")
            ->toContain("\e[32m●\e[39m")
            ->toContain('Process added');
    });
});

final class ProcessAddRemoteShell implements RemoteShell
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

        $result = array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        usleep($result->durationMs * 1000);

        return $result;
    }
}
