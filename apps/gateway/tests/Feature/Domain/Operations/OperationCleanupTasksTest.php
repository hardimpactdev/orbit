<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationCleanupReview;
use App\Domain\Operations\OperationVersionCleanup;
use App\Domain\Proof\ProofCommandProjection;
use App\Domain\Tasks\TaskExtensionState;
use App\Models\GatewayRelease;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(LazilyRefreshDatabase::class);

/**
 * Selects the container inventory that the release flow leaves: live, the stopped old, the earlier one kept while live is unverified, and the verified list.
 *
 * @param  list<string>|null  $verified
 */
function selectCleanupRelease(string $root, string $sha, string $outcome, ?string $old = null, ?string $earlier = null, ?array $verified = null): GatewayRelease
{
    $verified ??= array_values(array_filter([in_array($outcome, ['verified', 'reconciled'], true) ? $sha : null, $old, $earlier]));
    file_put_contents($root.'/.orbit/gateway-containers.json', json_encode(['version' => 2, 'live' => $sha, 'old' => $old, 'earlier' => $earlier,
        'verified' => $verified, 'host_retired' => true], JSON_THROW_ON_ERROR));
    file_put_contents($root.'/orbit/REVISION', $sha);

    return GatewayRelease::query()->create(['sha' => $sha, 'requested' => $sha, 'trigger' => 'auto', 'outcome' => $outcome, 'phases' => [], 'duration_ms' => 0]);
}

/**
 * Runs the release workflow's cleanup step for a release: under the release lock, after it verified. It records
 * eligible versions and files cleanup Tasks. Returns the command's exit code.
 */
function cleanupStep(GatewayRelease $release, bool $hostReceipt = false): int
{
    // A host release Operation, the receipt of the first move into a container, names no target.
    $operation = Operation::query()->create(['kind' => 'gateway.release', 'target_type' => 'gateway_release', 'target_id' => $release->id,
        'status' => 'running', 'input' => ['sha' => $release->sha, ...($hostReceipt ? [] : ['target' => 'gateway'])], 'triggered_by' => 'test']);

    return Artisan::call('operations:check-cleanup', ['--release-operation' => $operation->id, '--json' => true]);
}

/** Publishes a release in the inventory without switching the checkout this test process runs. */
function publishCleanupRelease(string $root, string $live, string $old): void
{
    file_put_contents($root.'/.orbit/gateway-containers.json', json_encode(['version' => 2, 'live' => $live, 'old' => $old, 'verified' => [$live, $old], 'host_retired' => true], JSON_THROW_ON_ERROR));
}

