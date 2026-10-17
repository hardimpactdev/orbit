<?php

declare(strict_types=1);

use App\Actions\Fleet\OpenNightlyRecipePullRequestAction;
use App\Actions\Fleet\RefreshNightlyRecipePullRequestAction;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Infrastructure\MachineSoftware\PhpBuildBump;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Jobs\MaintainMachineRecipe;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Fleet\RecordingReleaseAlertNotifier;

function nightlyRefreshDate(): DateTimeImmutable
{
    return new DateTimeImmutable(new RecipeLoader()->load()->snapshot)->modify('+1 day')->setTime(1, 0);
}

/** @return array<string, mixed> */
function nightlyRefreshEvidence(): array
{
    $before = file_get_contents(resource_path('machine-software/recipe.json'));
    $snapshot = new RecipeLoader()->load()->snapshot;
    $date = nightlyRefreshDate()->format('Ymd');
    $after = str_replace($snapshot, $date.'T000000Z', $before);
    $bot = ['id' => 123, 'login' => 'orbit-acme[bot]', 'type' => 'Bot'];
    $sha = str_repeat('b', 40);
    $pr = ['number' => 99, 'title' => 'Bump machine recipe snapshot', 'html_url' => 'https://github.com/nckrtl/orbit/pull/99', 'state' => 'open', 'draft' => false,
        'user' => $bot, 'commits' => 1, 'changed_files' => 1, 'additions' => 1, 'deletions' => 1,
        'head' => ['ref' => 'nightly/recipe-'.$date, 'sha' => $sha, 'repo' => ['full_name' => 'nckrtl/orbit']],
        'base' => ['ref' => 'main', 'repo' => ['full_name' => 'nckrtl/orbit']]];

    return ['pr' => $pr, 'final' => $pr, 'before' => $before, 'after' => $after,
        'main' => str_repeat('c', 40), 'main_content' => $before, 'comparison' => 'ahead',
        'commits' => [['sha' => $sha, 'author' => $bot, 'committer' => $bot, 'parents' => [['sha' => str_repeat('a', 40)]]]],
        'files' => [['filename' => OpenNightlyRecipePullRequestAction::Path, 'status' => 'modified', 'additions' => 1, 'deletions' => 1, 'changes' => 2]],
        'runs' => [['name' => 'Required checks', 'status' => 'completed', 'conclusion' => 'failure', 'head_sha' => $sha, 'app' => ['slug' => 'github-actions']]],
        'timeline' => [['event' => 'committed', 'sha' => $sha]],
        'edits' => [], 'more_edits' => false, 'update_errors' => false,
    ];
}

