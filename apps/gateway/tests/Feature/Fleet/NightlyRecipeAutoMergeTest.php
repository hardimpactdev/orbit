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

function nightlyMergeHttp(array $evidence, array $overrides = []): RecordingReleaseAlertNotifier
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
    Http::fake(array_replace([
        $base.'/installation' => Http::response(['id' => 33]),
        'https://api.github.com/app/installations/33/access_tokens' => Http::response(['token' => 'ghs_nightly_merge']),
        $base.'/pulls?*' => Http::response([$evidence['pr']]),
        $base.'/pulls/99' => Http::sequence()->push($evidence['pr'])->push($evidence['final'])->whenEmpty(Http::response($evidence['pr'])),
        $base.'/pulls/99/commits?*' => Http::response($evidence['commits']),
        $base.'/pulls/99/files?*' => Http::response($evidence['files']),
        $base.'/commits/'.$evidence['pr']['head']['sha'].'/check-runs?*' => Http::response(['total_count' => count($evidence['runs']), 'check_runs' => $evidence['runs']]),
        $base.'/contents/apps/gateway/resources/machine-software/recipe.json*' => static fn (Request $request) => Http::response([
            'content' => base64_encode(str_contains($request->url(), 'ref='.str_repeat('a', 40)) ? $evidence['before'] : $evidence['after']),
        ]),
        $base.'/pulls/99/merge' => Http::response(['merged' => $evidence['merge_status'] === 200, 'sha' => str_repeat('c', 40), 'message' => 'Merge verdict'], $evidence['merge_status']),
    ], $overrides));

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
    expect($alerts->alerts[0]->summary)->toContain('retrying in five minutes');
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
    $valid = $evidence['after'];
    $evidence['after'] .= ' ';
    $alerts = nightlyMergeHttp($evidence);

    app(MergeNightlyRecipePullRequestsAction::class)->execute();
    $this->travel(2)->days();
    app(MergeNightlyRecipePullRequestsAction::class)->execute();

    expect($alerts->alerts)->toHaveCount(1);
    expect($alerts->alerts[0]->summary)->toContain('Recipe contents differ from the authenticated snapshot');
    expect(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/files?')))->toHaveCount(1);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/merge'));

    $evidence['after'] = $valid;
    $head = str_repeat('d', 40);
    $evidence['pr']['head']['sha'] = $evidence['final']['head']['sha'] = $head;
    $evidence['commits'][0]['sha'] = $evidence['runs'][0]['head_sha'] = $head;
    Http::swap(new Factory);
    $retried = nightlyMergeHttp($evidence);
    app()->instance(NightlyRecipeContents::class, new NightlyRecipeContents(new FakeUbuntuSnapshot));

    app(MergeNightlyRecipePullRequestsAction::class)->execute();

    expect($retried->alerts)->toBe([]);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/merge') && $request['sha'] === $head);
});

