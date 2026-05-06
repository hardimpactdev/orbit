<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Schedule;
use App\Services\Doctor\DoctorReportRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('DoctorReportRunner', function (): void {
    it('suppresses resolved issues when a supported fix completes', function (): void {
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

        $report = app(DoctorReportRunner::class)->run($gateway, mode: 'fix', families: ['schedule']);

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
                'mode' => 'fix',
                'status' => 'completed',
            ])
            ->and($shell->scripts[2])->toContain('[program:orbit_scheduler]');
    });

    it('records skipped actions for unsupported fix issues while keeping drift visible', function (): void {
        $gateway = Node::factory()->create(['name' => 'gateway-1', 'role' => 'gateway', 'status' => 'active']);
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'redis']);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        ]));

        $report = app(DoctorReportRunner::class)->run($gateway, mode: 'fix', families: ['tool']);

        expect($report['healthy'])->toBeFalse()
            ->and($report['summary'])->toMatchArray([
                'issues' => 1,
                'fixed' => 0,
                'skipped' => 1,
            ])
            ->and($report['issues'][0])->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.capability_missing',
            ])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.capability_missing',
                'mode' => 'fix',
                'status' => 'skipped',
                'details' => [
                    'reason' => 'mode_not_supported',
                ],
            ]);
    });

    it('suppresses resolved tool version issues when a safe update fix completes', function (): void {
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

        $report = app(DoctorReportRunner::class)->run($gateway, mode: 'fix', families: ['tool']);

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
                'mode' => 'fix',
                'status' => 'completed',
            ])
            ->and($shell->scripts[1])->toContain('composer self-update');
    });

    it('keeps the issue visible and records a failed action when a fix throws', function (): void {
        $gateway = Node::factory()->create(['name' => 'gateway-1', 'role' => 'gateway', 'status' => 'active']);
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $app = App::factory()->create(['node_id' => $node->id]);
        Schedule::factory()->forApp($app)->create();
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "missing\n", stderr: '', durationMs: 1),
            new RuntimeException('supervisor update failed'),
        ]));

        $report = app(DoctorReportRunner::class)->run($gateway, mode: 'fix', families: ['schedule']);

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
                'mode' => 'fix',
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