function nightlyRefreshHttp(array $evidence): void
{
    FleetFixtures::inPlace();
    FleetFixtures::adopt(FleetFixtures::Commit);
    GitHubTestSupport::storeApp();
    config(['orbit.cli_releases.repository' => 'https://github.com/nckrtl/orbit', 'orbit.gateway_releases.check' => 'Required checks']);
    Http::preventStrayRequests();
    $base = 'https://api.github.com/repos/nckrtl/orbit';
    Http::fake([
        $base.'/installation' => Http::response(['id' => 33]),
        'https://api.github.com/app/installations/33/access_tokens' => Http::response(['token' => 'ghs_refresh']),
        $base.'/git/ref/heads/main' => Http::response(['object' => ['sha' => $evidence['main']]]),
        $base.'/pulls?*' => static function (Request $request) use ($evidence) {
            if (isset($request['head'])) {
                return Http::response($request['head'] === 'nckrtl:'.$evidence['pr']['head']['ref'] ? [$evidence['pr']] : []);
            }

            return Http::response([$evidence['pr']], $evidence['list_status'] ?? 200);
        },
        $base.'/pulls/99' => Http::sequence()->push($evidence['pr'])->push($evidence['final'])->push(['state' => 'closed']),
        $base.'/git/refs' => Http::response([], 201),
        $base.'/pulls' => Http::response(['number' => 100, 'html_url' => 'https://github.com/nckrtl/orbit/pull/100', 'user' => ['login' => 'orbit-acme[bot]']], 201),
        $base.'/pulls/99/commits?*' => Http::response($evidence['commits']),
        $base.'/pulls/99/files?*' => Http::response($evidence['files']),
        $base.'/issues/99/timeline?*' => Http::response($evidence['timeline']),
        $base.'/commits/'.str_repeat('b', 40).'/check-runs?*' => Http::response(['total_count' => count($evidence['runs']), 'check_runs' => $evidence['runs']]),
        $base.'/compare/*' => Http::response(['status' => $evidence['comparison']]),
        $base.'/contents/'.OpenNightlyRecipePullRequestAction::Path.'*' => static fn (Request $request) => Http::response([
            'sha' => str_repeat('e', 40), 'content' => base64_encode(str_contains($request->url(), 'ref='.str_repeat('b', 40)) ? $evidence['after']
                : (str_contains($request->url(), 'ref='.$evidence['main']) ? $evidence['main_content'] : $evidence['before'])),
        ]),
        $base.'/git/commits/'.$evidence['main'] => Http::response(['tree' => ['sha' => str_repeat('9', 40)]]),
        $base.'/git/trees' => Http::response(['sha' => str_repeat('d', 40)], 201),
        $base.'/git/commits' => Http::response(['sha' => str_repeat('f', 40)], 201),
        'https://api.github.com/graphql' => static fn (Request $request) => Http::response(str_starts_with($request['query'], 'mutation')
            ? ($evidence['update_errors'] ? ['errors' => [['message' => 'Expected head changed']]] : ['data' => ['updateRefs' => ['clientMutationId' => null]]])
            : ['data' => ['repository' => ['id' => 'R_orbit', 'pullRequest' => ['userContentEdits' => ['nodes' => $evidence['edits'], 'pageInfo' => ['hasNextPage' => $evidence['more_edits']]]]]]]),
        $base.'/issues/99/comments' => Http::response([], $evidence['comment_status'] ?? 201),
    ]);
}

it('recreates a red App nightly PR as one commit on current main without closing it', function (): void {
    $evidence = nightlyRefreshEvidence();
    $this->travelTo(nightlyRefreshDate());
    nightlyRefreshHttp($evidence);

    expect(app(OpenNightlyRecipePullRequestAction::class)->execute())->toBe($evidence['pr']['html_url']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/git/trees')
        && $request['base_tree'] === str_repeat('9', 40) && $request['tree'] === [
            ['path' => OpenNightlyRecipePullRequestAction::Path, 'mode' => '100644', 'type' => 'blob', 'content' => $evidence['after']],
        ]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/git/commits')
        && $request['parents'] === [$evidence['main']] && ! isset($request['author']) && ! isset($request['committer']));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/graphql')
        && ($request['variables']['input']['refUpdates'] ?? null) === [
            ['name' => 'refs/heads/'.$evidence['pr']['head']['ref'], 'beforeOid' => str_repeat('b', 40), 'afterOid' => str_repeat('f', 40), 'force' => true],
        ]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/issues/99/comments')
        && str_contains($request['body'], 'Supersedes '.str_repeat('b', 40)));
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT' || str_ends_with($request->url(), '/pulls'));
});

it('refreshes after a maintainer references the failed nightly PR from the merged CI fix', function (): void {
    $evidence = nightlyRefreshEvidence();
    // Relevant fields from #1295's public timeline: #1298 mentioned it without modifying it.
    $evidence['timeline'][] = [
        'actor' => ['login' => 'nckrtl', 'id' => 18613261, 'type' => 'User'],
        'created_at' => '2026-10-16T05:36:00Z',
        'updated_at' => '2026-10-16T05:36:00Z',
        'source' => ['type' => 'issue', 'issue' => [
            'number' => 1298, 'html_url' => 'https://github.com/nckrtl/orbit/pull/1298', 'state' => 'closed',
            'pull_request' => ['merged_at' => '2026-10-16T05:43:18Z'],
        ]],
        'event' => 'cross-referenced',
    ];
    $this->travelTo(nightlyRefreshDate());
    nightlyRefreshHttp($evidence);

    expect(app(OpenNightlyRecipePullRequestAction::class)->execute())->toBe($evidence['pr']['html_url']);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/graphql')
        && ($request['variables']['input']['refUpdates'] ?? null) === [
            ['name' => 'refs/heads/'.$evidence['pr']['head']['ref'], 'beforeOid' => str_repeat('b', 40), 'afterOid' => str_repeat('f', 40), 'force' => true],
        ]);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/issues/99/comments')
        && str_contains($request['body'], 'Supersedes '.str_repeat('b', 40)));
});

