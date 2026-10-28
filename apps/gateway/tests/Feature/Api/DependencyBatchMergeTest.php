<?php

declare(strict_types=1);

use App\Actions\Tasks\CreateTaskGroupAction;
use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubMergeResult;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Infrastructure\GitHub\HttpGitHubApi;
use App\Models\Operation;
use App\Models\TaskCheck;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\Orb220DeploymentApiFixture;
use Tests\Support\ProductionSmokeRoute;

beforeEach(function (): void {
    enable_operation_queue();
    app(TaskExtensionState::class)->enable();
    $this->fixture = Orb220DeploymentApiFixture::create();
    $this->project = $this->fixture->instance->project;
    $this->project->update(['repository_url' => 'https://github.com/acme/widgets.git', 'type' => 'laravel-app']);
    ProductionSmokeRoute::create($this->fixture->instance);
    config(['dependency_detection.services_node_id' => $this->fixture->caller->id,
        'dependency_merge.projects.'.$this->project->id => ['enabled' => true, 'instance_id' => $this->fixture->instance->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip]);
    $this->head = str_repeat('a', 40);
    $this->group = app(CreateTaskGroupAction::class)->execute(new CreateTaskGroupData($this->project->id, 'Dependency batch',
        'Swamp schedule: batch-'.str_repeat('b', 64), TaskGroupStatus::Todo,
        [new TaskInputData('Update dependencies', 'Update the admitted batch', [['id' => 'package', 'type' => 'review', 'description' => 'Record batch evidence.']])], orchestration: TaskOrchestration::External));
    $this->group->update(['pr_url' => 'https://github.com/acme/widgets/pull/7', 'watched_pr_url' => 'https://github.com/acme/widgets/pull/7', 'watched_pr_number' => 7]);
    $this->check = TaskCheck::query()->create(['task_id' => $this->group->tasks->sole()->id, 'kind' => 'handoff', 'status' => 'passed',
        'pid' => 0, 'process_started' => 'fixture', 'head_before' => $this->head, 'tree_before' => $this->head, 'commit_sha' => $this->head, 'started_at' => now()]);
    $this->publish = Operation::query()->create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => $this->group->id,
        'status' => 'succeeded', 'sequence' => 1, 'input' => ['check' => $this->check->id], 'result' => ['branch' => 'task-'.$this->group->id,
            'commit' => $this->head, 'pull_request_number' => 7, 'pull_request_url' => $this->group->pr_url]]);
    $this->url = '/api/v1/task-groups/'.$this->group->id.'/dependency-merge';
    $this->body = ['publish_operation_id' => $this->publish->id, 'head_sha' => $this->head];
    $this->repo = ['default_branch' => 'main', 'archived' => false, 'allow_squash_merge' => true];
    $this->pr = ['number' => 7, 'state' => 'open', 'draft' => false, 'merged' => false, 'mergeable' => true, 'mergeable_state' => 'clean',
        'head' => ['sha' => $this->head, 'ref' => 'task-'.$this->group->id, 'repo' => ['full_name' => 'acme/widgets']],
        'base' => ['ref' => 'main', 'sha' => str_repeat('b', 40), 'repo' => ['full_name' => 'acme/widgets']]];
    $this->workflows = [['id' => 1, 'name' => 'CI', 'path' => '.github/workflows/ci.yml'], ['id' => 2, 'name' => 'Production smoke', 'path' => '.github/workflows/production-smoke.yml']];
    $this->runs = array_map(fn (int $id): array => ['id' => $id, 'workflow_id' => $id, 'check_suite_id' => $id, 'event' => 'pull_request',
        'head_sha' => $this->head, 'head_branch' => 'task-'.$this->group->id, 'status' => 'completed', 'conclusion' => 'success'], [1, 2]);
    $this->checks = [new GitHubCheckRun('Required checks', 'success', null, 1, status: 'completed', headSha: $this->head, appSlug: 'github-actions', checkSuiteId: 1),
        new GitHubCheckRun('smoke', 'success', null, 2, status: 'completed', headSha: $this->head, appSlug: 'github-actions', checkSuiteId: 2)];
    GitHubTestSupport::storeApp();
    $github = Mockery::mock(GitHubApi::class);
    $github->shouldReceive('repositoryInstallation')->andReturn(123);
    $github->shouldReceive('repositoryPullRequestReadToken')->andReturn('fixture-read');
    $github->shouldReceive('repositoryChecksToken')->andReturn('fixture-checks');
    $github->shouldReceive('checkRuns')->andReturnUsing(fn () => $this->checks);
    app()->instance(GitHubApi::class, $github);
    $this->github = $github;
    $this->reads = 0;
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/repos/acme/widgets/pulls/7' && ($this->disableDuringRead ?? false)) {
            config(['dependency_merge.projects' => []]);
        }

        return Http::response(match ($path) {
            '/repos/acme/widgets/installation' => ['id' => 123],
            '/app/installations/123/access_tokens' => ['token' => 'fixture-actions', 'permissions' => ['actions' => 'read']],
            '/repos/acme/widgets' => $this->repo,
            '/repos/acme/widgets/pulls/7/merge' => ['merged' => true, 'sha' => str_repeat('b', 40)],
            '/repos/acme/widgets/pulls/7' => (++$this->reads > 1 && ($this->moveHead ?? false)) ? [...$this->pr, 'head' => [...$this->pr['head'], 'sha' => str_repeat('c', 40)]] : $this->pr,
            '/repos/acme/widgets/actions/workflows' => ['total_count' => count($this->workflows), 'workflows' => $this->workflows],
            '/repos/acme/widgets/actions/runs' => ['total_count' => count($this->runs), 'workflow_runs' => $this->runs],
            default => throw new RuntimeException('Unexpected fixture request'),
        });
    });
});

