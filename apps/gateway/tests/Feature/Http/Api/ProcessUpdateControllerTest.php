<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Nodes\Access\NodePermissionPresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
});

const PROCESS_UPDATE_CALLER_WG_IP = '10.6.0.90';

function createProcessUpdateCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => PROCESS_UPDATE_CALLER_WG_IP,
        'wireguard_address' => PROCESS_UPDATE_CALLER_WG_IP,
    ], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'app-prod' => createTestAppHostNode($attributes, 'app-prod'),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

/**
 * @param  list<string>  $permissions
 */
function grantProcessUpdateAccess(Node $caller, Node $appNode, array $permissions = ['process:update']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('ProcessUpdateController', function (): void {
    it('updates process intent for authorized control callers', function (): void {
        $caller = createProcessUpdateCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessUpdateAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite', 'command' => 'npm run dev']);
        $remoteShell = new ProcessUpdateRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'PATCH',
            '/api/processes/vite',
            [
                'app' => 'docs',
                'command' => 'npm run dev -- --host=0.0.0.0',
                'restart_policy' => 'on_failure',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.command', 'npm run dev -- --host=0.0.0.0')
            ->assertJsonPath('success.data.changed', ['command', 'restart_policy'])
            ->assertJsonPath('success.meta.warnings', []);
    });

    it('rejects unauthorized callers before changing intent', function (): void {
        createProcessUpdateCallerNode();
        $appNode = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite', 'command' => 'npm run dev']);
        app()->instance(RemoteShell::class, new ProcessUpdateRemoteShell([]));

        $response = $this->call(
            'PATCH',
            '/api/processes/vite',
            [
                'app' => 'docs',
                'command' => 'npm run dev -- --host=0.0.0.0',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'process:update');

        expect(Process::query()->where('name', 'vite')->value('command'))->toBe('npm run dev');
    });

    it('denies app callers without a process edit grant before changing intent', function (): void {
        $caller = createProcessUpdateCallerNode(role: 'app-dev');
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $caller->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite', 'command' => 'npm run dev']);
        app()->instance(RemoteShell::class, new ProcessUpdateRemoteShell([]));

        $response = $this->call(
            'PATCH',
            '/api/processes/vite',
            [
                'app' => 'docs',
                'command' => 'npm run dev',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'process:update');
    });

    it('lets app-dev self grants update app-owned process intent on their own node only', function (): void {
        $caller = createProcessUpdateCallerNode(role: 'app-dev');
        $otherNode = createTestAppHostNode(['name' => 'app-2']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $caller->id]);
        $hiddenApp = App::factory()->create(['name' => 'hidden', 'node_id' => $otherNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite', 'command' => 'npm run dev']);
        Process::factory()->forOwner($hiddenApp)->create(['name' => 'queue', 'command' => 'php artisan queue:work']);
        grantProcessUpdateAccess(
            caller: $caller,
            appNode: $caller,
            permissions: app(NodePermissionPresets::class)->permissions('app-dev-self'),
        );
        $remoteShell = new ProcessUpdateRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'PATCH',
            '/api/processes/vite',
            [
                'app' => 'docs',
                'command' => 'npm run dev -- --host=0.0.0.0',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.command', 'npm run dev -- --host=0.0.0.0');

        $denied = $this->call(
            'PATCH',
            '/api/processes/queue',
            [
                'app' => 'hidden',
                'command' => 'php artisan queue:work --queue=critical',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $denied
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'process:update')
            ->assertJsonPath('error.meta.serving_node', 'app-2');

        expect(Process::query()->where('name', 'vite')->value('command'))
            ->toBe('npm run dev -- --host=0.0.0.0')
            ->and(Process::query()->where('name', 'queue')->value('command'))
            ->toBe('php artisan queue:work');
    });

    it('keeps app-prod self grants from updating process intent', function (): void {
        $caller = createProcessUpdateCallerNode(role: 'app-prod');
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $caller->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite', 'command' => 'npm run dev']);
        grantProcessUpdateAccess(
            caller: $caller,
            appNode: $caller,
            permissions: app(NodePermissionPresets::class)->permissions('app-prod-self'),
        );
        app()->instance(RemoteShell::class, new ProcessUpdateRemoteShell([]));

        $response = $this->call(
            'PATCH',
            '/api/processes/vite',
            [
                'app' => 'docs',
                'command' => 'npm run dev -- --host=0.0.0.0',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'process:update')
            ->assertJsonPath('error.meta.serving_node', $caller->name);

        expect(Process::query()->where('name', 'vite')->value('command'))->toBe('npm run dev');
    });

    it('persists and returns the runtime field when supplied', function (): void {
        $caller = createProcessUpdateCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessUpdateAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'queue', 'runtime' => 'docker']);
        $remoteShell = new ProcessUpdateRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'PATCH',
            '/api/processes/queue',
            [
                'app' => 'docs',
                'runtime' => 'systemd',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.runtime', 'systemd')
            ->assertJsonPath('success.data.changed', ['runtime']);

        expect(Process::query()->where('name', 'queue')->value('runtime')->value)->toBe('systemd');
    });

    it('updates node owned systemd process intent', function (): void {
        $caller = createProcessUpdateCallerNode();
        $node = createTestAppHostNode(['name' => 'app-1']);
        grantProcessUpdateAccess($caller, $node);
        Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'opencode-server',
                'command' => 'opencode serve',
                'runtime' => 'systemd',
                'tool' => 'opencode-cli',
            ]);
        $remoteShell = new ProcessUpdateRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'PATCH',
            '/api/processes/opencode-server',
            [
                'node' => 'app-1',
                'command' => 'opencode serve -a',
                'runtime' => 'systemd',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.node', 'app-1')
            ->assertJsonPath('success.data.process.app', null)
            ->assertJsonPath('success.data.process.workspace', null)
            ->assertJsonPath('success.data.process.command', 'opencode serve -a')
            ->assertJsonPath('success.data.process.runtime', 'systemd')
            ->assertJsonPath('success.data.process.tool', 'opencode-cli')
            ->assertJsonPath('success.data.runtime_units.0', ['name' => 'opencode-server', 'context' => 'node']);

        expect(Process::query()->where('name', 'opencode-server')->value('command'))->toBe('opencode serve -a');
    });

    it('renames node owned process identity inside its owner scope', function (): void {
        $caller = createProcessUpdateCallerNode();
        $node = createTestAppHostNode(['name' => 'database-1']);
        grantProcessUpdateAccess($caller, $node);
        $credentialValue = substr(hash('sha256', 'node owned rename'), 0, 16);
        Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'mysql',
                'runtime' => 'docker',
                'runtime_config' => [
                    'service' => 'mysql',
                    'image' => 'mysql:8.4',
                    'target_ports' => [3306],
                    'ports' => [['host' => '10.6.0.20', 'published' => 3308, 'target' => 3306]],
                    'environment' => ['MYSQL_ROOT_PASSWORD' => $credentialValue],
                ],
            ]);
        $remoteShell = new ProcessUpdateRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'PATCH',
            '/api/processes/mysql',
            [
                'node' => 'database-1',
                'name' => 'app-mysql',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.name', 'app-mysql')
            ->assertJsonPath('success.data.old_name', 'mysql')
            ->assertJsonPath('success.data.changed', ['name'])
            ->assertJsonPath('success.meta.warnings', []);

        expect(Process::query()->where('name', 'mysql')->exists())
            ->toBeFalse()
            ->and(Process::query()->where('name', 'app-mysql')->exists())
            ->toBeTrue()
            ->and(implode("\n---\n", $remoteShell->scripts))
            ->toContain('internal:process-docker-container');

        $applyIndex = collect($remoteShell->scripts)
            ->search(fn (string $script): bool => str_contains($script, 'internal:process-docker-container'));
        $cleanupIndex = collect($remoteShell->scripts)
            ->search(fn (string $script): bool => str_contains($script, 'internal:process-docker-container'));

        expect($applyIndex)
            ->not->toBe(false)->and($cleanupIndex)
            ->not->toBe(false);
    });

    it('renames app owned process identity and cleans derived workspace runtime units after re-rendering', function (): void {
        $caller = createProcessUpdateCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessUpdateAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Workspace::factory()->for($app)->create(['name' => 'feature-docs', 'path' => '/srv/docs-feature']);
        Process::factory()
            ->forOwner($app)
            ->create([
                'name' => 'vite',
                'command' => 'npm run dev',
                'runtime' => 'systemd',
            ]);
        $remoteShell = new ProcessUpdateRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'PATCH',
            '/api/processes/vite',
            [
                'app' => 'docs',
                'name' => 'dev-server',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.name', 'dev-server')
            ->assertJsonPath('success.data.old_name', 'vite')
            ->assertJsonPath('success.data.runtime_units.0', [
                'name' => 'orbit_docs_development_main_dev-server',
                'context' => 'main',
            ])
            ->assertJsonPath('success.data.runtime_units.1', [
                'name' => 'orbit_docs_development_feature-docs_dev-server',
                'context' => 'feature-docs',
            ]);

        $scripts = implode("\n---\n", $remoteShell->scripts);

        expect($scripts)
            ->toContain("internal:process-systemd-service 'remove' 'orbit_docs_development_main_vite.service'")
            ->toContain("internal:process-systemd-service 'remove' 'orbit_docs_development_feature-docs_vite.service'");
    });

    it('rejects same-name process rename requests without runtime side effects', function (): void {
        $caller = createProcessUpdateCallerNode();
        $node = createTestAppHostNode(['name' => 'database-1']);
        grantProcessUpdateAccess($caller, $node);
        Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'mysql',
                'runtime' => 'docker',
            ]);
        $remoteShell = new ProcessUpdateRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'PATCH',
            '/api/processes/mysql',
            [
                'node' => 'database-1',
                'name' => 'mysql',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'editable_fields');

        expect(Process::query()->where('name', 'mysql')->exists())
            ->toBeTrue()
            ->and($remoteShell->scripts)
            ->toBeEmpty();
    });

    it('rejects process rename conflicts inside the owner scope before runtime side effects', function (): void {
        $caller = createProcessUpdateCallerNode();
        $node = createTestAppHostNode(['name' => 'database-1']);
        grantProcessUpdateAccess($caller, $node);
        Process::factory()->forOwner($node)->create(['name' => 'mysql', 'runtime' => 'docker']);
        Process::factory()->forOwner($node)->create(['name' => 'app-mysql', 'runtime' => 'docker']);
        $remoteShell = new ProcessUpdateRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'PATCH',
            '/api/processes/mysql',
            [
                'node' => 'database-1',
                'name' => 'app-mysql',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $response
            ->assertConflict()
            ->assertJsonPath('error.code', 'process.name_conflict')
            ->assertJsonPath('error.meta.field', 'name');

        expect(Process::query()->where('name', 'mysql')->exists())
            ->toBeTrue()
            ->and(Process::query()->where('name', 'app-mysql')->count())
            ->toBe(1)
            ->and($remoteShell->scripts)
            ->toBeEmpty();
    });

    it('rejects process renames when the runtime unit has a fixed backend name', function (): void {
        $caller = createProcessUpdateCallerNode();
        $node = createTestAppHostNode(['name' => 'database-1']);
        grantProcessUpdateAccess($caller, $node);
        Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'mysql',
                'runtime' => 'docker-swarm',
                'runtime_config' => [
                    'service_name' => 'orbit-mysql',
                ],
            ]);
        $remoteShell = new ProcessUpdateRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'PATCH',
            '/api/processes/mysql',
            [
                'node' => 'database-1',
                'name' => 'app-mysql',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'process.rename_unsupported')
            ->assertJsonPath('error.meta.field', 'name')
            ->assertJsonPath('error.meta.reason', 'fixed_runtime_unit_name')
            ->assertJsonPath('error.meta.runtime', 'docker-swarm')
            ->assertJsonPath('error.meta.runtime_unit_name', 'orbit-mysql');

        expect(Process::query()->where('name', 'mysql')->exists())
            ->toBeTrue()
            ->and(Process::query()->where('name', 'app-mysql')->exists())
            ->toBeFalse()
            ->and($remoteShell->scripts)
            ->toBeEmpty();
    });

    it('updates workspace owned process intent', function (): void {
        $caller = createProcessUpdateCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessUpdateAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        $workspace = Workspace::factory()->for($app)->create(['name' => 'feature-docs', 'path' => '/srv/docs-feature']);
        Process::factory()
            ->forOwner($workspace)
            ->create([
                'name' => 'worker',
                'command' => 'php artisan queue:work',
                'runtime' => 'systemd',
            ]);
        app()->instance(RemoteShell::class, new ProcessUpdateRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call(
            'PATCH',
            '/api/processes/worker',
            [
                'app' => 'docs',
                'workspace' => 'feature-docs',
                'command' => 'php artisan horizon',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.process.app', 'docs')
            ->assertJsonPath('success.data.process.workspace', 'feature-docs')
            ->assertJsonPath('success.data.process.command', 'php artisan horizon')
            ->assertJsonPath('success.data.runtime_units.0', [
                'name' => 'orbit_docs_development_feature-docs_worker',
                'context' => 'feature-docs',
            ]);

        expect($workspace->processes()->where('name', 'worker')->value('command'))->toBe('php artisan horizon');
    });

    it('rejects invalid runtime values with the documented validation envelope', function (): void {
        $caller = createProcessUpdateCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessUpdateAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'queue', 'runtime' => 'docker']);
        app()->instance(RemoteShell::class, new ProcessUpdateRemoteShell([]));

        $response = $this->call(
            'PATCH',
            '/api/processes/queue',
            [
                'app' => 'docs',
                'runtime' => 'podman',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'podman')
            ->assertJsonPath('error.meta.allowed', ['docker', 'docker-swarm', 'launchd', 'systemd']);

        expect(Process::query()->where('name', 'queue')->value('runtime')->value)->toBe('docker');
    });

    it('rejects supervisor for app scoped process updates before runtime side effects', function (): void {
        $caller = createProcessUpdateCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessUpdateAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'queue', 'runtime' => 'docker']);
        $remoteShell = new ProcessUpdateRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'PATCH',
            '/api/processes/queue',
            [
                'app' => 'docs',
                'runtime' => 'supervisor',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'supervisor')
            ->assertJsonPath('error.meta.allowed', ['docker', 'docker-swarm', 'launchd', 'systemd']);

        expect(Process::query()->where('name', 'queue')->value('runtime')->value)
            ->toBe('docker')
            ->and($remoteShell->scripts)
            ->toBeEmpty();
    });

    it('rejects docker swarm for app scoped process updates before runtime side effects', function (): void {
        $caller = createProcessUpdateCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessUpdateAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'queue', 'runtime' => 'docker']);
        $remoteShell = new ProcessUpdateRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'PATCH',
            '/api/processes/queue',
            [
                'app' => 'docs',
                'runtime' => 'docker-swarm',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'docker-swarm')
            ->assertJsonPath('error.meta.reason', 'docker_swarm_requires_node_owned_process');

        expect(Process::query()->where('name', 'queue')->value('runtime')->value)
            ->toBe('docker')
            ->and($remoteShell->scripts)
            ->toBeEmpty();
    });

    it('rejects docker for app scoped host-command process updates before runtime side effects', function (): void {
        $caller = createProcessUpdateCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessUpdateAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'queue', 'runtime' => 'systemd']);
        $remoteShell = new ProcessUpdateRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'PATCH',
            '/api/processes/queue',
            [
                'app' => 'docs',
                'runtime' => 'docker',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'docker')
            ->assertJsonPath('error.meta.reason', 'docker_runtime_requires_service_or_managed_process');

        expect(Process::query()->where('name', 'queue')->value('runtime')->value)
            ->toBe('systemd')
            ->and($remoteShell->scripts)
            ->toBeEmpty();
    });

    it('rejects docker for workspace scoped host-command process updates before runtime side effects', function (): void {
        $caller = createProcessUpdateCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessUpdateAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        $workspace = Workspace::factory()->for($app)->create(['name' => 'feature-docs']);
        Process::factory()->forOwner($workspace)->create(['name' => 'queue', 'runtime' => 'systemd']);
        $remoteShell = new ProcessUpdateRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'PATCH',
            '/api/processes/queue',
            [
                'app' => 'docs',
                'workspace' => 'feature-docs',
                'runtime' => 'docker',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'docker')
            ->assertJsonPath('error.meta.reason', 'docker_runtime_requires_service_or_managed_process');

        expect($workspace->processes()->where('name', 'queue')->value('runtime')->value)
            ->toBe('systemd')
            ->and($remoteShell->scripts)
            ->toBe([]);
    });

    it('returns validation and not found errors', function (
        array $payload,
        string $processName,
        int $status,
        string $code,
    ): void {
        createProcessUpdateCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite', 'command' => 'npm run dev']);
        app()->instance(RemoteShell::class, new ProcessUpdateRemoteShell([]));

        $response = $this->call(
            'PATCH',
            "/api/processes/{$processName}",
            $payload,
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $response->assertStatus($status)
            ->assertJsonPath('error.code', $code);
    })->with([
        'missing app' => [['command' => 'npm run dev'], 'vite', 422, 'validation_failed'],
        'no editable fields' => [['app' => 'docs'], 'vite', 422, 'validation_failed'],
        'invalid restart' => [['app' => 'docs', 'restart_policy' => 'sometimes'], 'vite', 422, 'validation_failed'],
        'not found' => [['app' => 'docs', 'command' => 'php artisan queue:work'], 'queue', 404, 'process.not_found'],
    ]);

    it('rejects agent ide crash notification updates for existing launchd processes', function (): void {
        $caller = createProcessUpdateCallerNode();
        $appNode = createTestAppHostNode([
            'platform' => 'macos_14',
            'user' => 'nckrtl',
        ]);
        grantProcessUpdateAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()
            ->forOwner($app)
            ->create([
                'name' => 'feedback',
                'runtime' => 'launchd',
                'crash_notification' => 'none',
            ]);
        $remoteShell = new ProcessUpdateRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'PATCH',
            '/api/processes/feedback',
            [
                'app' => 'docs',
                'crash_notification' => 'agent_ide',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'crash_notification')
            ->assertJsonPath('error.meta.reason', 'launchd_crash_notification_deferred');

        expect(Process::query()->where('name', 'feedback')->value('crash_notification')->value)
            ->toBe('none')
            ->and($remoteShell->scripts)
            ->toBeEmpty();
    });
});

final class ProcessUpdateRemoteShell implements RemoteShell
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

        if (str_contains($script, 'internal:process-systemd-service')) {
            return $this->internalSuccessResult([
                'status' => 'ok',
                'summary' => 'Applied systemd service.',
            ]);
        }

        if (str_contains($script, 'internal:process-launchd-service')) {
            return $this->internalSuccessResult([
                'status' => 'ok',
                'summary' => 'Applied launchd service.',
            ]);
        }

        if (str_contains($script, 'internal:process-docker-container')) {
            return $this->internalSuccessResult([
                'outcome' => 'created',
            ]);
        }

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

        if (str_contains($script, 'docker container inspect --format')) {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: '{"Id":"process-container"}'."\n",
                stderr: '',
                durationMs: 1,
            );
        }

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function internalSuccessResult(array $data): RemoteShellResult
    {
        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => [
                    'data' => $data,
                    'meta' => [],
                ],
            ], JSON_THROW_ON_ERROR)
                ."\n",
            stderr: '',
            durationMs: 1,
        );
    }
}
