<?php

declare(strict_types=1);

use App\Actions\Fleet\MergeNightlyRecipePullRequestsAction;
use App\Actions\Fleet\NightlyRecipeContents;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\MachineSoftware\SnapshotAvailability;
use App\Jobs\MaintainMachineRecipe;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\Fleet\FakeUbuntuSnapshot;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Fleet\RecordingReleaseAlertNotifier;
use Tests\Support\TaskVmSettingsBuilder;

/** @return array<string, mixed> */
function nightlyMergeEvidence(): array
{
    $before = file_get_contents(resource_path('machine-software/recipe.json'));
    $document = json_decode($before, true, flags: JSON_THROW_ON_ERROR);
    $snapshot = $document['ubuntu']['snapshot'];
    $release = $document['ubuntu']['release'];
    $date = new DateTimeImmutable($snapshot)->modify('+1 day');
    $next = $date->format('Ymd\THis\Z');
    $after = str_replace($snapshot, $next, $before);
    $bot = ['id' => 4243, 'login' => 'orbit-acme[bot]', 'type' => 'Bot'];
    $sha = str_repeat('b', 40);
    $pr = ['number' => 99, 'html_url' => 'https://github.com/nckrtl/orbit/pull/99', 'title' => 'Nightly snapshot',
        'state' => 'open', 'draft' => false, 'user' => $bot, 'commits' => 1, 'changed_files' => 1, 'additions' => 1, 'deletions' => 1,
        'head' => ['ref' => 'nightly/recipe-'.$date->format('Ymd'), 'sha' => $sha, 'repo' => ['full_name' => 'nckrtl/orbit']],
        'base' => ['ref' => 'main', 'repo' => ['full_name' => 'nckrtl/orbit']]];

    return ['pr' => $pr, 'final' => $pr, 'commits' => [['sha' => $sha, 'author' => $bot, 'committer' => ['login' => 'web-flow', 'type' => 'User'], 'commit' => ['committer' => ['name' => 'GitHub', 'email' => 'noreply@github.com'], 'verification' => ['verified' => true, 'reason' => 'valid']], 'parents' => [['sha' => str_repeat('a', 40)]]]],
        'files' => [['filename' => 'apps/gateway/resources/machine-software/recipe.json', 'status' => 'modified', 'additions' => 1, 'deletions' => 1, 'changes' => 2,
            'patch' => "@@ -2,6 +2,6 @@\n     \"ubuntu\": {\n         \"release\": \"{$release}\",\n-        \"snapshot\": \"{$snapshot}\"\n+        \"snapshot\": \"{$next}\"\n     },"]],
        'before' => $before, 'after' => $after,
        'runs' => [['name' => 'Required checks', 'status' => 'completed', 'conclusion' => 'success', 'head_sha' => $sha, 'app' => ['slug' => 'github-actions']]],
        'merge_status' => 200,
    ];
}

function nightlyMergeHttp(array $evidence): RecordingReleaseAlertNotifier
{
    app()->instance(SnapshotAvailability::class, new FakeUbuntuSnapshot);
    FleetFixtures::inPlace();
    FleetFixtures::adopt(FleetFixtures::Commit);
    GitHubTestSupport::storeApp();
    config(['orbit.cli_releases.repository' => 'https://github.com/nckrtl/orbit', 'orbit.gateway_releases.check' => 'Required checks']);
    $alerts = new RecordingReleaseAlertNotifier;
    app()->instance(ReleaseAlertNotifier::class, $alerts);
    Http::preventStrayRequests();
    $base = 'https://api.github.com/repos/nckrtl/orbit';
    Http::fake([
        $base.'/installation' => Http::response(['id' => 33]),
        'https://api.github.com/app/installations/33/access_tokens' => Http::response(['token' => 'ghs_nightly_merge']),
        $base.'/pulls?*' => Http::response([$evidence['pr']]),
        $base.'/pulls/99' => Http::sequence()->push($evidence['pr'])->push($evidence['final']),
        $base.'/pulls/99/commits?*' => Http::response($evidence['commits']),
        $base.'/pulls/99/files?*' => Http::response($evidence['files']),
        $base.'/commits/'.$evidence['pr']['head']['sha'].'/check-runs?*' => Http::response(['total_count' => count($evidence['runs']), 'check_runs' => $evidence['runs']]),
        $base.'/contents/apps/gateway/resources/machine-software/recipe.json*' => static fn (Request $request) => Http::response([
            'content' => base64_encode(str_contains($request->url(), 'ref='.str_repeat('a', 40)) ? $evidence['before'] : $evidence['after']),
        ]),
        $base.'/pulls/99/merge' => Http::response(['merged' => $evidence['merge_status'] === 200, 'sha' => str_repeat('c', 40), 'message' => 'Merge verdict'], $evidence['merge_status']),
    ]);

    return $alerts;
}