it('records one keyed merge intent without performing a GitHub write and replays it', function (): void {
    $this->withHeader('Idempotency-Key', 'batch-merge')->postJson($this->url, $this->body)->assertAccepted()->assertJsonPath('data.kind', 'tasks.dependency_merge');
    $id = Operation::query()->where('kind', 'tasks.dependency_merge')->sole()->id;
    $this->postJson($this->url, $this->body)->assertAccepted()->assertJsonPath('data.id', $id);
    expect(Operation::findOrFail($id)->input['token'])->toBe(['$secret' => 'github.dependency_merge', 'id' => $this->project->id]);
    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
});

it('ships all project switches off and disabled admission performs no GitHub reads', function (): void {
    expect(require config_path('dependency_merge.php'))->toBe(['projects' => []]);
    config(['dependency_merge.projects' => []]);
    $this->withHeader('Idempotency-Key', 'off')->postJson($this->url, $this->body)->assertConflict()->assertJsonPath('error.code', 'dependency_merge.disabled');
    Http::assertNothingSent();
});

it('refuses invalid batch lineage without admitting an operation', function (string $change, string $code): void {
    match ($change) {
        'package' => $this->project->update(['type' => 'laravel-package']),
        'node-package' => $this->project->update(['type' => 'node-package']),
        'monorepo' => $this->project->update(['type' => 'monorepo']),
        'host' => $this->fixture->instance->routes()->first()->update(['publication' => 'private']),
        'feature' => $this->group->update(['brief' => 'Feature work']),
        'cancelled' => $this->group->update(['status' => 'cancelled']),
        'unchecked' => $this->check->update(['status' => 'failed']),
        'unpublished' => $this->publish->update(['status' => 'failed']),
        'foreign' => $this->publish->update(['target_id' => $this->group->id + 999]),
        'head' => $this->body['head_sha'] = str_repeat('d', 40),
    };
    $this->withHeader('Idempotency-Key', 'refused')->postJson($this->url, $this->body)->assertConflict()->assertJsonPath('error.code', $code);
    expect(Operation::query()->where('kind', 'tasks.dependency_merge')->count())->toBe(0);
})->with([
    ['package', 'dependency_merge.app_required'], ['node-package', 'dependency_merge.app_required'], ['monorepo', 'dependency_merge.app_required'],
    ['host', 'dependency_merge.host_required'], ['feature', 'dependency_merge.batch_required'], ['cancelled', 'dependency_merge.lineage_invalid'],
    ['unchecked', 'dependency_merge.publication_invalid'], ['unpublished', 'dependency_merge.publication_invalid'], ['foreign', 'dependency_merge.publication_invalid'], ['head', 'dependency_merge.publication_invalid'],
]);