it('preserves changes made on main since the failed snapshot commit', function (): void {
    $evidence = nightlyRefreshEvidence();
    $evidence['main_content'] .= "\n";
    $this->travelTo(nightlyRefreshDate());
    nightlyRefreshHttp($evidence);

    app(OpenNightlyRecipePullRequestAction::class)->execute();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/git/trees')
        && $request['tree'][0]['content'] === $evidence['after']."\n");
});

it('leaves closed green pending unchanged-base and person-touched nightly PRs alone', function (string $case): void {
    $evidence = nightlyRefreshEvidence();
    match ($case) {
        'closed' => $evidence['pr']['state'] = 'closed',
        'green' => $evidence['runs'][0]['conclusion'] = 'success',
        'pending' => $evidence['runs'][0]['status'] = 'in_progress',
        'missing' => $evidence['runs'] = [],
        'spoofed check' => $evidence['runs'][0]['app']['slug'] = 'another-app',
        'same main' => $evidence['main'] = str_repeat('a', 40),
        'diverged main' => $evidence['comparison'] = 'diverged',
        'human PR author' => $evidence['pr']['user']['login'] = 'person',
        'extra commit' => $evidence['pr']['commits'] = 2,
        'human author' => $evidence['commits'][0]['author']['login'] = 'person',
        'human committer' => $evidence['commits'][0]['committer']['login'] = 'person',
        'changed file' => $evidence['files'][0]['filename'] = 'README.md',
        'changed contents' => $evidence['after'] .= "\n",
        'body edit' => $evidence['edits'][] = ['editor' => ['login' => 'person']],
        'hidden edit' => $evidence['edits'][] = ['editor' => null],
        'truncated edits' => $evidence['more_edits'] = true,
        'human edit' => $evidence['timeline'][] = ['event' => 'renamed', 'actor' => ['login' => 'person', 'type' => 'User']],
        'unknown human event' => $evidence['timeline'][] = ['event' => 'unknown', 'actor' => ['login' => 'person', 'type' => 'User']],
        'unknown missing actor' => $evidence['timeline'][] = ['event' => 'unknown'],
        'human close reopen' => $evidence['timeline'][] = ['event' => 'reopened', 'actor' => ['login' => 'person', 'type' => 'User']],
        'bot review' => $evidence['timeline'][] = ['event' => 'reviewed', 'user' => ['login' => 'other[bot]', 'type' => 'Bot']],
        'App force push' => $evidence['timeline'][] = ['event' => 'head_ref_force_pushed', 'actor' => ['login' => 'orbit-acme[bot]', 'type' => 'Bot']],
        'App reopen' => $evidence['timeline'][] = ['event' => 'reopened', 'actor' => ['login' => 'orbit-acme[bot]', 'type' => 'Bot']],
        'human force push' => $evidence['timeline'][] = ['event' => 'head_ref_force_pushed', 'actor' => ['login' => 'person', 'type' => 'User']],
        'truncated timeline' => $evidence['timeline'] = array_fill(0, 100, $evidence['timeline'][0]),
        'concurrent push' => $evidence['final']['head']['sha'] = str_repeat('e', 40),
        'concurrent close' => $evidence['final']['state'] = 'closed',
    };
    $this->travelTo(nightlyRefreshDate());
    nightlyRefreshHttp($evidence);

    expect(app(OpenNightlyRecipePullRequestAction::class)->execute())->toBe($evidence['pr']['html_url']);

    Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['PUT', 'PATCH'], true)
        || str_ends_with($request->url(), '/issues/99/comments')
        || (str_ends_with($request->url(), '/graphql') && str_starts_with($request['query'], 'mutation')));
})->with(['closed', 'green', 'pending', 'missing', 'spoofed check', 'same main', 'diverged main', 'human PR author', 'extra commit',
    'human author', 'human committer', 'changed file', 'changed contents', 'human edit', 'human close reopen', 'human force push',
    'truncated timeline', 'unknown human event', 'unknown missing actor', 'body edit', 'hidden edit', 'truncated edits', 'concurrent push', 'concurrent close', 'bot review', 'App force push', 'App reopen']);

