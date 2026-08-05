<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspaceEnvApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('applies values only to the selected workspace path', function (): void {
    $node = Node::factory()->gateway()->create(['status' => 'active']);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);
    $app = App::factory()->for($node, 'node')->create([
        'runtime' => AppRuntimeKind::Static,
    ]);
    $instance = Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            path: $app->path,
            domain: $app->domain,
        ),
    ]);
    $path = storage_path('framework/testing/workspace-env-applier');
    File::ensureDirectoryExists($path);
    File::put($path.'/.env', "APP_ENV=local\n");
    $workspace = Workspace::factory()
        ->for($app)
        ->for($instance, 'instance')
        ->create(['path' => $path]);

    $result = app(WorkspaceEnvApplier::class)->apply($workspace, [
        'APP_ENV' => 'production',
    ]);

    expect($result->envPath)
        ->toBe($path.'/.env')
        ->and(File::get($path.'/.env'))
        ->toBe("APP_ENV=production\n");
});

it('applies values to a registered nested development worktree path and preserves unrelated keys and mode', function (): void {
    $node = Node::factory()->gateway()->create(['status' => 'active']);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'billing',
        'path' => '/home/orbit-test-user/apps/billing',
        'runtime' => AppRuntimeKind::Static,
    ]);
    $instance = Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            path: '/home/orbit-test-user/apps/billing-development',
            domain: 'billing-development.test',
        ),
    ]);
    $path = storage_path('framework/testing/workspace-env-applier-worktree/billing/.worktrees/feature-mail');
    File::ensureDirectoryExists($path);
    $envPath = $path.'/.env';
    File::put($envPath, "APP_ENV=local\nKEEP_ME=yes\n");
    chmod($envPath, 0o640);
    $workspace = Workspace::factory()
        ->for($app)
        ->for($instance, 'instance')
        ->create([
            'name' => 'feature-mail',
            'path' => $path,
        ]);

    $applier = app(WorkspaceEnvApplier::class);
    $first = $applier->apply($workspace, [
        'APP_ENV' => 'testing',
    ]);
    $second = $applier->apply($workspace, [
        'APP_ENV' => 'testing',
    ]);

    $afterSecond = File::get($envPath);
    $appEnvMatches = preg_match_all('/^APP_ENV=/m', $afterSecond);

    expect($first->envPath)
        ->toBe($envPath)
        ->and(File::get($envPath))
        ->toBe("APP_ENV=testing\nKEEP_ME=yes\n")
        ->and(fileperms($envPath) & 0o777)
        ->toBe(0o640)
        ->and($second->envPath)
        ->toBe($envPath)
        ->and($afterSecond)
        ->toBe("APP_ENV=testing\nKEEP_ME=yes\n")
        ->and($appEnvMatches)
        ->toBe(1)
        ->and(fileperms($envPath) & 0o777)
        ->toBe(0o640);
});

it('aborts apply before writing when a remote env read returns a successful envelope without string contents', function (): void {
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create(['status' => 'active']);
    $app = App::factory()->for($node, 'node')->create([
        'runtime' => AppRuntimeKind::Static,
    ]);
    $instance = Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            path: $app->path,
            domain: $app->domain,
        ),
    ]);
    $workspace = Workspace::factory()
        ->for($app)
        ->for($instance, 'instance')
        ->create([
            'name' => 'feature-protocol',
            'path' => '/home/orbit-test-user/apps/demo/.worktrees/feature-protocol',
        ]);

    $executor = new class implements \App\Services\RemoteShell\RunsInternalCommands {
        /** @var list<string> */
        public array $actions = [];

        public function runInternal(
            \App\Models\Node $node,
            string $commandName,
            array $arguments = [],
            array $commandOptions = [],
            array $transportOptions = [],
        ): \App\Data\RemoteShell\RemoteShellResult {
            $input = $transportOptions['input'] ?? null;
            $payload = is_string($input)
                ? json_decode($input, associative: true, flags: JSON_THROW_ON_ERROR)
                : [];
            $action = is_array($payload) && is_string($payload['action'] ?? null)
                ? $payload['action']
                : 'unknown';
            $this->actions[] = $action;

            if ($action === 'read') {
                return new \App\Data\RemoteShell\RemoteShellResult(
                    exitCode: 0,
                    stdout: json_encode([
                        'success' => [
                            'data' => [
                                'path' => is_array($payload) ? $payload['path'] ?? null : null,
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR)
                        ."\n",
                    stderr: '',
                    durationMs: 1,
                );
            }

            return new \App\Data\RemoteShell\RemoteShellResult(
                exitCode: 0,
                stdout: json_encode(['success' => ['data' => ['bytes' => 1]]], JSON_THROW_ON_ERROR)."\n",
                stderr: '',
                durationMs: 1,
            );
        }
    };

    app()->instance(
        \App\Services\RemoteShell\RemoteEnvFile::class,
        new \App\Services\RemoteShell\RemoteEnvFile($executor),
    );

    expect(fn () => app(WorkspaceEnvApplier::class)->apply($workspace, ['APP_ENV' => 'testing']))
        ->toThrow(\App\Services\Workspaces\WorkspaceEnvApplyException::class);

    // WorkspaceEnvApplier uses readForApply; a malformed success must not write.
    expect($executor->actions)
        ->toBe(['read'])
        ->not->toContain('write');
});

it('derives the env path only from the registered workspace path field', function (): void {
    $node = Node::factory()->gateway()->create(['status' => 'active']);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);
    $app = App::factory()->for($node, 'node')->create([
        'runtime' => AppRuntimeKind::Static,
    ]);
    $instance = Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            path: $app->path,
            domain: $app->domain,
        ),
    ]);
    $registeredPath = storage_path('framework/testing/workspace-env-registered-path');
    $workspace = Workspace::factory()
        ->for($app)
        ->for($instance, 'instance')
        ->create(['path' => $registeredPath]);

    expect(app(WorkspaceEnvApplier::class)->envPath($workspace))
        ->toBe($registeredPath.'/.env')
        ->not->toBe('/home/orbit/apps/sibling/.env')
        ->not->toBe('/tmp/client-supplied/.env');
});