/** @param array<string,mixed> $policy */
function useCleanupPolicy(array $policy): void
{
    config(['operation_versions' => ['proof.verify' => $policy]]);
    app()->forgetInstance(DirectOperation::class);
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/orbit-cleanup-tasks-'.bin2hex(random_bytes(5));
    mkdir($this->root.'/.orbit', 0700, true);
    touch($this->root.'/.orbit/gateway-release.lock');
    mkdir($this->root.'/orbit/swamp/workflows', 0700, true);
    $this->sha = str_repeat('a', 40);
    config(['orbit.gateway_checkout' => $this->root.'/orbit/apps/gateway', 'orbit.gateway_container' => true]);
    file_put_contents($this->root.'/orbit/GATEWAY_ORIGIN', 'https://github.com/fixture/orbit.git');
    file_put_contents($this->root.'/orbit/swamp/workflows/mismatched-filename.yaml',
        "id: daace7ba-223f-47b6-9452-7c0da7cf384e\nname: legacy-proof\njobs: [*unresolved]\n");
    $this->policy = ['current' => 2, 'retained' => [1 => ['workflow' => 'legacy-proof', 'projection' => ProofCommandProjection::class]], 'deprecated' => [1]];
    config(['operation_versions' => ['proof.verify' => $this->policy]]);
    app()->forgetInstance(DirectOperation::class);
    app(TaskExtensionState::class)->enable();
    $this->project = Project::query()->create(['name' => 'Gateway source', 'slug' => 'gateway-source', 'repository_url' => 'git@github.com:fixture/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('files one backlog Task at the next verified release step, keeps it after closure and records the removal later', function (): void {
    [$b, $c, $d, $e] = [str_repeat('b', 40), str_repeat('c', 40), str_repeat('d', 40), str_repeat('e', 40)];
    $release = selectCleanupRelease($this->root, $this->sha, 'verified');
    $this->artisan('operations:check-cleanup', ['--fence-only' => true])->assertSuccessful();
    // The recording release never files its own rows.
    expect(cleanupStep($release))->toBe(0)->and(cleanupStep($release))->toBe(0)->and(Task::topLevel()->count())->toBe(0);
    expect(cleanupStep(selectCleanupRelease($this->root, $b, 'verified', old: $this->sha)))->toBe(0);
    $task = Task::topLevel()->sole();
    expect($task->project_id)->toBe($this->project->id)->and($task->status->value)->toBe('backlog')
        ->and($task->brief)->toContain($this->sha, 'legacy-proof', 'daace7ba-223f-47b6-9452-7c0da7cf384e', ProofCommandProjection::class);
    $task->update(['status' => 'cancelled']);
    cleanupStep(selectCleanupRelease($this->root, $c, 'verified', old: $b));
    expect(Task::topLevel()->count())->toBe(1)->and(DB::table('operation_cleanup_tasks')->value('filed_sha'))->toBe($this->sha);
    // D removes the mapping; the next verified release records D against the Task.
    useCleanupPolicy(['current' => 2, 'deprecated' => [1]]);
    cleanupStep(selectCleanupRelease($this->root, $d, 'verified', old: $c));
    expect(DB::table('operation_cleanup_tasks')->value('removed_sha'))->toBeNull();
    cleanupStep(selectCleanupRelease($this->root, $e, 'verified', old: $d));
    expect(DB::table('operation_cleanup_tasks')->value('removed_sha'))->toBe($d)
        ->and(DB::table('deprecated_operation_versions')->where('kind', 'proof.verify')->exists())->toBeTrue();
});

it('records work that drains later at the then-serving release and files it at the release after', function (): void {
    config(['operation_versions' => ['proof.verify' => $this->policy, 'proof.command' => $this->policy]]);
    app()->forgetInstance(DirectOperation::class);
    [$b, $c, $d] = [str_repeat('b', 40), str_repeat('c', 40), str_repeat('d', 40)];
    $old = Operation::query()->create(['kind' => 'proof.command', 'target_type' => 'node', 'target_id' => 1, 'status' => 'running', 'input' => [], 'triggered_by' => 'test']);
    $release = selectCleanupRelease($this->root, $this->sha, 'verified');
    $this->artisan('operations:check-cleanup', ['--fence-only' => true])->assertSuccessful();
    expect(cleanupStep($release))->toBe(75);
    // proof.command v1 in this fixture has no declared child.
    cleanupStep(selectCleanupRelease($this->root, $b, 'verified', old: $this->sha));
    expect(Task::topLevel()->pluck('title')->all())->toBe(['Retire proof.verify v1']);
    $old->update(['status' => 'succeeded']);
    cleanupStep(selectCleanupRelease($this->root, $c, 'verified', old: $b));
    expect(Task::topLevel()->count())->toBe(1);
    cleanupStep(selectCleanupRelease($this->root, $d, 'verified', old: $c));
    expect(Task::topLevel()->orderBy('id')->pluck('title')->all())->toBe(['Retire proof.verify v1', 'Retire proof.command v1']);
});

it('leaves rows unclaimed while Tasks is off, the Project is missing or the version is current, and retries at the next verified release', function (): void {
    [$b, $c, $d] = [str_repeat('b', 40), str_repeat('c', 40), str_repeat('d', 40)];
    $release = selectCleanupRelease($this->root, $this->sha, 'verified');
    $this->artisan('operations:check-cleanup', ['--fence-only' => true])->assertSuccessful();
    // A checkout that no longer serves the release Operation records nothing.
    file_put_contents($this->root.'/orbit/REVISION', $b);
    expect(cleanupStep($release))->toBe(1)->and(DB::table('operation_cleanup_reviews')->count())->toBe(0);
    file_put_contents($this->root.'/orbit/REVISION', $this->sha);
    cleanupStep($release);
    app(TaskExtensionState::class)->disable();
    expect(cleanupStep(selectCleanupRelease($this->root, $b, 'verified', old: $this->sha)))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(0);
    app(TaskExtensionState::class)->enable();
    $this->project->delete();
    expect(cleanupStep(selectCleanupRelease($this->root, $c, 'verified', old: $b)))->toBe(1)
        ->and(DB::table('operation_cleanup_reviews')->where('release_id', $release->id)->whereNull('processed_at')->count())->toBe(1);
    Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/fixture/orbit', 'default_branch' => 'main', 'root' => 'public']);
    config(['operation_versions' => ['proof.verify' => $this->policy, 'proof.command' => ['current' => 1, 'deprecated' => [1]]]]);
    app()->forgetInstance(DirectOperation::class);
    cleanupStep(selectCleanupRelease($this->root, $d, 'verified', old: $c));
    expect(Task::topLevel()->pluck('title')->all())->toBe(['Retire proof.verify v1']);
});

it('isolates an unregistered release origin and replays the migration with pending evidence intact', function (): void {
    $old = selectCleanupRelease($this->root, str_repeat('d', 40), 'verified');
    DB::table('operation_cleanup_reviews')->insert(['release_id' => $old->id, 'repository' => 'https://github.com/fixture/former',
        'kind' => 'proof.verify', 'version' => 1, 'brief' => 'Older checkout.', 'parked' => '[]']);
    $migration = require database_path('migrations/2027_03_08_000006_create_operation_cleanup_tasks.php');
    $migration->up();
    $release = selectCleanupRelease($this->root, $this->sha, 'verified');
    $this->artisan('operations:check-cleanup', ['--fence-only' => true])->assertSuccessful();
    expect(cleanupStep($release))->toBe(1)
        ->and(cleanupStep(selectCleanupRelease($this->root, str_repeat('e', 40), 'verified', old: $this->sha)))->toBe(1);
    expect(Task::topLevel()->sole()->project_id)->toBe($this->project->id)
        ->and(DB::table('operation_cleanup_tasks')->value('filed_sha'))->toBe($this->sha)
        ->and(DB::table('operation_cleanup_reviews')->where('repository', 'https://github.com/fixture/former')->whereNull('processed_at')->value('release_id'))->toBe($old->id);
});

it('records removal of a whole policy while protecting the default v1 mapping', function (): void {
    $policy = ['current' => 3, 'retained' => [2 => $this->policy['retained'][1]], 'deprecated' => [2]];
    config(['operation_versions' => ['proof.command' => $policy, 'proof.verify' => $this->policy]]);
    app()->forgetInstance(DirectOperation::class);
    [$next, $later] = [str_repeat('f', 40), str_repeat('e', 40)];
    $release = selectCleanupRelease($this->root, $this->sha, 'verified');
    $this->artisan('operations:check-cleanup', ['--fence-only' => true])->assertSuccessful();
    cleanupStep($release);
    config(['operation_versions' => []]);
    app()->forgetInstance(DirectOperation::class);
    cleanupStep(selectCleanupRelease($this->root, $next, 'verified', old: $this->sha));
    cleanupStep(selectCleanupRelease($this->root, $later, 'verified', old: $next));
    expect(DB::table('operation_cleanup_tasks')->where('kind', 'proof.command')->value('removed_sha'))->toBe($next)
        ->and(DB::table('operation_cleanup_tasks')->where('kind', 'proof.verify')->value('removed_sha'))->toBeNull();
});

it('files only after a later verified release replaced the stopped release that held the version', function (): void {
    [$before, $next] = [str_repeat('1', 40), str_repeat('2', 40)];
    $release = selectCleanupRelease($this->root, $this->sha, 'verified', old: $before);
    cleanupStep($release);
    cleanupStep($release);
    expect(Task::topLevel()->count())->toBe(0)
        ->and(json_decode((string) DB::table('operation_cleanup_reviews')->value('parked'), true))->toBe([$before]);
    // The next verified release keeps the drained release as old and deletes the one before it.
    cleanupStep(selectCleanupRelease($this->root, $next, 'verified', old: $this->sha));
    expect(Task::topLevel()->pluck('title')->all())->toBe(['Retire proof.verify v1'])
        ->and(DB::table('operation_cleanup_tasks')->value('filed_sha'))->toBe($this->sha);
});

it('files nothing after a rollback to the stopped release that still uses the version', function (): void {
    [$before, $fix] = [str_repeat('1', 40), str_repeat('3', 40)];
    cleanupStep(selectCleanupRelease($this->root, $this->sha, 'verified', old: $before));
    // Rollback starts the stopped old; its code declares v1 current again.
    useCleanupPolicy(['current' => 1]);
    cleanupStep(selectCleanupRelease($this->root, $before, 'verified', old: $this->sha));
    expect(Task::topLevel()->count())->toBe(0);
    // A corrective release replaced both stopped releases, but its code keeps v1 open: the row stays unclaimed.
    useCleanupPolicy(['current' => 2, 'retained' => $this->policy['retained']]);
    DB::table('deprecated_operation_versions')->insertOrIgnore(['kind' => 'proof.verify', 'version' => 1]);
    cleanupStep(selectCleanupRelease($this->root, $fix, 'verified', old: str_repeat('4', 40)));
    expect(Task::topLevel()->count())->toBe(0)
        ->and(DB::table('operation_cleanup_reviews')->whereNull('processed_at')->count())->toBe(1);
    // The filing guard itself, independent of the ledger reopening the fence: only a closed version is reviewable.
    $reviews = app(OperationCleanupReview::class);
    expect($reviews->reviewable('proof.verify', 1, ['current' => 1, 'retained' => [], 'deprecated' => []]))->toBeFalse()
        ->and($reviews->reviewable('proof.verify', 1, ['current' => 2, 'retained' => [1 => []], 'deprecated' => []]))->toBeFalse()
        ->and($reviews->reviewable('proof.verify', 1, ['current' => 2, 'retained' => [1 => []], 'deprecated' => [1]]))->toBeTrue();
});

it('files nothing across two failed releases and resumes after a verified one', function (): void {
    [$before, $failed, $again, $good] = [str_repeat('1', 40), str_repeat('5', 40), str_repeat('6', 40), str_repeat('7', 40)];
    cleanupStep(selectCleanupRelease($this->root, $this->sha, 'verified', old: $before));
    // An unverified live keeps every earlier release stopped, and its step files nothing.
    cleanupStep(selectCleanupRelease($this->root, $failed, 'failed', old: $this->sha, earlier: $before));
    cleanupStep(selectCleanupRelease($this->root, $again, 'failed', old: $this->sha, earlier: $failed));
    expect(Task::topLevel()->count())->toBe(0);
    cleanupStep(selectCleanupRelease($this->root, $good, 'verified', old: $this->sha));
    expect(Task::topLevel()->pluck('title')->all())->toBe(['Retire proof.verify v1']);
});

it('skips a GitHub CLI Project quietly until it uses the GitHub App', function (): void {
    [$before, $next, $later] = [str_repeat('1', 40), str_repeat('8', 40), str_repeat('9', 40)];
    $release = selectCleanupRelease($this->root, $this->sha, 'verified', old: $before);
    cleanupStep($release);
    $this->project->update(['source_access' => 'gh_cli']);
    expect(cleanupStep(selectCleanupRelease($this->root, $next, 'verified', old: $this->sha)))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(0)
        ->and(DB::table('operation_cleanup_reviews')->where('release_id', $release->id)->value('processed_at'))->toBeNull();
    $this->project->update(['source_access' => 'github_app']);
    cleanupStep(selectCleanupRelease($this->root, $later, 'verified', old: $next));
    expect(Task::topLevel()->count())->toBe(1);
});

it('files nothing while the stopped old reverted the deprecation, and contains a malformed row to its repository', function (): void {
    [$before, $revert, $again, $later] = [str_repeat('1', 40), str_repeat('2', 40), str_repeat('3', 40), str_repeat('4', 40)];
    // Filed first: an older release of another repository.
    $other = GatewayRelease::query()->create(['sha' => str_repeat('9', 40), 'requested' => str_repeat('9', 40), 'trigger' => 'auto', 'outcome' => 'verified', 'phases' => [], 'duration_ms' => 0]);
    cleanupStep(selectCleanupRelease($this->root, $this->sha, 'verified', old: $before));
    // Rollback to the stopped old, then M reverts the deprecation and keeps the rollback live as its old.
    useCleanupPolicy(['current' => 1]);
    cleanupStep(selectCleanupRelease($this->root, $before, 'verified', old: $this->sha));
    cleanupStep(selectCleanupRelease($this->root, $revert, 'verified', old: $before));
    // M+1 deprecates v1 again; the stopped M still uses it.
    useCleanupPolicy($this->policy);
    cleanupStep(selectCleanupRelease($this->root, $again, 'verified', old: $revert));
    expect(Task::topLevel()->count())->toBe(0);
    DB::table('operation_cleanup_reviews')->insert(['release_id' => $other->id, 'repository' => 'https://github.com/fixture/former',
        'kind' => 'proof.verify', 'version' => 1, 'brief' => 'Other.', 'parked' => '{not json']);
    Project::query()->create(['name' => 'Former', 'slug' => 'former', 'repository_url' => 'https://github.com/fixture/former', 'default_branch' => 'main', 'root' => 'public']);
    // M+2 verifies and keeps M+1, which closed v1 itself, as its old.
    expect(cleanupStep(selectCleanupRelease($this->root, $later, 'verified', old: $again)))->toBe(1);
    expect(Task::topLevel()->pluck('title')->all())->toBe(['Retire proof.verify v1']);
});

it('keeps filing the other rows of a repository when one of its rows is malformed', function (): void {
    $before = str_repeat('1', 40);
    // An earlier row of the same repository with a broken stopped-release list is processed first.
    $earlier = GatewayRelease::query()->create(['sha' => str_repeat('9', 40), 'requested' => str_repeat('9', 40), 'trigger' => 'auto', 'outcome' => 'verified', 'phases' => [], 'duration_ms' => 0]);
    DB::table('operation_cleanup_reviews')->insert(['release_id' => $earlier->id, 'repository' => 'https://github.com/fixture/orbit',
        'kind' => 'proof.verify', 'version' => 1, 'brief' => 'Broken.', 'parked' => '{not json']);
    cleanupStep(selectCleanupRelease($this->root, $this->sha, 'verified', old: $before));
    expect(cleanupStep(selectCleanupRelease($this->root, str_repeat('2', 40), 'verified', old: $this->sha)))->toBe(1);
    expect(Task::topLevel()->pluck('title')->all())->toBe(['Retire proof.verify v1'])
        ->and(DB::table('operation_cleanup_reviews')->where('release_id', $earlier->id)->value('processed_at'))->toBeNull();
});

it('never files from the scheduler; it only repairs the fence, also with Tasks disabled', function (): void {
    [$before, $next] = [str_repeat('1', 40), str_repeat('2', 40)];
    cleanupStep(selectCleanupRelease($this->root, $this->sha, 'verified', old: $before));
    // The next release serves and its row would be fileable, but only its release step files.
    selectCleanupRelease($this->root, $next, 'verified', old: $this->sha);
    $this->artisan('operations:repair-fence')->assertSuccessful();
    expect(Task::topLevel()->count())->toBe(0)
        ->and(DB::table('operation_cleanup_reviews')->whereNull('processed_at')->count())->toBe(1);
    // A fence that an outgoing release closed again is reopened by the serving release's repair tick.
    app(TaskExtensionState::class)->disable();
    DB::table('deprecated_operation_versions')->delete();
    $this->artisan('operations:repair-fence')->assertSuccessful();
    expect(DB::table('deprecated_operation_versions')->where('kind', 'proof.verify')->where('version', 1)->exists())->toBeTrue();
});

it('keeps an outgoing release from rewriting the fence after the next release reopened a version and published', function (): void {
    selectCleanupRelease($this->root, $this->sha, 'verified');
    $this->artisan('operations:check-cleanup', ['--fence-only' => true])->assertSuccessful();
    expect(DB::table('deprecated_operation_versions')->where('kind', 'proof.verify')->where('version', 1)->exists())->toBeTrue();
    // Release C reopens v1 in its pre-publication fence, then publishes.
    $c = str_repeat('c', 40);
    useCleanupPolicy(['current' => 1]);
    file_put_contents($this->root.'/orbit/REVISION', $c);
    $this->artisan('operations:check-cleanup', ['--fence-only' => true])->assertSuccessful();
    publishCleanupRelease($this->root, $c, $this->sha);
    expect(DB::table('deprecated_operation_versions')->where('kind', 'proof.verify')->exists())->toBeFalse();
    // The outgoing worker keeps its own policy and checkout; neither its check nor its repair tick writes the fence.
    useCleanupPolicy($this->policy);
    file_put_contents($this->root.'/orbit/REVISION', $this->sha);
    $this->artisan('operations:check-cleanup', ['--json' => true])->assertSuccessful();
    $this->artisan('operations:repair-fence')->assertSuccessful();
    expect(DB::table('deprecated_operation_versions')->where('kind', 'proof.verify')->exists())->toBeFalse();
});

it('heals a fence that the outgoing release rewrote before publication on the serving release tick', function (): void {
    selectCleanupRelease($this->root, $this->sha, 'verified');
    $c = str_repeat('c', 40);
    // C fenced with v1 reopened, but has not published: the outgoing release still serves and closes v1 again.
    $this->artisan('operations:check-cleanup', ['--json' => true])->assertSuccessful();
    expect(DB::table('deprecated_operation_versions')->where('kind', 'proof.verify')->exists())->toBeTrue();
    // C publishes. Its next repair tick rewrites the fence from its own policy.
    publishCleanupRelease($this->root, $c, $this->sha);
    useCleanupPolicy(['current' => 1]);
    file_put_contents($this->root.'/orbit/REVISION', $c);
    $this->artisan('operations:repair-fence')->assertSuccessful();
    expect(DB::table('deprecated_operation_versions')->where('kind', 'proof.verify')->exists())->toBeFalse();
});

it('keeps v1 open after a rollback to a release whose fence command predates cleanup Tasks', function (): void {
    $legacy = str_repeat('1', 40);
    selectCleanupRelease($this->root, $this->sha, 'verified', old: $legacy);
    $this->artisan('operations:check-cleanup', ['--fence-only' => true])->assertSuccessful();
    // Rollback: the legacy target's own fence reopens v1, then it publishes.
    DB::table('deprecated_operation_versions')->where('kind', 'proof.verify')->where('version', 1)->delete();
    publishCleanupRelease($this->root, $legacy, $this->sha);
    // The outgoing worker still runs the head code and checkout, and must not close v1 again.
    app(OperationVersionCleanup::class)->check();
    $this->artisan('operations:repair-fence')->assertSuccessful();
    expect(DB::table('deprecated_operation_versions')->where('kind', 'proof.verify')->exists())->toBeFalse();
});

it('gives a release container without a readable inventory no fence-write authority', function (): void {
    selectCleanupRelease($this->root, $this->sha, 'verified');
    unlink($this->root.'/.orbit/gateway-containers.json');
    $this->artisan('operations:repair-fence')->assertSuccessful();
    expect(app(OperationVersionCleanup::class)->serves())->toBeFalse()
        ->and(DB::table('deprecated_operation_versions')->exists())->toBeFalse();
    // An unreadable inventory is no authority either.
    file_put_contents($this->root.'/.orbit/gateway-containers.json', '{not json');
    $this->artisan('operations:repair-fence')->assertSuccessful();
    expect(DB::table('deprecated_operation_versions')->exists())->toBeFalse();
    // Only a host Gateway, which the container runtime does not mark, writes without an inventory.
    unlink($this->root.'/.orbit/gateway-containers.json');
    config(['orbit.gateway_container' => false]);
    $this->artisan('operations:repair-fence')->assertSuccessful();
    expect(DB::table('deprecated_operation_versions')->where('kind', 'proof.verify')->exists())->toBeTrue();
});

it('rejects a null verified list and trusts live and old only when the field is absent', function (): void {
    $before = str_repeat('1', 40);
    $layout = GatewayReleaseLayout::fromConfig();
    file_put_contents($this->root.'/orbit/REVISION', $this->sha);
    file_put_contents($this->root.'/.orbit/gateway-containers.json', json_encode(['version' => 2, 'live' => $this->sha, 'old' => $before,
        'verified' => null, 'host_retired' => true], JSON_THROW_ON_ERROR));
    expect(fn () => $layout->observe($layout->applicationPath))->toThrow(GatewayReleaseException::class)
        ->and(app(OperationVersionCleanup::class)->serves())->toBeFalse();
    file_put_contents($this->root.'/.orbit/gateway-containers.json', json_encode(['version' => 2, 'live' => $this->sha, 'old' => $before,
        'host_retired' => true], JSON_THROW_ON_ERROR));
    expect($layout->observe($layout->applicationPath)->verified)->toBe([$this->sha, $before]);
    // Old and earlier are startable; an inventory from before the rename names earlier pending.
    $earlier = str_repeat('2', 40);
    foreach (['earlier', 'pending'] as $key) {
        file_put_contents($this->root.'/.orbit/gateway-containers.json', json_encode(['version' => 2, 'live' => $this->sha, 'old' => $before,
            $key => $earlier, 'host_retired' => true], JSON_THROW_ON_ERROR));
        expect($layout->observe($layout->applicationPath)->parked)->toBe([$before, $earlier]);
    }
});

it('files nothing in the step of a live release that the inventory does not list as verified', function (): void {
    [$before, $next] = [str_repeat('1', 40), str_repeat('2', 40)];
    cleanupStep(selectCleanupRelease($this->root, $this->sha, 'verified', old: $before));
    cleanupStep(selectCleanupRelease($this->root, $next, 'verified', old: $this->sha, verified: [$this->sha]));
    expect(Task::topLevel()->count())->toBe(0);
});

it('schedules only the fence repair every five minutes, independent of the Tasks extension', function (): void {
    app(TaskExtensionState::class)->disable();
    Artisan::call('schedule:list');
    expect(Artisan::output())->toMatch('/\*\/5\s+\*\s+\*\s+\*\s+\*\s+php artisan operations:repair-fence/')
        ->not->toContain('file-cleanup-tasks');
});

it('accepts the verified host release receipt of the first move into a container, which names no target', function (): void {
    $release = selectCleanupRelease($this->root, $this->sha, 'running');
    $this->artisan('operations:check-cleanup', ['--fence-only' => true])->assertSuccessful();
    // An unverified host receipt is not the serving release.
    expect(fn () => cleanupStep($release, hostReceipt: true))->toThrow(RuntimeException::class, 'different Gateway release');
    expect(DB::table('operation_cleanup_reviews')->count())->toBe(0);
    $release->update(['outcome' => 'verified']);
    expect(cleanupStep($release, hostReceipt: true))->toBe(0)->and(DB::table('operation_cleanup_reviews')->count())->toBe(1);
});

it('runs the scheduled fence repair inline, not through the Gateway queue', function (): void {
    config(['orbit.gateway_queue.connection' => 'gateway-queue']);
    Artisan::call('schedule:list');
    expect(Artisan::output())->toMatch('/\*\/5\s+\*\s+\*\s+\*\s+\*\s+php artisan operations:repair-fence/');
});

it('skips the scheduled repair while a release switch holds the release lock, in both policy directions', function (): void {
    selectCleanupRelease($this->root, $this->sha, 'verified');
    $this->artisan('operations:check-cleanup', ['--fence-only' => true])->assertSuccessful();
    // The switch holds the host's release lock from before the candidate fences until it has published.
    $switch = fopen($this->root.'/.orbit/gateway-release.lock', 'r');
    expect(flock($switch, LOCK_EX | LOCK_NB))->toBeTrue();
    // The candidate reopened v1; the outgoing release, still live in the inventory, would close it again.
    DB::table('deprecated_operation_versions')->delete();
    $this->artisan('operations:repair-fence')->assertSuccessful();
    expect(DB::table('deprecated_operation_versions')->exists())->toBeFalse();
    // The candidate closed v1, which the outgoing release still runs as current; the repair would reopen it.
    $this->artisan('operations:check-cleanup', ['--fence-only' => true])->assertSuccessful();
    useCleanupPolicy(['current' => 1]);
    $this->artisan('operations:repair-fence')->assertSuccessful();
    expect(DB::table('deprecated_operation_versions')->where('kind', 'proof.verify')->where('version', 1)->exists())->toBeTrue();
    // The switch runs the repair itself under the lock it holds.
    $this->artisan('operations:repair-fence', ['--release-lock-held' => true])->assertSuccessful();
    expect(DB::table('deprecated_operation_versions')->exists())->toBeFalse();
    flock($switch, LOCK_UN);
    fclose($switch);
    // Once the lock is free, the tick repairs again.
    useCleanupPolicy($this->policy);
    $this->artisan('operations:repair-fence')->assertSuccessful();
    expect(DB::table('deprecated_operation_versions')->where('kind', 'proof.verify')->exists())->toBeTrue();
});

it('rejects malformed stopped-release evidence inside the row handler', function (string $parked): void {
    $before = str_repeat('1', 40);
    $earlier = GatewayRelease::query()->create(['sha' => str_repeat('9', 40), 'requested' => str_repeat('9', 40), 'trigger' => 'auto', 'outcome' => 'verified', 'phases' => [], 'duration_ms' => 0]);
    DB::table('operation_cleanup_reviews')->insert(['release_id' => $earlier->id, 'repository' => 'https://github.com/fixture/orbit',
        'kind' => 'proof.verify', 'version' => 1, 'brief' => 'Malformed.', 'parked' => $parked]);
    cleanupStep(selectCleanupRelease($this->root, $this->sha, 'verified', old: $before));
    expect(cleanupStep(selectCleanupRelease($this->root, str_repeat('2', 40), 'verified', old: $this->sha)))->toBe(1);
    // Only the valid row filed; the malformed row stays unclaimed.
    expect(Task::topLevel()->pluck('brief')->all())->not->toContain('Malformed.')
        ->and(Task::topLevel()->count())->toBe(1)
        ->and(DB::table('operation_cleanup_reviews')->where('release_id', $earlier->id)->value('processed_at'))->toBeNull();
})->with([
    'an empty object' => ['{}'],
    'an object' => ['{"old":"'.str_repeat('1', 40).'"}'],
    'a string' => ['"'.str_repeat('1', 40).'"'],
    'an invalid commit' => ['["bad-sha"]'],
    'a short commit' => ['["'.str_repeat('1', 12).'"]'],
    'an uppercase commit' => ['["'.str_repeat('A', 40).'"]'],
]);

it('skips the scheduled repair after an interrupted publication until the handover marker is gone', function (): void {
    selectCleanupRelease($this->root, $this->sha, 'verified');
    $this->artisan('operations:check-cleanup', ['--fence-only' => true])->assertSuccessful();
    // The switch fenced (v1 reopened), routed traffic and stopped before writing the inventory: the lock is free,
    // the inventory still names the outgoing release live, and the marker remains.
    file_put_contents($this->root.'/.orbit/gateway-handover', '42 '.str_repeat('c', 40)."\n");
    DB::table('deprecated_operation_versions')->delete();
    $this->artisan('operations:repair-fence')->assertSuccessful();
    expect(DB::table('deprecated_operation_versions')->exists())->toBeFalse();
    // --recover writes the inventory and removes the marker; the repair runs again.
    unlink($this->root.'/.orbit/gateway-handover');
    $this->artisan('operations:repair-fence')->assertSuccessful();
    expect(DB::table('deprecated_operation_versions')->where('kind', 'proof.verify')->exists())->toBeTrue();
});
