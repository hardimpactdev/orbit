<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Infrastructure\GitHub\GitHubActionsJobs;
use App\Infrastructure\GitHub\GitHubActionsReader;
use App\Infrastructure\GitHub\GitHubWorkflowFile;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Project;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
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
 * A fake GitHub: the App's installation and read tokens, the workflows and their files, the run lists and each run's jobs. Lists
 * page by `per_page` and `page`, run lists honour `created` and give at most 1,000 results for one query, as GitHub does. A run
 * without `workflow_id` belongs to workflow 1, and a repository without workflows has workflow 1 with a self-hosted job.
 *
 * @param  array<string, list<array<string, mixed>>>  $runs  repository => its runs, newest first
 * @param  array<int, list<array<string, mixed>>>  $jobs  run id => its jobs
 * @param  list<string>  $missing  repositories the App is not installed on
 * @param  list<string>  $failing  repositories whose run list fails
 * @param  array<string, list<array{id: int, path: string, file: ?string}>>  $workflows  repository => its workflows; a null file is not readable
 * @param  int  $jobSeconds  how long each read of a job list takes
 */
function ciJobsGitHub(array $runs, array $jobs, array $missing = [], array $failing = [], array $workflows = [], int $jobSeconds = 0): void
{
    Http::fake(function (Request $request) use ($runs, $jobs, $missing, $failing, $workflows, $jobSeconds) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $page = static function (array $rows, string $field, ?int $total = null) use ($request): PromiseInterface {
            $size = (int) ($request['per_page'] ?? 30);

            return Http::response(['total_count' => $total ?? count($rows), $field => array_slice($rows, ((int) ($request['page'] ?? 1) - 1) * $size, $size)]);
        };
        $listed = static function (string $repository) use ($runs, $request): array {
            $created = (string) ($request['created'] ?? '');
            $from = str_starts_with($created, '>=') ? substr($created, 2) : explode('..', $created)[0];
            $to = str_contains($created, '..') ? explode('..', $created)[1] : null;

            return array_values(array_filter(array_map(static fn (array $run): array => ['workflow_id' => 1, ...$run], $runs[$repository] ?? []),
                static fn (array $run): bool => ! isset($run['created_at']) || ($from === '' || $run['created_at'] >= $from) && ($to === null || $run['created_at'] <= $to)));
        };
        $workflowsOf = static fn (string $repository): array => $workflows[$repository]
            ?? [['id' => 1, 'path' => '.github/workflows/ci.yml', 'file' => "jobs:\n  fixtures:\n    runs-on: [self-hosted, hardimpact]\n"]];

        if (preg_match('#^/repos/([^/]+/[^/]+)/installation$#', $path, $match) === 1) {
            return in_array($match[1], $missing, true) ? Http::response(['message' => 'Not Found'], 404) : Http::response(['id' => 7]);
        }
        if (str_ends_with($path, '/access_tokens')) {
            $permissions = $request->data()['permissions'] ?? null;
            expect($permissions)->toBeIn([['actions' => 'read'], ['contents' => 'read']]);

            return Http::response(['token' => key($permissions).'-token', 'permissions' => $permissions], 201);
        }
        if (preg_match('#^/repos/([^/]+/[^/]+)/actions/(?:workflows/(\d+)/)?runs$#', $path, $match) === 1) {
            if (in_array($match[1], $failing, true)) {
                return Http::response(['message' => 'Server Error'], 502);
            }
            $all = $listed($match[1]);
            if (isset($match[2])) {
                $all = array_values(array_filter($all, static fn (array $run): bool => $run['workflow_id'] === (int) $match[2]));
            }

            return $page(array_slice($all, 0, 1000), 'workflow_runs', count($all));
        }
        if (preg_match('#^/repos/([^/]+/[^/]+)/actions/workflows$#', $path, $match) === 1) {
            return $page(array_map(static fn (array $workflow): array => ['id' => $workflow['id'], 'name' => 'W'.$workflow['id'],
                'path' => $workflow['path'], 'state' => 'active'], $workflowsOf($match[1])), 'workflows');
        }
        if (preg_match('#^/repos/([^/]+/[^/]+)/contents/(.+)$#', $path, $match) === 1) {
            expect($request->header('Authorization'))->toBe(['Bearer contents-token']);
            foreach ($workflowsOf($match[1]) as $workflow) {
                if ($workflow['path'] === rawurldecode($match[2]) && $workflow['file'] !== null) {
                    return Http::response(['type' => 'file', 'encoding' => 'base64', 'content' => chunk_split(base64_encode($workflow['file']), 60, "\n")]);
                }
            }

            return Http::response(['message' => 'Not Found'], 404);
        }
        if (preg_match('#/actions/runs/(\d+)/jobs$#', $path, $match) === 1) {
            expect($request->header('Authorization'))->toBe(['Bearer actions-token']);
            Carbon::setTestNow(now()->addSeconds($jobSeconds));

            return $page($jobs[(int) $match[1]] ?? [], 'jobs');
        }

        return Http::response(['message' => 'unexpected '.$path], 418);
    });
}