it('requires fresh exact-head CI and the qualified Production smoke job', function (string $change): void {
    match ($change) {
        'missing' => $this->checks = [],
        'wrong-head' => $this->checks[0] = new GitHubCheckRun('Required checks', 'success', null, status: 'completed', headSha: str_repeat('d', 40), appSlug: 'github-actions', checkSuiteId: 1),
        'smoke-failed' => $this->checks[1] = new GitHubCheckRun('smoke', 'failure', null, status: 'completed', headSha: $this->head, appSlug: 'github-actions', checkSuiteId: 2),
        'smoke-skipped' => $this->checks[1] = new GitHubCheckRun('smoke', 'skipped', null, status: 'completed', headSha: $this->head, appSlug: 'github-actions', checkSuiteId: 2),
        'pending' => $this->runs[0]['status'] = 'queued',
        'old-suite' => $this->runs[0]['check_suite_id'] = 999,
        'no-smoke-run' => array_pop($this->runs),
        'default' => $this->repo['default_branch'] = 'trunk',
        'pr-base' => $this->pr['base']['ref'] = 'trunk',
        'draft' => $this->pr['draft'] = true,
        'head-moves' => $this->moveHead = true,
        'foreign-repo' => $this->pr['head']['repo']['full_name'] = 'foreign/widgets',
    };
    $this->withHeader('Idempotency-Key', 'checks')->postJson($this->url, $this->body)->assertConflict();
    expect(Operation::query()->where('kind', 'tasks.dependency_merge')->count())->toBe(0);
})->with(['missing', 'wrong-head', 'smoke-failed', 'smoke-skipped', 'pending', 'old-suite', 'no-smoke-run', 'default', 'pr-base', 'draft', 'head-moves', 'foreign-repo']);

it('does not require a smoke workflow that the repository does not define', function (): void {
    array_pop($this->workflows);
    array_pop($this->runs);
    array_pop($this->checks);
    $this->withHeader('Idempotency-Key', 'no-smoke')->postJson($this->url, $this->body)->assertAccepted();
});

it('refuses another peer and undocumented inputs before admission', function (): void {
    config(['dependency_detection.services_node_id' => $this->fixture->owner->id]);
    $this->withHeader('Idempotency-Key', 'peer')->postJson($this->url, $this->body)->assertForbidden();
    config(['dependency_detection.services_node_id' => $this->fixture->caller->id]);
    $this->postJson($this->url, [...$this->body, 'merge_method' => 'merge'])->assertUnprocessable();
});

it('uses the expected SHA and squash method in the GitHub write', function (): void {
    $repo = GitHubRepository::fromOrigin('https://github.com/acme/widgets');
    expect(app(HttpGitHubApi::class)->squashPullRequest('fixture-write', $repo, 7, $this->head)->merged)->toBeTrue();
    Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['sha'] === $this->head && $request['merge_method'] === 'squash');
});

function dependencyMergeClaim(object $test): array
{
    $id = $test->withHeader('Idempotency-Key', 'executor-merge')->postJson($test->url, $test->body)->assertAccepted()->json('data.id');
    $test->flushHeaders();
    $claim = $test->postJson('/api/v1/operation-executor/claim', ['kind' => 'tasks.dependency_merge', 'worker' => 'proof'])->assertOk();
    expect($claim->json('data.input.token'))->toBe(['$secret' => 'github.dependency_merge', 'id' => $test->project->id]);
    $input = $claim->json('data.input');

    return [$id, ['worker' => 'proof', 'key' => 'squash', 'transport' => 'gateway', 'name' => 'github.dependency-squash', 'input' => $input]];
}