it('verifies a signed snapshot commit independently of the serving tool catalog', function (): void {
    $evidence = nightlyMergeEvidence();
    foreach (['before', 'after'] as $field) {
        $evidence[$field] = preg_replace('/^            "lock_sha256":.*\n/m', '', $evidence[$field]);
    }
    nightlyMergeHttp($evidence);

    app(MergeNightlyRecipePullRequestsAction::class)->execute();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/merge'));
});

it('verifies the live patch and merges with the expected SHA from the per-minute maintenance job', function (): void {
    $alerts = nightlyMergeHttp(nightlyMergeEvidence());
    TaskVmSettingsBuilder::bind(['hosts' => []]);

    app()->call([new MaintainMachineRecipe, 'handle']);

    Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/pulls/99/files?'));
    Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/pulls/99/commits?'));
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/pulls/99/merge')
        && $request->data() === ['sha' => str_repeat('b', 40), 'merge_method' => 'merge']
        && $request->hasHeader('Authorization', 'Bearer ghs_nightly_merge'));
    expect($alerts->alerts)->toBe([]);
});

it('waits for genuine green Required checks on this exact head', function (string $case): void {
    $evidence = nightlyMergeEvidence();
    match ($case) {
        'pending' => $evidence['runs'][0]['status'] = 'in_progress',
        'failed' => $evidence['runs'][0]['conclusion'] = 'failure',
        'wrong head' => $evidence['runs'][0]['head_sha'] = str_repeat('d', 40),
        'wrong check App' => $evidence['runs'][0]['app']['slug'] = 'other-app',
        'missing' => $evidence['runs'] = [],
    };
    $alerts = nightlyMergeHttp($evidence);

    app(MergeNightlyRecipePullRequestsAction::class)->execute();

    Http::assertNotSent(static fn (Request $request): bool => str_ends_with($request->url(), '/merge') || str_contains($request->url(), '/files?'));
    expect($alerts->alerts)->toBe([]);
})->with(['pending', 'failed', 'wrong head', 'wrong check App', 'missing']);

it('blocks and alerts for any author commit file or snapshot evidence outside the narrow nightly change', function (string $case): void {
    $evidence = nightlyMergeEvidence();
    $recipe = new RecipeLoader()->decode($evidence['after']);
    $wrongSnapshot = new DateTimeImmutable($recipe->snapshot)->modify('+1 day')->format('Ymd\THis\Z');
    $curl = $recipe->tools['curl']->version;
    match ($case) {
        'human PR author' => $evidence['pr']['user'] = ['id' => 42, 'login' => 'nick', 'type' => 'User'],
        'other App' => $evidence['pr']['user']['login'] = 'other[bot]',
        'extra commit count' => $evidence['pr']['commits'] = 2,
        'extra commit response' => $evidence['commits'][] = $evidence['commits'][0],
        'human commit author' => $evidence['commits'][0]['author']['login'] = 'nick',
        'human committer' => $evidence['commits'][0]['committer']['login'] = 'nick',
        'unsigned commit' => $evidence['commits'][0]['commit']['verification']['verified'] = false,
        'missing signature' => $evidence['commits'][0]['commit']['verification'] = null,
        'invalid signature' => $evidence['commits'][0]['commit']['verification']['reason'] = 'invalid',
        'wrong author type' => $evidence['commits'][0]['author']['type'] = 'User',
        'wrong committer type' => $evidence['commits'][0]['committer']['type'] = 'Bot',
        'wrong commit head' => $evidence['commits'][0]['sha'] = str_repeat('d', 40),
        'merge commit' => $evidence['commits'][0]['parents'][] = ['sha' => str_repeat('d', 40)],
        'extra file count' => $evidence['pr']['changed_files'] = 2,
        'extra file response' => $evidence['files'][] = $evidence['files'][0],
        'wrong file' => $evidence['files'][0]['filename'] = 'composer.json',
        'renamed file' => $evidence['files'][0]['status'] = 'renamed',
        'missing patch' => $evidence['files'][0]['patch'] = null,
        'extra patch line' => $evidence['files'][0]['patch'] .= "\n+extra",
        'wrong snapshot date' => $evidence['after'] = str_replace($recipe->snapshot, $wrongSnapshot, $evidence['after']),
        'hidden tool edit' => $evidence['after'] = str_replace('"version": "'.$curl.'"', '"version": "'.$curl.'.changed"', $evidence['after']),
        'head changed at merge' => $evidence['final']['head']['sha'] = str_repeat('d', 40),
        'extra commit at merge' => $evidence['final']['commits'] = 2,
        'wrong base' => $evidence['pr']['base']['ref'] = 'other',
        'fork' => $evidence['pr']['head']['repo']['full_name'] = 'other/orbit',
    };
    $alerts = nightlyMergeHttp($evidence);

    app(MergeNightlyRecipePullRequestsAction::class)->execute();

    Http::assertNotSent(static fn (Request $request): bool => str_ends_with($request->url(), '/merge'));
    expect($alerts->alerts)->toHaveCount(1);
    expect($alerts->alerts[0]->summary)->toContain('pull/99', 'blocked:');
    expect($alerts->alerts[0]->incident->resource)->toBe('recipe-pr:99')
        ->and($alerts->alerts[0]->incident->attempt)->toBe(str_repeat('b', 40))
        ->and($alerts->alerts[0]->incident->fault)->toBe('merge_blocked');
})->with(['human PR author', 'other App', 'extra commit count', 'extra commit response', 'human commit author', 'human committer', 'unsigned commit', 'missing signature', 'invalid signature', 'wrong author type', 'wrong committer type', 'wrong commit head', 'merge commit',
    'extra file count', 'extra file response', 'wrong file', 'renamed file', 'missing patch', 'extra patch line', 'wrong snapshot date', 'hidden tool edit',
    'head changed at merge', 'extra commit at merge', 'wrong base', 'fork']);