/**
 * Runs of one workflow, `$count` of them, newest first, from run `$first` down, created a minute apart from `$newest` back.
 *
 * @return list<array<string, mixed>>
 */
function ciJobsRuns(int $first, int $count, int $workflow, string $newest): array
{
    return array_map(static fn (int $offset): array => ['id' => $first - $offset, 'name' => 'W'.$workflow, 'workflow_id' => $workflow,
        'status' => 'completed', 'conclusion' => 'success', 'run_attempt' => 1,
        'created_at' => now()->parse($newest)->subMinutes($offset)->utc()->format('Y-m-d\TH:i:s\Z')], range(0, $count - 1));
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

        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/repos/acme/shop/actions/workflows/1/runs?')
            && $request['created'] === '2026-11-14T12:00:00Z..2026-11-16T12:00:00Z' && $request['per_page'] === '100' && $request['page'] === '1');
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

    it('reads a run list to its last page and splits a window that holds more runs than GitHub gives for one query', function (): void {
        $runs = ciJobsRuns(2500, 2500, 2, '2026-11-16T11:59:00Z');
        ciJobsGitHub(['acme/shop' => $runs], []);

        $listed = new GitHubActionsReader(app(GitHubAppStore::class), new CommandDeadline)
            ->workflowRuns(GitHubRepository::fromOrigin('https://github.com/acme/shop') ?? throw new LogicException, 'token', 2, now()->subHours(48), now());

        expect(array_column($listed, 'id'))->toBe(range(2500, 1, -1));
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/actions/workflows/2/runs?') && $request['page'] === '8'
            && $request->hasHeader('Authorization', 'Bearer token'));
        Http::assertNotSent(static fn (Request $request): bool => (int) $request['page'] > 10);
    });

    it('lists every self-hosted job of a repository whose self-hosted runs are older than its newest 500 runs', function (): void {
        ciJobsApp();
        ciJobsRepository('acme/shop');
        $hosted = ciJobsRuns(700, 550, 1, '2026-11-16T11:00:00Z');
        $fixtures = ciJobsRuns(150, 50, 2, '2026-11-15T19:00:00Z');
        ciJobsGitHub(['acme/shop' => [...$hosted, ...$fixtures]],
            array_combine(range(101, 150), array_map(static fn (int $run): array => [ciJobsJob($run * 10)], range(101, 150))),
            workflows: ['acme/shop' => [
                ['id' => 1, 'path' => '.github/workflows/lint.yml', 'file' => "jobs:\n  lint:\n    runs-on: ubuntu-latest\n"],
                ['id' => 2, 'path' => '.github/workflows/fixtures.yml', 'file' => "jobs:\n  fixtures:\n    runs-on: [self-hosted, hardimpact, orbit-shard]\n"],
            ]]);

        $answer = app(GitHubActionsJobs::class)->read()->toArray();

        expect($answer['repositories'])->toBe([['repository' => 'acme/shop', 'error_code' => null, 'truncated' => false]])
            ->and($answer['jobs'])->toHaveCount(50)
            ->and(array_unique(array_column($answer['jobs'], 'run_id')))->toEqualCanonicalizing(range(101, 150));
    });
    it('reads every job of a run with more than one page of jobs', function (): void {
        ciJobsApp();
        ciJobsRepository('acme/shop');
        ciJobsGitHub(['acme/shop' => ciJobsRuns(9, 1, 1, '2026-11-16T10:00:00Z')], [9 => array_map(static fn (int $id): array => ciJobsJob($id), range(1, 301))]);

        $answer = app(GitHubActionsJobs::class)->read()->toArray();

        expect($answer['jobs'])->toHaveCount(301)
            ->and($answer['repositories'])->toBe([['repository' => 'acme/shop', 'error_code' => null, 'truncated' => false]]);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/actions/runs/9/jobs?') && $request['page'] === '4');
    });

    it('asks for no jobs of a workflow whose file runs only on GitHub-hosted runners', function (): void {
        ciJobsApp();
        ciJobsRepository('acme/shop');
        ciJobsGitHub(['acme/shop' => [...ciJobsRuns(30, 20, 1, '2026-11-16T10:00:00Z'), ...ciJobsRuns(5, 1, 2, '2026-11-16T09:00:00Z')]], [5 => [ciJobsJob(51)]],
            workflows: ['acme/shop' => [
                ['id' => 1, 'path' => '.github/workflows/lint.yml', 'file' => "on: push\njobs:\n  lint:\n    runs-on: ubuntu-latest\n  test:\n    runs-on: [macos-15]\n"],
                ['id' => 2, 'path' => '.github/workflows/gone.yml', 'file' => null],
            ]]);
        $jobs = app(GitHubActionsJobs::class);

        expect(array_column($jobs->read()->toArray()['jobs'], 'job_id'))->toBe([51]);
        $this->travel(61)->seconds();
        $jobs->read();

        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/actions/workflows/1/runs')
            || preg_match('#/actions/runs/(1\d|2\d|30)/jobs#', $request->url()) === 1);
        expect(Http::recorded(static fn (Request $request): bool => str_contains($request->url(), '/contents/'))->count())->toBe(2);
    });

    it('stops when the budget is spent, marks the repository truncated and lets the next answer finish it first', function (): void {
        ciJobsApp();
        ciJobsRepository('acme/a');
        ciJobsRepository('acme/b');
        ciJobsGitHub(['acme/a' => ciJobsRuns(10, 1, 1, '2026-11-16T10:00:00Z'), 'acme/b' => ciJobsRuns(23, 3, 1, '2026-11-16T09:00:00Z')],
            [10 => [ciJobsJob(100)], 23 => [ciJobsJob(230)], 22 => [ciJobsJob(220)], 21 => [ciJobsJob(210)]], jobSeconds: 4);
        $jobs = new GitHubActionsJobs(app(GitHubActionsReader::class), app(GitHubAppStore::class), 10.0);

        $first = $jobs->read()->toArray();
        expect($first['repositories'])->toBe([
            ['repository' => 'acme/a', 'error_code' => null, 'truncated' => false],
            ['repository' => 'acme/b', 'error_code' => null, 'truncated' => true],
        ])->and(array_column($first['jobs'], 'job_id'))->toBe([230, 220, 100]);

        $this->travel(61)->seconds();
        $before = Http::recorded()->count();
        $second = $jobs->read()->toArray();
        $lists = Http::recorded()->slice($before)->map(static fn (array $pair): string => $pair[0]->url())
            ->filter(static fn (string $url): bool => str_contains($url, '/runs?'))->values();

        expect($second['repositories'])->toBe([
            ['repository' => 'acme/a', 'error_code' => null, 'truncated' => false],
            ['repository' => 'acme/b', 'error_code' => null, 'truncated' => false],
        ])->and(array_column($second['jobs'], 'job_id'))->toBe([230, 220, 210, 100])
            ->and($lists->first())->toContain('/repos/acme/b/')
            ->and($lists->last())->toContain('/repos/acme/a/');
    });

    it('keeps showing kept jobs when the budget is spent and lets a repository that cannot finish go behind the others', function (): void {
        ciJobsApp();
        ciJobsRepository('acme/a');
        ciJobsRepository('acme/b');
        $running = array_map(static fn (array $run): array => [...$run, 'status' => 'in_progress', 'conclusion' => null], ciJobsRuns(14, 4, 1, '2026-11-16T10:00:00Z'));
        ciJobsGitHub(['acme/a' => $running, 'acme/b' => ciJobsRuns(20, 1, 1, '2026-11-16T09:00:00Z')],
            [14 => [ciJobsJob(140)], 13 => [ciJobsJob(130)], 12 => [ciJobsJob(120)], 11 => [ciJobsJob(110)], 20 => [ciJobsJob(200)]], jobSeconds: 4);
        $jobs = new GitHubActionsJobs(app(GitHubActionsReader::class), app(GitHubAppStore::class), 10.0);
        $answer = function () use ($jobs): array {
            $this->travel(61)->seconds();

            return $jobs->read()->toArray();
        };
        $truncated = static fn (array $read): array => array_column($read['repositories'], 'truncated', 'repository');

        expect($truncated($answer()))->toBe(['acme/a' => true, 'acme/b' => true])
            ->and($truncated($answer()))->toBe(['acme/a' => true, 'acme/b' => true]);

        $third = $answer();
        expect($truncated($third))->toBe(['acme/a' => true, 'acme/b' => false])
            ->and(array_column($third['jobs'], 'job_id'))->toContain(200);

        $fourth = $answer();
        expect($truncated($fourth))->toBe(['acme/a' => true, 'acme/b' => false])
            ->and(array_column($fourth['jobs'], 'job_id'))->toBe([200, 140, 130, 120]);
    });

    it('keeps a workflow that could ask for a self-hosted runner while its runs can be in the window', function (): void {
        ciJobsApp();
        ciJobsRepository('acme/shop');
        $workflow = ['id' => 2, 'path' => '.github/workflows/fixtures.yml', 'file' => "jobs:\n  fixtures:\n    runs-on: [self-hosted]\n"];
        ciJobsGitHub(['acme/shop' => ciJobsRuns(5, 1, 2, '2026-11-16T09:00:00Z')], [5 => [ciJobsJob(51)]], workflows: ['acme/shop' => [$workflow]]);
        app(GitHubActionsJobs::class)->read();

        $this->travel(GitHubActionsJobs::WorkflowSeconds + 1)->seconds();
        ciJobsGitHub(['acme/shop' => ciJobsRuns(5, 1, 2, '2026-11-16T09:00:00Z')], [5 => [ciJobsJob(51)]],
            workflows: ['acme/shop' => [[...$workflow, 'file' => "jobs:\n  fixtures:\n    runs-on: ubuntu-26.04\n"]]]);

        expect(array_column(app(GitHubActionsJobs::class)->read()->toArray()['jobs'], 'job_id'))->toBe([51]);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/contents/'));
    });

    it('treats a workflow file as able to ask for a self-hosted runner unless every job names hosted labels', function (string $file, bool $expected): void {
        expect(GitHubWorkflowFile::canAskForSelfHosted($file))->toBe($expected);
    })->with([
        'hosted labels' => ["jobs:\n  a:\n    runs-on: ubuntu-26.04\n  b:\n    runs-on: [macos-15]\n", false],
        'a self-hosted label' => ["jobs:\n  a:\n    runs-on: ubuntu-26.04\n  b:\n    runs-on: [self-hosted, hardimpact, orbit-shard]\n", true],
        'labels from an expression' => ["jobs:\n  a:\n    runs-on: \${{ fromJSON(vars.ORBIT_PROOF_RUNNER) }}\n", true],
        'labels in a runner group' => ["jobs:\n  a:\n    runs-on:\n      group: ci\n      labels: [self-hosted]\n", true],
        'a called workflow' => ["jobs:\n  a:\n    uses: ./.github/workflows/fixtures.yml\n", true],
        'a file that does not parse' => ["jobs: [\n", true],
    ]);
});
