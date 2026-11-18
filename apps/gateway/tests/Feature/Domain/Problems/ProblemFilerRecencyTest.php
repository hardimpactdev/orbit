<?php

declare(strict_types=1);

use App\Domain\Problems\ProblemSource;
use App\Domain\Tasks\TaskExtensionState;
use App\Models\ProblemFingerprint;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-18T12:00:00Z'));
    app(TaskExtensionState::class)->enable();
    Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git']);
});

function recencyProblem(ProblemSource $source, string $fingerprint, int $occurrences, DateTimeInterface $lastSeen, array $evidence = []): ProblemFingerprint
{
    $last = DateTimeImmutable::createFromInterface($lastSeen);

    return ProblemFingerprint::query()->create([
        'source' => $source, 'fingerprint' => $fingerprint, 'occurrences' => $occurrences,
        'first_seen' => $last->modify('-1 day'), 'last_seen' => $last,
        'evidence' => ['observation_counts' => [1, 1], 'observation_times' => [
            $last->modify('-1 day')->format('Y-m-d\TH:i:s\Z'), $last->format('Y-m-d\TH:i:s\Z'),
        ], ...$evidence],
    ]);
}

it('leaves a log fingerprint unfiled when its last occurrence is older than seven days, even at the head of the order', function (): void {
    $stale = recencyProblem(ProblemSource::Log, 'log|RuntimeException|app/Stale.php:10', 50, now()->subDays(8));
    $recent = recencyProblem(ProblemSource::Log, 'log|RuntimeException|app/Recent.php:10', 10, now()->subHour());

    $this->artisan('problems:file')->assertSuccessful();

    $stale->refresh();
    expect($stale->task_group_id)->toBeNull()->and($stale->filed_at)->toBeNull()->and($stale->muted_until)->toBeNull()
        ->and($stale->evidence)->not->toHaveKey('resolved_at')->and($stale->occurrences)->toBe(50)
        ->and($recent->fresh()->task_group_id)->not->toBeNull()
        ->and(Task::topLevel()->count())->toBe(1);
});

it('files the same log fingerprint with the same counts when it last occurred an hour ago', function (): void {
    $row = recencyProblem(ProblemSource::Log, 'log|RuntimeException|app/Stale.php:10', 50, now()->subHour());

    $this->artisan('problems:file')->assertSuccessful();

    expect($row->fresh()->task_group_id)->not->toBeNull()->and($row->fresh()->filed_at)->not->toBeNull()
        ->and(Task::topLevel()->count())->toBe(1);
});

it('rejects rows at the sub-second cutoff in the query too, so they do not fill the ready slots ahead of a recent row', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-18T12:00:00.500Z'));
    // last_seen is stored at whole seconds: 2026-11-11 12:00:00, half a second older than seven days.
    $boundary = collect(range(1, 50))->map(fn (int $index): ProblemFingerprint => recencyProblem(
        ProblemSource::Log, "log|RuntimeException|app/Boundary{$index}.php:10", 100, now()->subDays(7)->subMilliseconds(500),
    ));
    $recent = recencyProblem(ProblemSource::Log, 'log|RuntimeException|app/Recent.php:10', 10, now()->subHour());

    $this->artisan('problems:file')->assertSuccessful();

    expect($recent->fresh()->task_group_id)->not->toBeNull()
        ->and(ProblemFingerprint::query()->whereIn('id', $boundary->pluck('id'))->whereNotNull('task_group_id')->count())->toBe(0)
        ->and(Task::topLevel()->count())->toBe(1);
});

it('applies the window to Swamp findings but not to release alerts, which are pushed once per incident', function (): void {
    $staleSwamp = recencyProblem(ProblemSource::Swamp, 'swamp|stale', 5, now()->subDays(8));
    $recentSwamp = recencyProblem(ProblemSource::Swamp, 'swamp|recent', 5, now()->subHour());
    $release = recencyProblem(ProblemSource::Release, 'release|rollout_halted|fleet-node:1|7', 1, now()->subDays(20), [
        'release_kind' => 'rollout_halted', 'incident_resource' => 'fleet-node:1', 'incident_attempt' => '7', 'incident_fault' => 'converge.failed',
    ]);

    $this->artisan('problems:file')->assertSuccessful();

    expect($staleSwamp->fresh()->task_group_id)->toBeNull()
        ->and($recentSwamp->fresh()->task_group_id)->not->toBeNull()
        ->and($release->fresh()->task_group_id)->not->toBeNull();
});
