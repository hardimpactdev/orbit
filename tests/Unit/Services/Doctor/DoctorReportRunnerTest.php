<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\WireGuardPeer;
use App\Models\Workspace;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Nodes\DevelopmentDnsMappingProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $developmentDnsConfigDir = storage_path('framework/testing/doctor-runner-dns/'.bin2hex(random_bytes(6)));
    $developmentDnsMappingEnactor = new DevelopmentDnsMappingEnactor($developmentDnsConfigDir);

    app()->instance(DevelopmentDnsMappingEnactor::class, $developmentDnsMappingEnactor);
    app()->instance(DevelopmentDnsMappingProbe::class, new DevelopmentDnsMappingProbe($developmentDnsMappingEnactor));
});

afterEach(function (): void {
    File::deleteDirectory(app(DevelopmentDnsMappingEnactor::class)->configDir());
});

function createDoctorRunnerAppHostNode(array $attributes = []): Node
{
    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
        ...$attributes,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-development',
        'status' => 'active',
        'settings' => ['tld' => 'test'],
    ]);

    return $node;
}

function markDoctorRunnerNodeSecurityBaselineClean(Node $node): void
{
    $node->forceFill([
        'user' => 'orbit',
        'host_key_type' => 'ed25519',
        'host_key_public' => 'ssh-ed25519 AAAATEST',
        'host_key_fingerprint' => 'SHA256:test',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
    ])->save();

    foreach (['v4', 'v6'] as $addressFamily) {
        FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => "orbit-public-ssh-deny-{$addressFamily}",
            'direction' => 'incoming',
            'action' => 'deny',
            'source' => 'any',
            'port' => '22',
            'protocol' => 'tcp',
            'source_hash' => hash('sha256', "orbit-public-ssh-deny-{$node->id}-{$addressFamily}"),
            'address_family' => $addressFamily,
            'interface' => 'public',
            'owner' => 'node-security',
            'protected' => true,
        ]);
    }
}

