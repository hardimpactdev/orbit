<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Problems\ProblemSource;
use App\Domain\SwampDatastore\SwampDatastoreWatch;
use App\Domain\Tasks\TaskExtensionState;
use App\Models\ProblemFingerprint;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Artisan;

/** Fixture fires start 15 minutes apart, in this order. */
function datastoreWatchRun(int $fire, string $status = 'succeeded'): array
{
    return ['id' => sprintf('00000000-0000-4000-8000-%012d', $fire), 'status' => $status,
        'startedAt' => sprintf('2026-11-02T10:%02d:00.000Z', $fire * 15)];
}

function datastoreWatch(): SwampDatastoreWatch
{
    return app(SwampDatastoreWatch::class);
}

/** @return array<string, ProblemFingerprint> */
function datastoreProblems(): array
{
    return ProblemFingerprint::query()->where('source', ProblemSource::Swamp)->get()->keyBy('fingerprint')->all();
}

it('declares the native schedule as one standalone scheduled result', function (): void {
    expect(app(DirectOperation::class)->scheduledResultDefinitions()[SwampDatastoreWatch::Workflow])->toBe([
        'workflow' => 'swamp-datastore-watch', 'terminal_result' => ['job' => 'watch', 'step' => 'measure'],
        'projection' => SwampDatastoreWatch::class, 'workflow_id' => SwampDatastoreWatch::Workflow,
    ]);
});

it('raises one problem per crossed bound and none within them', function (): void {
    datastoreWatch()->applyScheduled(SwampDatastoreWatch::Workflow, datastoreWatchRun(0), [
        'auto_definitions' => ['@swamp/ssh' => 2001, 'command/shell' => 2000, 'swamp/grant' => 2369], 'outputs' => 50001,
        'workflow_runs' => 900, 'data' => 10, 'step_leases' => 60001, 'grants' => 2369, 'serve_cpu_percent' => 50.5,
    ]);

    // Grant records have their own bound, so their type is not reported twice.
    expect(array_keys(datastoreProblems()))->toEqualCanonicalizing([
        'swamp|datastore|auto_definitions|@swamp/ssh', 'swamp|datastore|outputs', 'swamp|datastore|step_leases',
        'swamp|datastore|grants', 'swamp|serve|cpu',
    ])->and(datastoreProblems()['swamp|datastore|step_leases']->evidence)
        ->toMatchArray(['observed' => 60001, 'expected' => 60000, 'swamp_entry' => '.swamp/data/swamp/step-lease'])
        ->and(datastoreProblems()['swamp|datastore|auto_definitions|@swamp/ssh']->evidence)
        ->toMatchArray(['swamp_title' => 'Swamp serve holds 2001 auto-definitions of type @swamp/ssh', 'observed' => 2001, 'expected' => 2000,
            'swamp_entry' => '.swamp/auto-definitions/@swamp/ssh'])
        ->and(datastoreProblems()['swamp|serve|cpu']->evidence['observed'])->toBe(51);
});

it('counts a replayed fire once and resolves a bound once a later fire sees it clear', function (): void {
    $crossed = ['auto_definitions' => ['@swamp/ssh' => 2500], 'outputs' => 10, 'serve_cpu_percent' => 3];
    datastoreWatch()->applyScheduled(SwampDatastoreWatch::Workflow, datastoreWatchRun(0), $crossed);
    datastoreWatch()->applyScheduled(SwampDatastoreWatch::Workflow, datastoreWatchRun(0), $crossed);
    datastoreWatch()->applyScheduled(SwampDatastoreWatch::Workflow, datastoreWatchRun(1), $crossed);

    $problem = datastoreProblems()['swamp|datastore|auto_definitions|@swamp/ssh'];
    expect($problem->occurrences)->toBe(2)->and($problem->evidence)->not->toHaveKey('resolved_at');

    datastoreWatch()->applyScheduled(SwampDatastoreWatch::Workflow, datastoreWatchRun(2), ['auto_definitions' => ['@swamp/ssh' => 400], 'outputs' => 10, 'serve_cpu_percent' => 3]);

    expect($problem->refresh()->evidence['resolution'] ?? null)->toBe('bound_clear');
});

it('raises a problem when a fire fails, so an unwatched datastore is not silent', function (): void {
    datastoreWatch()->applyScheduled(SwampDatastoreWatch::Workflow, datastoreWatchRun(0, 'failed'), []);

    expect(array_keys(datastoreProblems()))->toBe(['swamp|watch|failed'])
        ->and(datastoreProblems()['swamp|watch|failed']->evidence['summary'])->toContain('ended failed');
});

it('files a crossed bound once two fires ten minutes apart saw it', function (): void {
    app(TaskExtensionState::class)->enable();
    Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://example.test/orbit.git', 'default_branch' => 'main']);
    $crossed = ['auto_definitions' => ['@swamp/ssh' => 2500], 'outputs' => 10, 'serve_cpu_percent' => 3];
    datastoreWatch()->applyScheduled(SwampDatastoreWatch::Workflow, datastoreWatchRun(0), $crossed);

    expect(Artisan::call('problems:file'))->toBe(0)->and(Task::topLevel()->count())->toBe(0);

    datastoreWatch()->applyScheduled(SwampDatastoreWatch::Workflow, datastoreWatchRun(1), $crossed);

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->sole()->title)->toBe('Swamp serve holds 2500 auto-definitions of type @swamp/ssh');
});
