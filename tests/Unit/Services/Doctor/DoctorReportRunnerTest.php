<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\Schedule;
use App\Models\WireGuardPeer;
use App\Models\Workspace;
use App\Services\Doctor\DoctorReportRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

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
        $node = createDoctorRunnerAppHostNode();
        $app = App::factory()->create(['node_id' => $node->id]);
        Schedule::factory()->forApp($app)->create();
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "missing\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['schedule']);

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
                'node' => 'app-1',
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
        $node = createDoctorRunnerAppHostNode();
        $app = App::factory()->create(['node_id' => $node->id]);
        Schedule::factory()->forApp($app)->create();
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "missing\n", stderr: '', durationMs: 1),
            new RuntimeException('supervisor update failed'),
        ]));

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['schedule']);

        expect($report['healthy'])->toBeFalse()
            ->and($report['summary'])->toMatchArray([
                'issues' => 1,
                'fixed' => 0,
                'failed' => 1,
                'skipped' => 0,
            ])
            ->and($report['issues'][0])->toMatchArray([
                'family' => 'schedule',
                'node' => 'app-1',
                'key' => 'schedule.scheduler_missing',
            ])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'schedule',
                'node' => 'app-1',
                'key' => 'schedule.scheduler_missing',
                'mode' => 'restore',
                'status' => 'failed',
                'details' => [
                    'error' => 'supervisor update failed',
                ],
            ]);
    });

    it('restores supported node role drift through node family dispatch', function (): void {
        File::deleteDirectory(storage_path('app/orbit/node-development-dns.d'));

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
            ->and(storage_path('app/orbit/node-development-dns.d/test.conf'))->toBeFile();
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

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['database_connection']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 2,
                'skipped' => 0,
            ])
            ->and(collect($report['actions'])->pluck('family')->unique()->all())->toBe(['database_connection'])
            ->and(File::get($path.'/.env'))->toContain('DB_PASSWORD=secret');
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