it('retries temporary failures on the same green head after backoff', function (string $failure): void {
    $this->freezeTime();
    $evidence = nightlyMergeEvidence();
    $base = 'https://api.github.com/repos/nckrtl/orbit';
    $overrides = match ($failure) {
        'PR list' => [$base.'/pulls?*' => Http::sequence()->pushStatus(503)->push([$evidence['pr']])],
        'PR read' => [$base.'/pulls/99' => Http::sequence()->pushStatus(503)->push($evidence['pr'])->push($evidence['final'])],
        'check runs' => [$base.'/commits/'.$evidence['pr']['head']['sha'].'/check-runs?*' => Http::sequence()->pushStatus(503)->push(['total_count' => 1, 'check_runs' => $evidence['runs']])],
        'merge' => [$base.'/pulls/99/merge' => Http::sequence()->pushStatus(503)->push(['merged' => true, 'sha' => str_repeat('c', 40), 'message' => 'Merged'])],
        'timeout' => [$base.'/pulls/99' => Http::sequence()->pushResponse(Http::failedConnection())->push($evidence['pr'])->push($evidence['final'])],
        default => [],
    };
    $alerts = nightlyMergeHttp($evidence, $overrides);
    if ($failure === 'snapshot download') {
        $snapshots = Mockery::mock(SnapshotAvailability::class);
        $snapshots->shouldReceive('latest')->once()->andThrow(new RuntimeException('snapshot download failed'));
        $snapshots->shouldReceive('latest')->once()->andReturnUsing(new FakeUbuntuSnapshot()->latest(...));
        app()->instance(NightlyRecipeContents::class, new NightlyRecipeContents($snapshots));
    }

    app(MergeNightlyRecipePullRequestsAction::class)->execute();
    $requests = count(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/pulls/99') || str_contains($request->url(), '/check-runs?')));
    $this->travel(4)->minutes();
    app(MergeNightlyRecipePullRequestsAction::class)->execute();

    expect(count(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/pulls/99') || str_contains($request->url(), '/check-runs?'))))->toBe($requests);
    expect($alerts->alerts)->toHaveCount(1);
    expect($alerts->alerts[0]->summary)->toContain('retrying in five minutes');
    if ($failure === 'snapshot download') {
        expect($alerts->alerts[0]->summary)->toContain('snapshot download failed');
    }

    $this->travel(2)->minutes();
    app(MergeNightlyRecipePullRequestsAction::class)->execute();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/merge') && $request['sha'] === $evidence['pr']['head']['sha']);
    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/merge') && $request->method() === 'PUT')->last()[1]->json('merged'))->toBeTrue();
})->with(['PR list', 'PR read', 'check runs', 'merge', 'timeout', 'snapshot download']);

it('does not record rejected evidence before Required checks pass', function (): void {
    $evidence = nightlyMergeEvidence();
    $evidence['pr']['changed_files'] = 2;
    $evidence['runs'][0]['status'] = 'in_progress';
    nightlyMergeHttp($evidence);

    app(MergeNightlyRecipePullRequestsAction::class)->execute();

    $evidence['pr']['changed_files'] = 1;
    $evidence['runs'][0]['status'] = 'completed';
    Http::swap(new Factory);
    nightlyMergeHttp($evidence);
    app(MergeNightlyRecipePullRequestsAction::class)->execute();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/merge'));
});

it('closes a blocked App PR with its reason only after a newer App date PR opens', function (string $case): void {
    $evidence = nightlyMergeEvidence();
    $evidence['after'] .= ' ';
    nightlyMergeHttp($evidence);
    app(MergeNightlyRecipePullRequestsAction::class)->execute();
    $replacement = $evidence['pr'];
    $replacement['number'] = 100;
    $replacement['html_url'] = 'https://github.com/nckrtl/orbit/pull/100';
    $date = new DateTimeImmutable(substr($evidence['pr']['head']['ref'], -8))->modify('+1 day');
    $replacement['head']['ref'] = 'nightly/recipe-'.$date->format('Ymd');
    $replacement['head']['sha'] = str_repeat('d', 40);
    $later = $replacement;
    $later['number'] = 101;
    $later['html_url'] = 'https://github.com/nckrtl/orbit/pull/101';
    $later['head']['ref'] = 'nightly/recipe-'.$date->modify('+1 day')->format('Ymd');
    $later['head']['sha'] = str_repeat('e', 40);
    $current = $evidence['pr'];
    match ($case) {
        'human replacement' => $replacement['user']['login'] = 'nick',
        'fork replacement', 'invalid candidate before App replacement' => $replacement['head']['repo']['full_name'] = 'other/orbit',
        'extra commits replacement' => $replacement['commits'] = 2,
        'changed old head' => $current['head']['sha'] = str_repeat('e', 40),
        'changed old author' => $current['user'] = ['id' => 42, 'login' => 'nick', 'type' => 'User'],
        default => null,
    };
    Http::swap(new Factory);
    $base = 'https://api.github.com/repos/nckrtl/orbit';
    nightlyMergeHttp($evidence, [
        $base.'/pulls?*' => Http::response(match ($case) {
            'no replacement' => [$evidence['pr']],
            'invalid candidate before App replacement' => [$evidence['pr'], $replacement, $later],
            default => [$evidence['pr'], $replacement],
        }),
        $base.'/pulls/99' => Http::response($current),
        $base.'/pulls/100' => Http::response($replacement),
        $base.'/pulls/101' => Http::response($later),
        $base.'/commits/'.str_repeat('e', 40).'/check-runs?*' => Http::response(['total_count' => 0, 'check_runs' => []]),
        $base.'/commits/'.str_repeat('d', 40).'/check-runs?*' => Http::response(['total_count' => 0, 'check_runs' => []]),
        $base.'/issues/99/comments' => Http::response([], $case === 'comment failure' ? 503 : 201),
    ]);

    app(MergeNightlyRecipePullRequestsAction::class)->execute();

    if (in_array($case, ['valid', 'extra commits replacement', 'invalid candidate before App replacement'], true)) {
        $replacementUrl = $case === 'invalid candidate before App replacement' ? 'pull/101' : 'pull/100';
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/issues/99/comments')
            && str_contains($request['body'], $replacementUrl) && str_contains($request['body'], 'Recipe contents differ'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && str_ends_with($request->url(), '/pulls/99') && $request['state'] === 'closed');
    } else {
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
    }
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/files?'));
})->with(['valid', 'no replacement', 'human replacement', 'fork replacement', 'invalid candidate before App replacement', 'extra commits replacement', 'changed old head', 'changed old author', 'comment failure']);

it('finishes blocked PR retirement before merging a green replacement after a temporary cleanup failure', function (string $failure): void {
    $this->freezeTime();
    $evidence = nightlyMergeEvidence();
    $evidence['after'] .= ' ';
    nightlyMergeHttp($evidence);
    app(MergeNightlyRecipePullRequestsAction::class)->execute();
    $replacement = nightlyMergeEvidence();
    $snapshot = new DateTimeImmutable(substr($evidence['pr']['head']['ref'], -8))->modify('+1 day');
    $replacement['after'] = str_replace(substr($evidence['pr']['head']['ref'], -8).'T000000Z', $snapshot->format('Ymd\THis\Z'), $replacement['after']);
    $replacement['files'][0]['patch'] = str_replace(substr($evidence['pr']['head']['ref'], -8).'T000000Z', $snapshot->format('Ymd\THis\Z'), $replacement['files'][0]['patch']);
    $replacement['pr']['number'] = 100;
    $replacement['pr']['html_url'] = 'https://github.com/nckrtl/orbit/pull/100';
    $replacement['pr']['head']['ref'] = 'nightly/recipe-'.$snapshot->format('Ymd');
    $replacement['pr']['head']['sha'] = str_repeat('d', 40);
    $replacement['commits'][0]['sha'] = $replacement['runs'][0]['head_sha'] = str_repeat('d', 40);
    Http::swap(new Factory);
    $base = 'https://api.github.com/repos/nckrtl/orbit';
    $closed = false;
    $fail = true;
    nightlyMergeHttp($evidence, [
        // Newest first proves cleanup does not depend on GitHub's list ordering.
        $base.'/pulls?*' => function () use ($evidence, $replacement, &$closed) {
            return Http::response($closed ? [$replacement['pr']] : [$replacement['pr'], $evidence['pr']]);
        },
        $base.'/pulls/100' => Http::response($replacement['pr']),
        $base.'/pulls/100/commits?*' => Http::response($replacement['commits']),
        $base.'/pulls/100/files?*' => Http::response($replacement['files']),
        $base.'/commits/'.str_repeat('d', 40).'/check-runs?*' => Http::response(['total_count' => 1, 'check_runs' => $replacement['runs']]),
        $base.'/contents/apps/gateway/resources/machine-software/recipe.json*' => fn (Request $request) => Http::response(['content' => base64_encode(str_contains($request->url(), 'ref='.str_repeat('a', 40)) ? $replacement['before'] : $replacement['after'])]),
        $base.'/issues/99/comments' => function () use ($failure, &$fail) {
            if ($failure === 'comment' && $fail) {
                $fail = false;

                return Http::response([], 503);
            }

            return Http::response([], 201);
        },
        $base.'/pulls/99' => function (Request $request) use ($evidence, $failure, &$fail, &$closed) {
            if ($request->method() === 'PATCH') {
                if ($failure === 'close' && $fail) {
                    $fail = false;

                    return Http::response([], 503);
                }
                $closed = true;
            }

            return Http::response($evidence['pr']);
        },
        $base.'/pulls/100/merge' => function () use (&$closed) {
            expect($closed)->toBeTrue();

            return Http::response(['merged' => true, 'sha' => str_repeat('c', 40), 'message' => 'Merged']);
        },
    ]);

    app(MergeNightlyRecipePullRequestsAction::class)->execute();
    $this->travel(4)->minutes();
    app(MergeNightlyRecipePullRequestsAction::class)->execute();

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/merge'));
    expect($closed)->toBeFalse();

    $this->travel(2)->minutes();
    app(MergeNightlyRecipePullRequestsAction::class)->execute();

    expect($closed)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/pulls/100/merge') && $request['sha'] === str_repeat('d', 40));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/issues/99/comments') && str_contains($request['body'], 'Recipe contents differ') && str_contains($request['body'], 'pull/100'));
})->with(['comment', 'close']);