it('does not report success or comment when an expected-head branch update loses a race', function (): void {
    $evidence = nightlyRefreshEvidence();
    $evidence['update_errors'] = true;
    $this->travelTo(nightlyRefreshDate());
    nightlyRefreshHttp($evidence);

    $alerts = new RecordingReleaseAlertNotifier;
    app()->instance(ReleaseAlertNotifier::class, $alerts);
    expect(app(OpenNightlyRecipePullRequestAction::class)->execute())->toBe($evidence['pr']['html_url']);
    expect($alerts->alerts)->toHaveCount(1);

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/issues/99/comments'));
});

it('leaves older-date green closed and reviewed PRs unchanged in the recovery pass', function (string $case): void {
    $evidence = nightlyRefreshEvidence();
    match ($case) {
        'green' => $evidence['runs'][0]['conclusion'] = 'success',
        'closed' => $evidence['pr']['state'] = 'closed',
        'person edit' => $evidence['edits'][] = ['editor' => ['login' => 'person']],
        'bot review' => $evidence['timeline'][] = ['event' => 'reviewed', 'user' => ['login' => 'other[bot]', 'type' => 'Bot']],
    };
    $this->travelTo(nightlyRefreshDate()->modify('+1 day'));
    nightlyRefreshHttp($evidence);

    app(RefreshNightlyRecipePullRequestAction::class)->execute();

    Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['PUT', 'PATCH'], true)
        || str_ends_with($request->url(), '/issues/99/comments')
        || (str_ends_with($request->url(), '/graphql') && str_starts_with($request['query'], 'mutation')));
})->with(['green', 'closed', 'person edit', 'bot review']);

// Exercise the same snapshot job the 03:00 Amsterdam schedule dispatches.
it('closes a red older-date PR and opens only tonight’s replacement on the real nightly job', function (): void {
    $evidence = nightlyRefreshEvidence();
    $this->travelTo(nightlyRefreshDate()->modify('+1 day')->setTimezone(new DateTimeZone('Europe/Amsterdam'))->setTime(3, 0));
    nightlyRefreshHttp($evidence);
    app()->instance(PhpBuildBump::class, new class extends PhpBuildBump
    {
        public bool $called = false;

        public function __construct() {}

        public function bump(string $recipe): string
        {
            $this->called = true;

            return $recipe;
        }
    });

    app()->call([new MaintainMachineRecipe(snapshot: true), 'handle']);

    $branch = 'nightly/recipe-'.now('Europe/Amsterdam')->format('Ymd');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && str_ends_with($request->url(), '/pulls/99') && $request['state'] === 'closed');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/issues/99/comments') && str_contains($request['body'], 'Superseded'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls') && $request['head'] === $branch);
    expect(collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/pulls')))->toHaveCount(1);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/graphql') && str_starts_with($request['query'], 'mutation'));
    expect(app(PhpBuildBump::class)->called)->toBeTrue();
});

it('keeps the PHP build pass running after recovery errors or failed comments', function (string $case): void {
    $evidence = nightlyRefreshEvidence();
    if ($case === 'list') {
        $evidence['list_status'] = 500;
    } elseif ($case === 'update') {
        $evidence['update_errors'] = true;
    } else {
        $evidence['comment_status'] = 403;
    }
    $this->travelTo(nightlyRefreshDate());
    nightlyRefreshHttp($evidence);
    $alerts = new RecordingReleaseAlertNotifier;
    app()->instance(ReleaseAlertNotifier::class, $alerts);
    app()->instance(PhpBuildBump::class, new class extends PhpBuildBump
    {
        public bool $called = false;

        public function __construct() {}

        public function bump(string $recipe): string
        {
            $this->called = true;

            return $recipe;
        }
    });

    app()->call([new MaintainMachineRecipe(snapshot: true), 'handle']);

    expect(app(PhpBuildBump::class)->called)->toBeTrue();
    expect($alerts->alerts)->toHaveCount($case === 'comment' ? 0 : 1);
})->with(['list', 'update', 'comment']);