it('hydrates a Project-bound token only in Gateway memory and requires proven completion', function (): void {
    [$id, $request] = dependencyMergeClaim($this);
    $this->github->shouldReceive('repositoryPullRequestToken')->andReturn('fixture-write-secret');
    $this->github->shouldReceive('squashPullRequest')->once()->with('fixture-write-secret', Mockery::any(), 7, $this->head)
        ->andReturn(new GitHubMergeResult(true, str_repeat('b', 40), 200, 'Merged'));
    $path = '/api/v1/operation-executor/'.$id;
    $proof = ['merged' => true, 'head_sha' => $this->head, 'merge_sha' => str_repeat('b', 40)];
    $this->postJson($path.'/complete', ['worker' => 'proof', 'status' => 'succeeded', 'result' => $proof])->assertConflict();
    $this->postJson($path.'/run', $request)->assertOk()->assertJsonPath('data', $proof);
    $this->postJson($path.'/run', $request)->assertOk()->assertJsonPath('data', $proof);
    $this->postJson($path.'/step', ['worker' => 'proof', 'key' => 'done', 'name' => 'squash', 'status' => 'succeeded', 'result' => $proof])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'proof', 'status' => 'succeeded', 'result' => $proof])->assertOk()->assertJsonPath('data.status', 'succeeded');
    expect(json_encode(Operation::findOrFail($id)->toArray()))->not->toContain('fixture-write-secret');
});

it('rechecks enablement and exact-head CI at the effect boundary', function (string $change): void {
    [$id, $request] = dependencyMergeClaim($this);
    $this->github->shouldReceive('repositoryPullRequestToken')->andReturn('fixture-write-secret');
    $this->github->shouldNotReceive('squashPullRequest');
    if ($change === 'disabled') {
        config(['dependency_merge.projects' => []]);
    }
    if ($change === 'during-read') {
        $this->disableDuringRead = true;
    }
    if ($change === 'checks') {
        $this->checks = [];
    }
    if ($change === 'head') {
        $this->pr['head']['sha'] = str_repeat('d', 40);
    }
    $response = $this->postJson('/api/v1/operation-executor/'.$id.'/run', $request)->assertOk();
    expect($response->json('data.error_code'))->toStartWith('dependency_merge.');
})->with(['disabled', 'checks', 'head', 'during-read']);

it('reconciles a lost GitHub merge response without sending another merge', function (): void {
    [$id, $request] = dependencyMergeClaim($this);
    $this->github->shouldReceive('repositoryPullRequestToken')->andReturn('fixture-write-secret');
    $this->github->shouldReceive('squashPullRequest')->once()->andReturnUsing(function () {
        $this->pr = [...$this->pr, 'merged' => true, 'state' => 'closed', 'merge_commit_sha' => str_repeat('b', 40)];
        throw GitHubApiException::unavailable();
    });
    $path = '/api/v1/operation-executor/'.$id.'/run';
    $this->postJson($path, $request)->assertStatus(502);
    $this->postJson($path, $request)->assertOk()->assertJsonPath('data.merged', true)->assertJsonPath('data.merge_sha', str_repeat('b', 40));
});

it('refuses a substituted secret owner, command or target input', function (): void {
    [$id, $request] = dependencyMergeClaim($this);
    $this->github->shouldNotReceive('repositoryPullRequestToken');
    $this->github->shouldNotReceive('squashPullRequest');
    foreach (['owner', 'command', 'head'] as $change) {
        $bad = $request;
        if ($change === 'owner') {
            $bad['input']['token']['id']++;
        }
        if ($change === 'command') {
            $bad['name'] = 'github.arbitrary';
        }
        if ($change === 'head') {
            $bad['input']['head_sha'] = str_repeat('d', 40);
        }
        $this->postJson('/api/v1/operation-executor/'.$id.'/run', $bad)->assertUnprocessable();
    }
});
