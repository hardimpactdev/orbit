<?php

declare(strict_types=1);

use App\Actions\Fleet\MergeNightlyRecipePullRequestsAction;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Jobs\MaintainMachineRecipe;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Fleet\RecordingReleaseAlertNotifier;
use Tests\Support\TaskVmSettingsBuilder;

/** @return array<string, mixed> */
function nightlyMergeEvidence(): array
{
    $before = file_get_contents(resource_path('machine-software/recipe.json'));
    $after = str_replace('20261014T000000Z', '20261015T000000Z', $before);
    $bot = ['id' => 4243, 'login' => 'orbit-acme[bot]', 'type' => 'Bot'];
    $sha = str_repeat('b', 40);
    $pr = ['number' => 99, 'html_url' => 'https://github.com/nckrtl/orbit/pull/99', 'title' => 'Nightly snapshot',
        'state' => 'open', 'draft' => false, 'user' => $bot, 'commits' => 1, 'changed_files' => 1, 'additions' => 1, 'deletions' => 1,
        'head' => ['ref' => 'nightly/recipe-20261015', 'sha' => $sha, 'repo' => ['full_name' => 'nckrtl/orbit']],
        'base' => ['ref' => 'main', 'repo' => ['full_name' => 'nckrtl/orbit']]];

    return ['pr' => $pr, 'final' => $pr, 'commits' => [['sha' => $sha, 'author' => $bot, 'committer' => $bot, 'parents' => [['sha' => str_repeat('a', 40)]]]],
        'files' => [['filename' => 'apps/gateway/resources/machine-software/recipe.json', 'status' => 'modified', 'additions' => 1, 'deletions' => 1, 'changes' => 2,
            'patch' => "@@ -2,6 +2,6 @@\n     \"ubuntu\": {\n         \"release\": \"26.04\",\n-        \"snapshot\": \"20261014T000000Z\"\n+        \"snapshot\": \"20261015T000000Z\"\n     },"]],
        'before' => $before, 'after' => $after,
        'runs' => [['name' => 'Required checks', 'status' => 'completed', 'conclusion' => 'success', 'head_sha' => $sha, 'app' => ['slug' => 'github-actions']]],
        'merge_status' => 200,
    ];
}

function nightlyMergeHttp(array $evidence): RecordingReleaseAlertNotifier
{
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
        $base.'/commits/'.str_repeat('b', 40).'/check-runs?*' => Http::response(['total_count' => count($evidence['runs']), 'check_runs' => $evidence['runs']]),
        $base.'/contents/apps/gateway/resources/machine-software/recipe.json*' => static fn (Request $request) => Http::response([
            'content' => base64_encode(str_contains($request->url(), 'ref='.str_repeat('a', 40)) ? $evidence['before'] : $evidence['after']),
        ]),
        $base.'/pulls/99/merge' => Http::response(['merged' => $evidence['merge_status'] === 200, 'sha' => str_repeat('c', 40), 'message' => 'Merge verdict'], $evidence['merge_status']),
    ]);

    return $alerts;
}

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
    match ($case) {
        'human PR author' => $evidence['pr']['user'] = ['id' => 42, 'login' => 'nick', 'type' => 'User'],
        'other App' => $evidence['pr']['user']['login'] = 'other[bot]',
        'extra commit count' => $evidence['pr']['commits'] = 2,
        'extra commit response' => $evidence['commits'][] = $evidence['commits'][0],
        'human commit author' => $evidence['commits'][0]['author']['login'] = 'nick',
        'human committer' => $evidence['commits'][0]['committer']['login'] = 'nick',
        'wrong commit head' => $evidence['commits'][0]['sha'] = str_repeat('d', 40),
        'merge commit' => $evidence['commits'][0]['parents'][] = ['sha' => str_repeat('d', 40)],
        'extra file count' => $evidence['pr']['changed_files'] = 2,
        'extra file response' => $evidence['files'][] = $evidence['files'][0],
        'wrong file' => $evidence['files'][0]['filename'] = 'composer.json',
        'renamed file' => $evidence['files'][0]['status'] = 'renamed',
        'missing patch' => $evidence['files'][0]['patch'] = null,
        'extra patch line' => $evidence['files'][0]['patch'] .= "\n+extra",
        'wrong snapshot date' => $evidence['after'] = str_replace('20261015T000000Z', '20261016T000000Z', $evidence['after']),
        'hidden tool edit' => $evidence['after'] = str_replace('2.3.2-2', '2.3.2-3', $evidence['after']),
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
})->with(['human PR author', 'other App', 'extra commit count', 'extra commit response', 'human commit author', 'human committer', 'wrong commit head', 'merge commit',
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
