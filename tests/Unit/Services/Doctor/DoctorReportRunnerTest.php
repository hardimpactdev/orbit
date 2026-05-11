<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Schedule;
use App\Models\Workspace;
use App\Services\Doctor\DoctorReportRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('DoctorReportRunner', function (): void {
    it('restores workspace PHP-FPM pool mismatches from gateway intent', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
        ]);
        Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature',
            'path' => '/home/orbit/apps/docs/feature',
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
            ->and($shell->scripts[1])->toContain("sudo systemctl reload 'php8.5-fpm'");
    });

    it('suppresses resolved issues when a supported restore completes', function (): void {
        $gateway = Node::factory()->create(['name' => 'gateway-1', 'role' => 'gateway', 'status' => 'active']);
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
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
            ->and($shell->scripts[2])->toContain('[program:orbit_scheduler]');
    });

    it('installs missing tools through restore mode family dispatch', function (): void {
        $gateway = Node::factory()->create(['name' => 'gateway-1', 'role' => 'gateway', 'status' => 'active']);
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
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
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
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
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
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
