<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Infrastructure\GitHub\GitHubActionsJobs;
use App\Infrastructure\GitHub\GitHubActionsReader;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

function ciJobsRepository(string $slug, string $access = 'github_app'): void
{
    Project::query()->create(['slug' => str_replace('/', '-', $slug), 'name' => $slug, 'repository_url' => 'https://github.com/'.$slug.'.git',
        'default_branch' => 'main', 'root' => '.', 'source_access' => $access]);
}

function ciJobsApp(): void
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'orbit', 'orbit', 'acme', 'organization', 'https://github.com/apps/orbit', $pem));
}

/** @param array<string, mixed> $overrides */
function ciJobsJob(int $id, array $overrides = []): array
{
    return ['id' => $id, 'run_id' => 0, 'name' => 'job-'.$id, 'labels' => ['self-hosted', 'hardimpact'], 'runner_name' => 'ci-1',
        'status' => 'completed', 'conclusion' => 'success', 'created_at' => '2026-11-15T10:00:00Z', 'started_at' => '2026-11-15T10:00:30Z',
        'completed_at' => '2026-11-15T10:05:30Z', 'html_url' => 'https://github.com/acme/shop/actions/runs/1/job/'.$id, 'steps' => [], ...$overrides];
}

/**
 * A fake GitHub: the App's installation and Actions read token, the run list and each run's jobs.
 *
 * @param  array<string, list<array<string, mixed>>>  $runs  repository => its runs
 * @param  array<int, list<array<string, mixed>>>  $jobs  run id => its jobs
 * @param  list<string>  $missing  repositories the App is not installed on
 * @param  list<string>  $failing  repositories whose run list fails
 */
function ciJobsGitHub(array $runs, array $jobs, array $missing = [], array $failing = []): void
{
    Http::fake(function (Request $request) use ($runs, $jobs, $missing, $failing) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        if (preg_match('#^/repos/([^/]+/[^/]+)/installation$#', $path, $match) === 1) {
            return in_array($match[1], $missing, true) ? Http::response(['message' => 'Not Found'], 404) : Http::response(['id' => 7]);
        }
        if (str_ends_with($path, '/access_tokens')) {
            expect($request->data())->toMatchArray(['permissions' => ['actions' => 'read']]);

            return Http::response(['token' => 'actions-token', 'permissions' => ['actions' => 'read']], 201);
        }
        if (preg_match('#^/repos/([^/]+/[^/]+)/actions/runs$#', $path, $match) === 1) {
            if (in_array($match[1], $failing, true)) {
                return Http::response(['message' => 'Server Error'], 502);
            }
            $all = $runs[$match[1]] ?? [];

            return Http::response(['total_count' => count($all), 'workflow_runs' => $all]);
        }
        if (preg_match('#/actions/runs/(\d+)/jobs$#', $path, $match) === 1) {
            $list = $jobs[(int) $match[1]] ?? [];

            return Http::response(['total_count' => count($list), 'jobs' => $list]);
        }

        return Http::response(['message' => 'unexpected '.$path], 418);
    });
}

