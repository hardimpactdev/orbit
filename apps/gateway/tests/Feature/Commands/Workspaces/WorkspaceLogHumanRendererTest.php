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

function createWorkspaceLogHumanRun(): WorkspaceRun
{
    Node::factory()->create([
        'name' => 'local-gateway',

        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
    $node = Node::factory()->create(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
    $step = WorkspaceStep::factory()->create([
        'app_id' => $app->id,
        'command' => 'Install dependencies',
    ]);
    $run = WorkspaceRun::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => 'failed',
        'started_at' => '2026-05-02 10:00:00',
        'completed_at' => '2026-05-02 10:00:12',
    ]);
    WorkspaceRunStep::factory()->create([
        'workspace_run_id' => $run->id,
        'workspace_step_id' => $step->id,
        'command' => 'composer install',
        'exit_code' => 1,
        'output' => "Loading repositories\nYour requirements could not be resolved. [TRUNCATED]",
        'started_at' => '2026-05-02 10:00:03',
        'completed_at' => '2026-05-02 10:00:11',
    ]);

    return $run;
}

describe('workspace:log human renderer contract', function (): void {
    it('renders run header, captured step output, exit code, and footer', function (): void {
        $run = createWorkspaceLogHumanRun();

        $exitCode = Artisan::call('workspace:log', ['run' => (string) $run->id]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("Workspace Log Run #{$run->id}  (docs/feature-docs on app-1)")
            ->and($output)->toContain('✘ Install dependencies (composer install)')
            ->and($output)->toContain('[EXIT CODE 1]')
            ->and($output)->toContain('STDOUT:')
            ->and($output)->toContain('> Loading repositories')
            ->and($output)->toContain('[TRUNCATED]')
            ->and($output)->toContain("Run #{$run->id} failed");
    });

    it('renders prose validation failures without a JSON envelope', function (): void {
        $exitCode = Artisan::call('workspace:log', ['run' => '0']);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Workspace run ID must be a positive integer.')
            ->and($output)->not->toContain('"error"');
    });
});
