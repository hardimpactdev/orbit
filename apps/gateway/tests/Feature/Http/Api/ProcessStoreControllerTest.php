<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
});

const PROCESS_STORE_CALLER_WG_IP = '10.6.0.89';

function createProcessStoreCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => PROCESS_STORE_CALLER_WG_IP,
        'wireguard_address' => PROCESS_STORE_CALLER_WG_IP,
    ], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

function grantProcessStoreAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['process:add'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('ProcessStoreController', function (): void {
    it('creates process intent for authorized control callers', function (): void {
        $caller = createProcessStoreCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessStoreAccess($caller, $appNode);
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'app' => 'docs',
                'name' => 'vite',
                'command' => 'npm run dev',
                'restart_policy' => 'on_failure',
                'crash_notification' => 'agent_ide',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.name', 'vite')
            ->assertJsonPath('success.data.process.runtime', 'systemd')
            ->assertJsonPath('success.data.runtime_units.0.name', 'orbit_docs_main_vite')
            ->assertJsonPath('success.meta.warnings', []);

        expect(Process::query()->where('name', 'vite')->value('runtime'))->toBe(ProcessRuntime::Systemd);
    });

    it('defaults workspace command processes to systemd for PHP app workspaces', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
            'runtime' => AppRuntimeKind::Php,
        ]);
        $workspace = Workspace::factory()->for($app)->create(['name' => 'feature-docs']);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'app' => 'docs',
                'workspace' => 'feature-docs',
                'name' => 'horizon',
                'command' => 'php artisan horizon',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.name', 'horizon')
            ->assertJsonPath('success.data.process.workspace', 'feature-docs')
            ->assertJsonPath('success.data.process.runtime', 'systemd')
            ->assertJsonPath('success.data.runtime_units.0.name', 'orbit_docs_feature-docs_horizon');

        $process = Process::query()->where('name', 'horizon')->firstOrFail();

        expect($process->owner_type)
            ->toBe($workspace->getMorphClass())
            ->and($process->owner_id)
            ->toBe($workspace->id)
            ->and($process->runtime)
            ->toBe(ProcessRuntime::Systemd);
    });

    it('rejects unauthorized callers before writing intent', function (): void {
        createProcessStoreCallerNode();
        $appNode = createTestAppHostNode();
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'app' => 'docs',
                'name' => 'vite',
                'command' => 'npm run dev',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'process:add');

        expect(Process::query()->exists())->toBeFalse();
    });

    it('denies app callers without a process add grant before writing intent', function (): void {
        $caller = createProcessStoreCallerNode(role: 'app-dev');
        App::factory()->create(['name' => 'docs', 'node_id' => $caller->id]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'app' => 'docs',
                'name' => 'vite',
                'command' => 'npm run dev',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'process:add');
    });

    it('returns validation errors before writing intent', function (array $payload, string $field): void {
        createProcessStoreCallerNode(role: 'gateway');
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call(
            'POST',
            '/api/processes',
            $payload,
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', $field);

        expect(Process::query()->exists())->toBeFalse();
    })->with([
        'missing app' => [['name' => 'vite', 'command' => 'npm run dev'], 'app'],
        'missing name' => [['app' => 'docs', 'command' => 'npm run dev'], 'name'],
        'missing command' => [['app' => 'docs', 'name' => 'vite'], 'command'],
        'invalid restart' => [
            ['app' => 'docs', 'name' => 'vite', 'command' => 'npm run dev', 'restart_policy' => 'sometimes'],
            'restart_policy',
        ],
    ]);

    it('persists and returns an explicit systemd runtime when supplied', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'app' => 'docs',
                'name' => 'legacy',
                'command' => './legacy.sh',
                'runtime' => 'systemd',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response->assertOk()
            ->assertJsonPath('success.data.process.runtime', 'systemd');

        expect(Process::query()->where('name', 'legacy')->value('runtime')->value)->toBe('systemd');
    });

    it('rejects supervisor runtime values before writing intent', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'app' => 'docs',
                'name' => 'legacy',
                'command' => './legacy.sh',
                'runtime' => 'supervisor',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'supervisor')
            ->assertJsonPath('error.meta.allowed', ['docker', 'docker-swarm', 'systemd']);

        expect(Process::query()->where('name', 'legacy')->exists())->toBeFalse();
    });

    it('rejects invalid runtime values with the documented validation envelope', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'app' => 'docs',
                'name' => 'queue',
                'command' => 'php artisan queue:work',
                'runtime' => 'podman',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'podman')
            ->assertJsonPath('error.meta.allowed', ['docker', 'docker-swarm', 'systemd']);

        expect(Process::query()->where('name', 'queue')->exists())->toBeFalse();
    });

    it('rejects docker swarm for app scoped process creation before runtime side effects', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'app' => 'docs',
                'name' => 'mysql8',
                'command' => 'mysqld',
                'runtime' => 'docker-swarm',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'docker-swarm')
            ->assertJsonPath('error.meta.reason', 'docker_swarm_requires_node_owned_process');

        expect(Process::query()->where('name', 'mysql8')->exists())->toBeFalse()->and($remoteShell->scripts)->toBe([]);
    });

    it('rejects docker for app scoped host-command process creation before runtime side effects', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'app' => 'docs',
                'name' => 'queue',
                'command' => 'php artisan queue:work',
                'runtime' => 'docker',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'docker')
            ->assertJsonPath('error.meta.reason', 'docker_runtime_requires_service_or_managed_process');

        expect(Process::query()->where('name', 'queue')->exists())->toBeFalse()->and($remoteShell->scripts)->toBe([]);
    });

    it('rejects docker for workspace scoped host-command process creation before runtime side effects', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Workspace::factory()->for($app)->create(['name' => 'feature-docs']);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'app' => 'docs',
                'workspace' => 'feature-docs',
                'name' => 'queue',
                'command' => 'php artisan queue:work',
                'runtime' => 'docker',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'docker')
            ->assertJsonPath('error.meta.reason', 'docker_runtime_requires_service_or_managed_process');

        expect(Process::query()->where('name', 'queue')->exists())->toBeFalse()->and($remoteShell->scripts)->toBe([]);
    });

    it('creates node owned systemd process intent with an optional tool dependency', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode(['name' => 'app-1']);
        $remoteShell = new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'app-1',
                'name' => 'opencode-server',
                'command' => 'opencode serve --hostname 0.0.0.0',
                'runtime' => 'systemd',
                'tool' => 'opencode',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.name', 'opencode-server')
            ->assertJsonPath('success.data.process.node', 'app-1')
            ->assertJsonPath('success.data.process.tool', 'opencode')
            ->assertJsonPath('success.data.process.runtime', 'systemd')
            ->assertJsonPath('success.data.runtime_units.0.name', 'opencode-server');

        $process = Process::query()->where('name', 'opencode-server')->firstOrFail();

        expect($process->owner_type)
            ->toBe($node->getMorphClass())
            ->and($process->owner_id)
            ->toBe($node->id)
            ->and($process->node_id)
            ->toBe($node->id)
            ->and($process->tool)
            ->toBe('opencode')
            ->and($process->runtime)
            ->toBe(ProcessRuntime::Systemd)
            ->and($remoteShell->scripts[1])
            ->toContain("sudo systemctl enable 'opencode-server.service'");
    });

    it('creates node owned Mailpit managed service processes with SMTP published and UI private', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode([
            'name' => 'beast',
            'wireguard_address' => '10.6.0.7',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'beast',
                'name' => 'mailpit',
                'service' => 'mailpit',
                'runtime' => 'docker',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.name', 'mailpit')
            ->assertJsonPath('success.data.process.node', 'beast')
            ->assertJsonPath('success.data.process.tool', null)
            ->assertJsonPath('success.data.process.runtime', 'docker')
            ->assertJsonPath('success.data.runtime_units.0.name', 'mailpit');

        $process = Process::query()->where('name', 'mailpit')->firstOrFail();

        expect($process->owner_type)
            ->toBe($node->getMorphClass())
            ->and($process->owner_id)
            ->toBe($node->id)
            ->and($process->tool)
            ->toBeNull()
            ->and($process->runtime)
            ->toBe(ProcessRuntime::Docker)
            ->and($process->runtime_config)
            ->toMatchArray([
                'service' => 'mailpit',
                'version_family' => 'latest',
                'version' => 'latest',
            ])
            ->and($process->runtime_config['endpoint'])
            ->toMatchArray([
                'name' => 'smtp',
                'host' => '10.6.0.7',
                'port' => 1025,
            ])
            ->and($process->runtime_config['endpoints'])
            ->toBe([
                [
                    'name' => 'smtp',
                    'kind' => 'tcp',
                    'host' => '10.6.0.7',
                    'port' => 1025,
                ],
            ])
            ->and($process->runtime_config['labels']['orbit.process.service'])
            ->toBe('mailpit')
            ->and($process->command)
            ->toBe('/mailpit');

        $create = collect($remoteShell->scripts)
            ->first(fn (string $script): bool => str_contains($script, 'docker create'));

        expect($create)
            ->toBeString()
            ->toContain("--publish '1025:1025'")
            ->not->toContain('8025:8025');
    });

    it('removes explicit replacement containers before creating a Docker managed service process', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        createTestAppHostNode([
            'name' => 'beast',
            'wireguard_address' => '10.6.0.7',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '{"Name":"dngdmt-mailpit-1"}', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '{"Name":"orbit-mailpit"}', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'beast',
                'name' => 'mailpit',
                'service' => 'mailpit',
                'runtime' => 'docker',
                'replace_containers' => ['dngdmt-mailpit-1', 'orbit-mailpit'],
                'destructive_consent' => true,
                'destructive_consent_source' => 'force',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.name', 'mailpit')
            ->assertJsonPath('success.data.replaced_containers', ['dngdmt-mailpit-1', 'orbit-mailpit']);

        $removeDngdmt = collect($remoteShell->scripts)
            ->search(fn (string $script): bool => str_contains($script, "docker rm -f 'dngdmt-mailpit-1'"));
        $removeOrbit = collect($remoteShell->scripts)
            ->search(fn (string $script): bool => str_contains($script, "docker rm -f 'orbit-mailpit'"));
        $create = collect($remoteShell->scripts)
            ->search(fn (string $script): bool => str_contains($script, 'docker create'));

        expect($removeDngdmt)
            ->not->toBeFalse()->and($removeOrbit)
            ->not->toBeFalse()->and($create)
            ->not->toBeFalse()->and($removeDngdmt)->toBeLessThan($create)->and($removeOrbit)->toBeLessThan($create);
    });

    it('requires destructive consent before replacing containers', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        createTestAppHostNode([
            'name' => 'beast',
            'wireguard_address' => '10.6.0.7',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'beast',
                'name' => 'mailpit',
                'service' => 'mailpit',
                'runtime' => 'docker',
                'replace_containers' => ['dngdmt-mailpit-1'],
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'force')
            ->assertJsonPath('error.meta.reason', 'destructive_consent_required');

        expect(Process::query()->where('name', 'mailpit')->exists())->toBeFalse()->and($remoteShell->scripts)->toBe([]);
    });

    it('does not write process configuration when replacement container removal fails', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        createTestAppHostNode([
            'name' => 'beast',
            'wireguard_address' => '10.6.0.7',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '{"Name":"dngdmt-mailpit-1"}', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'permission denied', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'beast',
                'name' => 'mailpit',
                'service' => 'mailpit',
                'runtime' => 'docker',
                'replace_containers' => ['dngdmt-mailpit-1'],
                'destructive_consent' => true,
                'destructive_consent_source' => 'force',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'process.replace_container_failed')
            ->assertJsonPath('error.meta.container', 'dngdmt-mailpit-1');

        expect(Process::query()->where('name', 'mailpit')->exists())
            ->toBeFalse()
            ->and(collect($remoteShell->scripts)
                ->contains(fn (string $script): bool => str_contains($script, 'docker create')))
            ->toBeFalse();
    });

    it('rejects replacement containers outside node owned Docker managed services', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        createTestAppHostNode([
            'name' => 'beast',
            'wireguard_address' => '10.6.0.7',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'beast',
                'name' => 'mailpit',
                'service' => 'mailpit',
                'runtime' => 'docker-swarm',
                'replace_containers' => ['dngdmt-mailpit-1'],
                'destructive_consent' => true,
                'destructive_consent_source' => 'force',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'replace_containers')
            ->assertJsonPath('error.meta.reason', 'replace_container_requires_node_docker_service');

        expect(Process::query()->where('name', 'mailpit')->exists())->toBeFalse()->and($remoteShell->scripts)->toBe([]);
    });

    it('creates node owned MySQL managed service processes without tool rows', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.44',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'database-1',
                'name' => 'mysql8',
                'service' => 'mysql',
                'version' => '8',
                'runtime' => 'docker-swarm',
                'restart_policy' => 'on_failure',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.name', 'mysql8')
            ->assertJsonPath('success.data.process.node', 'database-1')
            ->assertJsonPath('success.data.process.tool', null)
            ->assertJsonPath('success.data.process.runtime', 'docker-swarm')
            ->assertJsonPath('success.data.runtime_units.0.name', 'orbit-mysql8');

        $process = Process::query()->where('name', 'mysql8')->firstOrFail();

        expect($process->owner_type)
            ->toBe($node->getMorphClass())
            ->and($process->owner_id)
            ->toBe($node->id)
            ->and($process->tool)
            ->toBeNull()
            ->and($process->runtime)
            ->toBe(ProcessRuntime::DockerSwarm)
            ->and($process->runtime_config)
            ->toMatchArray([
                'service' => 'mysql',
                'version_family' => '8',
                'version' => '8.4',
            ])
            ->and($process->runtime_config['endpoint']['host'])
            ->toBe('10.6.0.44')
            ->and($process->runtime_config['endpoint']['port'])
            ->toBe(3308)
            ->and($process->runtime_config['labels']['orbit.process.service'])
            ->toBe('mysql')
            ->and($process->runtime_config['labels']['orbit.process.version_family'])
            ->toBe('8')
            ->and($remoteShell->scripts[0])
            ->toContain('docker service create')
            ->and($remoteShell->scripts[0])
            ->toContain("--label 'orbit.process.service=mysql'");
    });

    it('lets MySQL 8 and MySQL 9 managed services coexist on one node', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.44',
        ]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        foreach ([['mysql8', '8'], ['mysql9', '9']] as [$name, $version]) {
            $this->call(
                'POST',
                '/api/processes',
                [
                    'node' => 'database-1',
                    'name' => $name,
                    'service' => 'mysql',
                    'version' => $version,
                    'runtime' => 'docker',
                ],
                [],
                [],
                ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
            )->assertOk();
        }

        $mysql8 = Process::query()->where('name', 'mysql8')->firstOrFail();
        $mysql9 = Process::query()->where('name', 'mysql9')->firstOrFail();

        expect($mysql8->owner_id)
            ->toBe($node->id)
            ->and($mysql9->owner_id)
            ->toBe($node->id)
            ->and($mysql8->runtime_config['endpoint']['port'])
            ->toBe(3308)
            ->and($mysql9->runtime_config['endpoint']['port'])
            ->toBe(3309)
            ->and($mysql8->runtime_config['spec_hash'])
            ->not->toBe($mysql9->runtime_config['spec_hash']);
    });

    it('rejects invalid managed service input before runtime side effects', function (
        array $payload,
        string $field,
        string $reason,
    ): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode(['name' => 'database-1']);
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            $payload,
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', $field)
            ->assertJsonPath('error.meta.reason', $reason);

        expect(Process::query()->whereIn('name', ['redis', 'mysql8'])->exists())
            ->toBeFalse()
            ->and($remoteShell->scripts)
            ->toBe([]);
    })->with([
        'app owner' => [
            [
                'app' => 'docs',
                'name' => 'redis',
                'service' => 'redis',
                'version' => '7',
                'runtime' => 'docker',
            ],
            'service',
            'process_service_requires_node_owned_process',
        ],
        'tool dependency' => [
            [
                'node' => 'database-1',
                'name' => 'redis',
                'service' => 'redis',
                'version' => '7',
                'runtime' => 'docker',
                'tool' => 'redis',
            ],
            'tool',
            'process_service_cannot_reference_tool',
        ],
        'version without service' => [
            [
                'node' => 'database-1',
                'name' => 'worker',
                'command' => 'php artisan queue:work',
                'version' => '8',
                'runtime' => 'docker',
            ],
            'version',
            'process_service_version_requires_service',
        ],
        'image without service' => [
            [
                'node' => 'database-1',
                'name' => 'worker',
                'command' => 'php artisan queue:work',
                'image' => 'docker.io/library/mysql:8.3',
                'runtime' => 'docker',
            ],
            'image',
            'process_service_image_requires_service',
        ],
        'image with systemd runtime' => [
            [
                'node' => 'database-1',
                'name' => 'node-exporter',
                'service' => 'node-exporter',
                'runtime' => 'systemd',
                'image' => 'prom/node-exporter:v1.11.1',
            ],
            'image',
            'process_service_image_requires_docker_runtime',
        ],
        'managed service node without WireGuard address' => [
            [
                'node' => 'database-1',
                'name' => 'redis',
                'service' => 'redis',
                'version' => '7',
                'runtime' => 'docker',
            ],
            'node',
            'wireguard_address_required',
        ],
    ]);

    it('rejects managed service endpoint conflicts before runtime side effects', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.44',
        ]);
        Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'existing-redis',
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => [
                    'endpoints' => [
                        ['name' => 'existing-redis', 'kind' => 'tcp', 'host' => '10.6.0.44', 'port' => 6379],
                    ],
                ],
            ]);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'database-1',
                'name' => 'redis',
                'service' => 'redis',
                'version' => '7',
                'runtime' => 'docker',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'endpoint_conflict')
            ->assertJsonPath('error.meta.existing_process', 'existing-redis')
            ->assertJsonPath('error.meta.port', 6379);

        expect(Process::query()->where('name', 'redis')->exists())->toBeFalse()->and($remoteShell->scripts)->toBe([]);
    });

    it('returns duplicate process conflicts', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite']);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'app' => 'docs',
                'name' => 'vite',
                'command' => 'npm run dev',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'process.name_collision');
    });

    it('creates node owned MySQL processes from the service selector with default start', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode([
            'name' => 'beast',
            'wireguard_address' => '10.6.0.44',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'beast',
                'name' => 'mysql8',
                'service' => 'mysql',
                'version' => '8.3',
                'runtime' => 'docker',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.name', 'mysql8')
            ->assertJsonPath('success.data.process.node', 'beast')
            ->assertJsonPath('success.data.process.tool', null)
            ->assertJsonPath('success.data.process.runtime', 'docker')
            ->assertJsonPath('success.data.runtime_units.0.name', 'mysql8');

        $process = Process::query()->where('name', 'mysql8')->firstOrFail();

        expect($process->owner_type)
            ->toBe($node->getMorphClass())
            ->and($process->owner_id)
            ->toBe($node->id)
            ->and($process->tool)
            ->toBeNull()
            ->and($process->runtime)
            ->toBe(ProcessRuntime::Docker)
            ->and($process->runtime_config['service'])
            ->toBe('mysql')
            ->and($process->runtime_config['version'])
            ->toBe('8.3')
            ->and($process->runtime_config['image'])
            ->toBe('mysql:8.3')
            ->and($process->runtime_config['command_mode'])
            ->toBe('image_entrypoint')
            ->and($process->runtime_config['ports'][0])
            ->toBe([
                'published' => 3308,
                'target' => 3306,
                'protocol' => 'tcp',
            ])
            ->and(collect($remoteShell->scripts)
                ->contains(fn (string $script): bool => str_contains($script, "--publish '3308:3306'")))
            ->toBeTrue()
            ->and(collect($remoteShell->scripts)
                ->contains(fn (string $script): bool => str_contains($script, "docker start 'mysql8'")))
            ->toBeTrue();
    });

    it('accepts an explicit image override for docker managed service processes', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        createTestAppHostNode([
            'name' => 'beast',
            'wireguard_address' => '10.6.0.44',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'beast',
                'name' => 'mysql8',
                'service' => 'mysql',
                'version' => '8.3',
                'runtime' => 'docker',
                'image' => 'docker.io/library/mysql:8.3',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response->assertOk();

        $process = Process::query()->where('name', 'mysql8')->firstOrFail();

        expect($process->runtime_config['image'])->toBe('docker.io/library/mysql:8.3');
    });

    it('does not start managed service runtime units when no_start is true', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        createTestAppHostNode([
            'name' => 'beast',
            'wireguard_address' => '10.6.0.44',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'beast',
                'name' => 'mysql8',
                'service' => 'mysql',
                'version' => '8.3',
                'runtime' => 'docker',
                'no_start' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response->assertOk();

        expect(collect($remoteShell->scripts)
            ->contains(fn (string $script): bool => str_contains($script, "docker start 'mysql8'")))->toBeFalse();
    });

    it('does not infer managed service from process name alone', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        createTestAppHostNode(['name' => 'beast']);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'beast',
                'name' => 'mysql8',
                'runtime' => 'docker',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'command');

        expect(Process::query()->where('name', 'mysql8')->exists())->toBeFalse()->and($remoteShell->scripts)->toBe([]);
    });

    it('rejects service versions without service before runtime side effects', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        createTestAppHostNode(['name' => 'beast']);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'POST',
            '/api/processes',
            [
                'node' => 'beast',
                'name' => 'worker',
                'command' => 'php artisan queue:work',
                'version' => '8.3',
                'runtime' => 'docker',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'version')
            ->assertJsonPath('error.meta.reason', 'process_service_version_requires_service');

        expect(Process::query()->where('name', 'worker')->exists())->toBeFalse()->and($remoteShell->scripts)->toBe([]);
    });
});

final class ProcessStoreRemoteShell implements RemoteShell
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

        if (str_contains($script, 'sudo systemctl is-enabled "$service"')) {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'exists' => false,
                    'hash' => null,
                    'enabled' => false,
                ], JSON_THROW_ON_ERROR)
                    ."\n",
                stderr: '',
                durationMs: 1,
            );
        }

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