describe('the CI runner view job read', function (): void {
    beforeEach(function (): void {
        $this->travelTo(now()->parse('2026-11-16T12:00:00Z'));
    });

    it('lists the self-hosted jobs of the window with their runner, queue wait and run time, newest first', function (): void {
        ciJobsApp();
        ciJobsRepository('acme/shop');
        ciJobsRepository('acme/private', 'gh_cli');
        ciJobsGitHub(['acme/shop' => [
            ['id' => 11, 'name' => 'CI', 'status' => 'in_progress', 'conclusion' => null, 'run_attempt' => 2],
            ['id' => 10, 'name' => 'CI', 'status' => 'completed', 'conclusion' => 'skipped', 'run_attempt' => 1],
            ['id' => 9, 'name' => 'Deploy', 'status' => 'completed', 'conclusion' => 'failure', 'run_attempt' => 1],
        ]], [
            11 => [
                ciJobsJob(111, ['status' => 'in_progress', 'conclusion' => null, 'completed_at' => null, 'created_at' => '2026-11-16T11:00:00Z',
                    'started_at' => '2026-11-16T11:02:00Z', 'runner_name' => 'ci-2']),
                ciJobsJob(112, ['status' => 'queued', 'conclusion' => null, 'runner_name' => '', 'created_at' => '2026-11-16T11:30:00Z',
                    'started_at' => '2026-11-16T11:30:00Z', 'completed_at' => null]),
                ciJobsJob(113, ['labels' => ['ubuntu-latest']]),
            ],
            9 => [ciJobsJob(91, ['conclusion' => 'failure'])],
        ]);

        $answer = app(GitHubActionsJobs::class)->read()->toArray();

        expect($answer['github_error_code'])->toBeNull()
            ->and($answer['repositories'])->toBe([['repository' => 'acme/shop', 'error_code' => null, 'truncated' => false]])
            ->and(array_column($answer['jobs'], 'job_id'))->toBe([112, 111, 91])
            ->and($answer['jobs'][0])->toMatchArray(['status' => 'queued', 'runner_name' => null, 'started_at' => null, 'completed_at' => null,
                'queue_wait_seconds' => null, 'run_seconds' => null, 'workflow' => 'CI', 'run_id' => 11])
            ->and($answer['jobs'][1])->toMatchArray(['runner_name' => 'ci-2', 'started_at' => '2026-11-16T11:02:00Z', 'completed_at' => null,
                'queue_wait_seconds' => 120, 'run_seconds' => null])
            ->and($answer['jobs'][2])->toBe(['job_id' => 91, 'run_id' => 9, 'repository' => 'acme/shop', 'workflow' => 'Deploy', 'job' => 'job-91',
                'runner_name' => 'ci-1', 'status' => 'completed', 'conclusion' => 'failure', 'created_at' => '2026-11-15T10:00:00Z',
                'started_at' => '2026-11-15T10:00:30Z', 'completed_at' => '2026-11-15T10:05:30Z', 'queue_wait_seconds' => 30, 'run_seconds' => 300,
                'url' => 'https://github.com/acme/shop/actions/runs/1/job/91']);

        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/repos/acme/shop/actions/runs?')
            && $request['created'] === '>=2026-11-14T12:00:00Z' && $request['per_page'] === '100' && $request['page'] === '1');
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/actions/runs/11/jobs?') && $request['filter'] === 'latest');
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/actions/runs/10/jobs')
            || str_contains($request->url(), 'acme/private') || str_contains($request->url(), '/runners'));
    });

    it('serves one answer for a minute and then reads again only the runs that have not completed', function (): void {
        ciJobsApp();
        ciJobsRepository('acme/shop');
        ciJobsGitHub(['acme/shop' => [
            ['id' => 11, 'name' => 'CI', 'status' => 'in_progress', 'run_attempt' => 1],
            ['id' => 9, 'name' => 'CI', 'status' => 'completed', 'conclusion' => 'success', 'run_attempt' => 1],
        ]], [11 => [ciJobsJob(111, ['status' => 'in_progress', 'conclusion' => null])], 9 => [ciJobsJob(91)]]);
        $jobs = app(GitHubActionsJobs::class);
        $reads = static fn (int $run): int => Http::recorded(static fn (Request $request): bool => str_contains($request->url(), '/actions/runs/'.$run.'/jobs'))->count();

        $first = $jobs->read()->toArray();
        $this->travel(59)->seconds();
        expect($jobs->read()->toArray())->toBe($first)
            ->and($reads(11))->toBe(1)->and($reads(9))->toBe(1);

        $this->travel(2)->seconds();
        expect(array_column($jobs->read()->toArray()['jobs'], 'job_id'))->toBe([111, 91])
            ->and($reads(11))->toBe(2)
            ->and($reads(9))->toBe(1);
    });

    it('reports a missing App without asking GitHub', function (): void {
        Http::fake();
        ciJobsRepository('acme/shop');

        expect(app(GitHubActionsJobs::class)->read()->toArray())->toBe(['github_error_code' => 'github.app_missing', 'repositories' => [], 'jobs' => []]);
        Http::assertNothingSent();
    });

    it('leaves out a repository without the App and marks one that GitHub refused', function (): void {
        ciJobsApp();
        ciJobsRepository('acme/shop');
        ciJobsRepository('acme/other');
        ciJobsRepository('acme/down');
        ciJobsGitHub(['acme/shop' => [['id' => 9, 'name' => 'CI', 'status' => 'completed', 'conclusion' => 'success']]], [9 => [ciJobsJob(91)]],
            missing: ['acme/other'], failing: ['acme/down']);

        $answer = app(GitHubActionsJobs::class)->read()->toArray();

        expect($answer['repositories'])->toBe([
            ['repository' => 'acme/down', 'error_code' => 'github.unavailable', 'truncated' => false],
            ['repository' => 'acme/shop', 'error_code' => null, 'truncated' => false],
        ])->and(array_column($answer['jobs'], 'job_id'))->toBe([91]);
    });

    it('marks a repository truncated when its jobs do not fit the time budget', function (): void {
        ciJobsApp();
        ciJobsRepository('acme/shop');
        ciJobsGitHub(['acme/shop' => [['id' => 9, 'name' => 'CI', 'status' => 'completed', 'conclusion' => 'success']]], [9 => [ciJobsJob(91)]]);

        $answer = new GitHubActionsJobs(app(GitHubActionsReader::class), app(GitHubAppStore::class), 0.0)->read()->toArray();

        expect($answer['repositories'])->toBe([['repository' => 'acme/shop', 'error_code' => null, 'truncated' => true]])
            ->and($answer['jobs'])->toBe([]);
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/jobs'));
    });

    it('reads at most the given pages of runs and says when the window holds more', function (): void {
        $runs = array_map(static fn (int $id): array => ['id' => $id, 'name' => 'CI', 'status' => 'completed'], range(1, 100));
        Http::fake(['api.github.com/repos/acme/shop/actions/runs*' => static fn () => Http::response(['total_count' => 250, 'workflow_runs' => $runs])]);

        $listed = new GitHubActionsReader(app(GitHubAppStore::class), new CommandDeadline)
            ->workflowRuns(GitHubRepository::fromOrigin('https://github.com/acme/shop') ?? throw new LogicException, 'token', now()->subHours(48), 2);

        expect($listed['runs'])->toHaveCount(200)->and($listed['truncated'])->toBeTrue();
        Http::assertSentCount(2);
        Http::assertSent(static fn (Request $request): bool => $request['page'] === '2' && $request->hasHeader('Authorization', 'Bearer token'));
    });
});