describe('DoctorReportRunner', function (): void {
    it('restores workspace PHP-FPM pool mismatches from gateway intent', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
        ]);
        Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature',
            'path' => '/home/orbit/apps/docs/.worktrees/feature',
        ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "feature\t1\t1\t1\t1\t0\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['workspace']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'workspace',
                'node' => 'app-1',
                'key' => 'workspace.fpm_config_mismatch',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($shell->scripts[1])->toContain('/etc/php/8.5/fpm/pool.d/orbit-docs-feature.conf')
            ->and($shell->scripts[1])->toContain("PHP_FPM_SERVICE='php8.5-fpm'")
            ->and($shell->scripts[1])->toContain('sudo rm -f "$ORBIT_STALE_POOL"')
            ->and($shell->scripts[1])->toContain('sudo systemctl restart "$PHP_FPM_SERVICE"');
    });

    it('suppresses resolved issues when a supported restore completes', function (): void {
        $gateway = Node::factory()->create(['name' => 'gateway-1', 'role' => 'gateway', 'status' => 'active']);
        NodeRoleAssignment::factory()->create([
            'node_id' => $gateway->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "missing\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($gateway, mode: 'restore', families: ['schedule']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'failed' => 0,
                'skipped' => 0,
            ])
            ->and($report['issues'])->toBe([])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'schedule',
                'node' => 'gateway-1',
                'key' => 'schedule.scheduler_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and(base64_decode((string) str($shell->scripts[2])->match("/printf %s\\s+'([^']+)'/")->toString(), true))->toContain('[program:orbit_scheduler]');
    });

    it('installs missing tools through restore mode family dispatch', function (): void {
        $gateway = Node::factory()->create(['name' => 'gateway-1', 'role' => 'gateway', 'status' => 'active']);
        $node = createDoctorRunnerAppHostNode();
        NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'redis']);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['tool']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['issues'])->toBe([])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.capability_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($shell->scripts[1])->toContain("docker compose -f '/opt/orbit/docker-compose.yml' pull 'redis'");
    });

    it('suppresses resolved tool version issues when a safe update restore completes', function (): void {
        $gateway = Node::factory()->create(['name' => 'gateway-1', 'role' => 'gateway', 'status' => 'active']);
        $node = createDoctorRunnerAppHostNode();
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'composer',
            'expected_version' => '3.0',
        ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "/usr/local/bin/composer\tComposer version 2.8.0\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['tool']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['issues'])->toBe([])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.version_mismatch',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($shell->scripts[1])->toContain('composer self-update');
    });

    it('keeps the issue visible and records a failed action when a restore throws', function (): void {
        $gateway = Node::factory()->create(['name' => 'gateway-1', 'role' => 'gateway', 'status' => 'active']);
        NodeRoleAssignment::factory()->create([
            'node_id' => $gateway->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "missing\n", stderr: '', durationMs: 1),
            new RuntimeException('supervisor update failed'),
        ]));

        $report = app(DoctorReportRunner::class)->run($gateway, mode: 'restore', families: ['schedule']);

        expect($report['healthy'])->toBeFalse()
            ->and($report['summary'])->toMatchArray([
                'issues' => 1,
                'fixed' => 0,
                'failed' => 1,
                'skipped' => 0,
            ])
            ->and($report['issues'][0])->toMatchArray([
                'family' => 'schedule',
                'node' => 'gateway-1',
                'key' => 'schedule.scheduler_missing',
            ])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'schedule',
                'node' => 'gateway-1',
                'key' => 'schedule.scheduler_missing',
                'mode' => 'restore',
                'status' => 'failed',
                'details' => [
                    'error' => 'supervisor update failed',
                ],
            ]);
    });

    it('restores supported node role drift through node family dispatch', function (): void {
        File::deleteDirectory(app(DevelopmentDnsMappingEnactor::class)->configDir());

        $node = Node::factory()->create([
            'name' => 'app-1',
            'role' => 'control',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'host' => '10.0.0.1',
            'wireguard_address' => '10.6.0.5',
        ]);
        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);
        markDoctorRunnerNodeSecurityBaselineClean($node);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
        ]));

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['node']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'node',
                'node' => 'app-1',
                'key' => 'node.role_baseline_mismatch',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and(app(DevelopmentDnsMappingEnactor::class)->configDir().'/test.conf')->toBeFile();
    });

    it('skips unsupported node role drift during restore', function (): void {
        $node = Node::factory()->create([
            'name' => 'app-1',
            'role' => 'control',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'host' => '10.0.0.1',
            'wireguard_address' => '10.6.0.5',
        ]);
        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);
        markDoctorRunnerNodeSecurityBaselineClean($node);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => 'active',
            'settings' => [],
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
        ]));

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['node']);

        expect($report['healthy'])->toBeFalse()
            ->and($report['summary'])->toMatchArray([
                'issues' => 1,
                'fixed' => 0,
                'skipped' => 1,
            ])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'node',
                'node' => 'app-1',
                'key' => 'node.role_settings_invalid',
                'mode' => 'restore',
                'status' => 'skipped',
            ]);
    });

    it('supports the database connection family on app nodes but not database-only nodes', function (): void {
        $appNode = createDoctorRunnerAppHostNode();
        $databaseNode = Node::factory()->create(['role' => 'database', 'status' => 'active']);

        $runner = app(DoctorReportRunner::class);

        expect($runner->supportedFamilies())->toContain('database_connection')
            ->and($runner->categoriesForNode($appNode))->toContain('database_connection')
            ->and($runner->categoriesForNode($databaseNode))->not->toContain('database_connection');
    });

    it('does not mark database connection unverifiable issues as adoptable', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $path = storage_path('framework/testing/doctor-database-unverifiable');
        File::ensureDirectoryExists($path);

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'missing env', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'missing env', durationMs: 1),
        ]));

        $report = app(DoctorReportRunner::class)->probe($node, ['database_connection']);
        $issue = collect($report['issues'])->firstWhere('key', 'database_connection.unverifiable');

        expect($issue)->not->toBeNull()
            ->and($issue['adoptable'] ?? null)->toBeFalse();
    });

    it('restores database connection env drift through family dispatch', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $path = storage_path('framework/testing/doctor-database-restore');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=mysql\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['database_connection']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 2,
                'skipped' => 0,
            ])
            ->and(collect($report['actions'])->pluck('family')->unique()->all())->toBe(['database_connection'])
            ->and(collect($shell->scripts)->contains(fn (string $script): bool => str_contains($script, 'base64 -d')))->toBeTrue();
    });

    it('restores missing database connection target mappings through family dispatch', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $path = storage_path('framework/testing/doctor-database-target-missing');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n", stderr: '', durationMs: 1),
        ]));

        $probe = app(DoctorReportRunner::class)->probe($node, ['database_connection']);
        $issue = collect($probe['issues'])->firstWhere('key', 'database_connection.target_missing');

        expect($issue)->not->toBeNull()
            ->and($issue['restorable'] ?? null)->toBeTrue();

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['database_connection']);

        expect($report['healthy'])->toBeTrue()
            ->and(DatabaseConnectionTarget::query()
                ->where('database_connection_id', $connection->id)
                ->where('app_id', $app->id)
                ->where('env_prefix', 'DB')
                ->exists())->toBeTrue();
    });

    it('adopts database connection env state for registered apps through family dispatch', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $path = storage_path('framework/testing/doctor-database-adopt');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n");

        App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n", stderr: '', durationMs: 1),
        ]));

        $report = app(DoctorReportRunner::class)->run($node, mode: 'adopt', families: ['database_connection']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'adopted' => 1,
                'skipped' => 0,
            ])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'database_connection',
                'node' => 'app-1',
                'mode' => 'adopt',
            ])
            ->and(DatabaseConnection::query()->where('slug', 'docs')->exists())->toBeTrue();
    });

    it('adopt mode updates gateway database connections from mismatched env without restoring env files', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $path = storage_path('framework/testing/doctor-database-adopt-mismatch');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=mysql\nDB_HOST=observed-host\nDB_PORT=3306\nDB_DATABASE=docs_v2\nDB_USERNAME=observed-user\nDB_PASSWORD=observed-secret\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'stored-host',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'stored-user',
            'credentials' => ['password' => 'stored-secret'],
        ]);
        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);
        $original = File::get($path.'/.env');
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\nDB_HOST=observed-host\nDB_PORT=3306\nDB_DATABASE=docs_v2\nDB_USERNAME=observed-user\nDB_PASSWORD=observed-secret\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\nDB_HOST=observed-host\nDB_PORT=3306\nDB_DATABASE=docs_v2\nDB_USERNAME=observed-user\nDB_PASSWORD=observed-secret\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\nDB_HOST=observed-host\nDB_PORT=3306\nDB_DATABASE=docs_v2\nDB_USERNAME=observed-user\nDB_PASSWORD=observed-secret\n", stderr: '', durationMs: 1),
        ]));

        $report = app(DoctorReportRunner::class)->run($node, mode: 'adopt', families: ['database_connection']);

        $connection->refresh();

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'adopted' => 1,
                'skipped' => 0,
            ])
            ->and(File::get($path.'/.env'))->toBe($original)
            ->and($connection)->toMatchArray([
                'driver' => 'mysql',
                'host' => 'observed-host',
                'port' => 3306,
                'database' => 'docs_v2',
                'username' => 'observed-user',
            ])
            ->and($connection->credentials)->toMatchArray(['password' => 'observed-secret']);
    });

    it('returns a failed action when database connection restore throws', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $path = storage_path('framework/testing/doctor-database-restore-failure');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=mysql\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'permission denied', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'permission denied', durationMs: 1),
        ]));

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['database_connection']);
        $failedAction = collect($report['actions'])->firstWhere('status', 'failed');

        expect($report['healthy'])->toBeFalse()
            ->and($report['summary']['failed'])->toBeGreaterThanOrEqual(1)
            ->and($failedAction)->toMatchArray([
                'family' => 'database_connection',
                'node' => 'app-1',
                'mode' => 'restore',
                'status' => 'failed',
            ])
            ->and($failedAction['key'])->toBeIn(['database_connection.env_missing', 'database_connection.env_mismatch'])
            ->and($failedAction)->toMatchArray([
                'mode' => 'restore',
                'status' => 'failed',
            ])
            ->and($failedAction['details']['error'] ?? null)->toContain('permission denied');
    });
});

final class DoctorReportRunnerRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  list<RemoteShellResult|Throwable>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $result = array_shift($this->results);

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