it('raises the existing alert when GitHub refuses the expected-SHA merge', function (): void {
    $evidence = nightlyMergeEvidence();
    $evidence['merge_status'] = 409;
    $alerts = nightlyMergeHttp($evidence);

    app(MergeNightlyRecipePullRequestsAction::class)->execute();

    Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/merge') && $request['sha'] === str_repeat('b', 40));
    expect($alerts->alerts[0]->summary)->toContain('refused the expected-head merge');
});

it('ignores task-group PRs and does not mint merge requests for them', function (): void {
    $evidence = nightlyMergeEvidence();
    $evidence['pr']['head']['ref'] = 'task-84';
    nightlyMergeHttp($evidence);

    app(MergeNightlyRecipePullRequestsAction::class)->execute();

    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/pulls/99'));
});

it('merges every refreshed Ubuntu pin but refuses even one stale pin in the snapshot bump', function (bool $stale): void {
    $evidence = nightlyMergeEvidence();
    $next = json_decode($evidence['after'], true, flags: JSON_THROW_ON_ERROR)['ubuntu']['snapshot'];
    $contents = new NightlyRecipeContents(new FakeUbuntuSnapshot('+snapshot1'));
    $evidence['after'] = $contents->replace($evidence['before'], new RecipeLoader()->load()->snapshot, $next);
    if ($stale) {
        $curl = new RecipeLoader()->load()->tools['curl']->version;
        $evidence['after'] = str_replace('"version": "'.$curl.'+snapshot1"', '"version": "'.$curl.'"', $evidence['after']);
    }
    $patch = [];
    $afterLines = explode("\n", $evidence['after']);
    foreach (explode("\n", $evidence['before']) as $index => $line) {
        if ($line !== $afterLines[$index]) {
            $patch[] = '-'.$line;
            $patch[] = '+'.$afterLines[$index];
        }
    }
    $evidence['files'][0]['patch'] = implode("\n", $patch);
    $evidence['files'][0]['additions'] = $evidence['files'][0]['deletions'] = intdiv(count($patch), 2);
    $evidence['files'][0]['changes'] = count($patch);
    $evidence['pr']['additions'] = $evidence['pr']['deletions'] = intdiv(count($patch), 2);
    $evidence['final'] = $evidence['pr'];
    $alerts = nightlyMergeHttp($evidence);
    app()->instance(NightlyRecipeContents::class, $contents);

    app(MergeNightlyRecipePullRequestsAction::class)->execute();

    if ($stale) {
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/merge'));
        expect($alerts->alerts)->toHaveCount(1);
    } else {
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/merge'));
        expect($alerts->alerts)->toBe([]);
    }
})->with([false, true]);

it('does not recheck a blocked head even after the alert expires and checks a changed head again', function (): void {
    $evidence = nightlyMergeEvidence();
    $alerts = nightlyMergeHttp($evidence);
    $snapshots = Mockery::mock(SnapshotAvailability::class);
    $snapshots->shouldReceive('latest')->once()->andThrow(new RuntimeException('snapshot download failed'));
    app()->instance(NightlyRecipeContents::class, new NightlyRecipeContents($snapshots));

    app(MergeNightlyRecipePullRequestsAction::class)->execute();
    $this->travel(2)->days();
    app(MergeNightlyRecipePullRequestsAction::class)->execute();

    expect($alerts->alerts)->toHaveCount(1);
    expect($alerts->alerts[0]->summary)->toContain('Ubuntu snapshot pins could not be verified: snapshot download failed');
    expect(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/files?')))->toHaveCount(1);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/merge'));

    $head = str_repeat('d', 40);
    $evidence['pr']['head']['sha'] = $evidence['final']['head']['sha'] = $head;
    $evidence['commits'][0]['sha'] = $evidence['runs'][0]['head_sha'] = $head;
    Http::swap(new Factory);
    $retried = nightlyMergeHttp($evidence);

    app(MergeNightlyRecipePullRequestsAction::class)->execute();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/merge') && $request['sha'] === $head);
    expect($retried->alerts)->toBe([]);
});
