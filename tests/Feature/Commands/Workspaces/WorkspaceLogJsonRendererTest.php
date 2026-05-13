<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Models\WorkspaceRun;
use App\Models\WorkspaceRunStep;
use App\Models\WorkspaceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => true]);
});

function createWorkspaceLogJsonRun(array $runOverrides = [], array $stepOverrides = []): WorkspaceRun
{
    Node::factory()->create([
        'name' => 'local-gateway',
        'role' => 'gateway',
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
    $step = WorkspaceStep::factory()->create([
        'app_id' => $app->id,
        'command' => 'Install dependencies',
    ]);
    $run = WorkspaceRun::factory()->create(array_merge([
        'workspace_id' => $workspace->id,
        'status' => 'failed',
        'started_at' => '2026-05-02 10:00:00',
        'completed_at' => '2026-05-02 10:00:12',
    ], $runOverrides));
    WorkspaceRunStep::factory()->create(array_merge([
        'workspace_run_id' => $run->id,
        'workspace_step_id' => $step->id,
        'command' => 'composer install',
        'exit_code' => 1,
        'output' => "Loading repositories\nYour requirements could not be resolved. [TRUNCATED]",
        'started_at' => '2026-05-02 10:00:03',
        'completed_at' => '2026-05-02 10:00:11',
    ], $stepOverrides));

    return $run;
}

describe('workspace:log JSON renderer contract', function (): void {
    it('renders run and step fields under success.data.run', function (): void {
        $run = createWorkspaceLogJsonRun(['status' => 'completed'], [
            'exit_code' => 0,
            'output' => 'Done',
        ]);

        $exitCode = Artisan::call('workspace:log', [
            'run' => (string) $run->id,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $step = $payload['success']['data']['run']['steps'][0];

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['run'])->toMatchArray([
                'workspace' => 'feature-docs',
                'app' => 'docs',
                'node' => 'app-1',
                'type' => 'setup',
                'status' => 'completed',
                'duration_ms' => 12000,
            ])
            ->and($payload['success']['meta']['registry_only'])->toBeTrue()
            ->and($step['name'])->toBe('Install dependencies')
            ->and($step['command'])->toBe('composer install')
            ->and($step['status'])->toBe('success')
            ->and($step['exit_code'])->toBe(0)
            ->and($step['stdout'])->toBe('Done')
            ->and($step['stderr'])->toBe('')
            ->and($step['stdout_truncated'])->toBeFalse()
            ->and($step['stderr_truncated'])->toBeFalse()
            ->and($step['duration_ms'])->toBe(8000);
    });

    it('renders skipped steps without synthesizing output', function (): void {
        $run = createWorkspaceLogJsonRun(stepOverrides: [
            'exit_code' => null,
            'output' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);

        $exitCode = Artisan::call('workspace:log', [
            'run' => (string) $run->id,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $step = $payload['success']['data']['run']['steps'][0];

        expect($exitCode)->toBe(0)
            ->and($step['status'])->toBe('skipped')
            ->and($step['exit_code'])->toBeNull()
            ->and($step['stdout'])->toBe('')
            ->and($step['duration_ms'])->toBeNull();
    });

    it('renders an empty step array as a successful read', function (): void {
        $run = createWorkspaceLogJsonRun();
        WorkspaceRunStep::query()->delete();

        $exitCode = Artisan::call('workspace:log', [
            'run' => (string) $run->id,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['run']['steps'])->toBe([]);
    });
});
