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
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\DependencyBatchMergeCommand;
use App\Domain\Tasks\DependencyMergeSecret;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Infrastructure\GitHub\HttpGitHubApi;
use App\Models\Operation;
use App\Models\Task;
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
        'dependency_merge.projects.'.$this->project->id => ['enabled' => true, 'instance_id' => $this->fixture->instance->id, 'smoke_workflow' => '.github/workflows/production-smoke.yml']]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip]);
    $this->head = str_repeat('a', 40);
    $this->group = app(CreateTaskGroupAction::class)->execute(new CreateTaskGroupData($this->project->id, 'Dependency batch',
        'Swamp schedule: batch-'.str_repeat('b', 64), TaskGroupStatus::Todo,
        [new TaskInputData('Update dependencies', 'Update the admitted batch', [['id' => 'package', 'type' => 'review', 'description' => 'Record batch evidence.']])], orchestration: TaskOrchestration::External), $this->fixture->caller);
    $this->group->update(['pr_url' => 'https://github.com/acme/widgets/pull/7', 'watched_pr_url' => 'https://github.com/acme/widgets/pull/7', 'watched_pr_number' => 7]);
    $this->check = TaskCheck::query()->create(['task_id' => $this->group->tasks->sole()->id, 'kind' => 'handoff', 'status' => 'passed',
        'pid' => 0, 'process_started' => 'fixture', 'head_before' => $this->head, 'tree_before' => $this->head, 'commit_sha' => $this->head, 'started_at' => now()]);
    $this->publish = Operation::query()->create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => $this->group->id,
        'status' => 'succeeded', 'sequence' => 1, 'input' => ['check' => $this->check->id], 'result' => ['branch' => 'task-'.$this->group->id,
            'commit' => $this->head, 'pull_request_number' => 7, 'pull_request_url' => $this->group->pr_url]]);
    $this->url = '/api/v1/task-groups/'.$this->group->id.'/dependency-merge';
    $this->body = ['publish_operation_id' => $this->publish->id, 'head_sha' => $this->head];
    $this->repo = ['default_branch' => 'main', 'archived' => false, 'allow_squash_merge' => true];
    $this->files = [['filename' => 'composer.lock', 'status' => 'modified']];
    $this->pr = ['changed_files' => 1, 'number' => 7, 'state' => 'open', 'draft' => false, 'merged' => false, 'mergeable' => true, 'mergeable_state' => 'clean',
        'head' => ['sha' => $this->head, 'ref' => 'task-'.$this->group->id, 'repo' => ['full_name' => 'acme/widgets']],
        'base' => ['ref' => 'main', 'sha' => str_repeat('b', 40), 'repo' => ['full_name' => 'acme/widgets']]];
    $this->workflows = [['id' => 1, 'name' => 'CI', 'path' => '.github/workflows/ci.yml'], ['id' => 2, 'name' => 'Production smoke', 'state' => 'active', 'path' => '.github/workflows/production-smoke.yml']];
    $this->runs = array_map(fn (int $id): array => ['id' => $id, 'workflow_id' => $id, 'check_suite_id' => $id, 'event' => 'pull_request',
        'head_sha' => $this->head, 'head_branch' => 'task-'.$this->group->id, 'status' => 'completed', 'conclusion' => 'success'], [1, 2]);
    $this->checks = [new GitHubCheckRun('Required checks', 'success', null, 1, status: 'completed', headSha: $this->head, appSlug: 'github-actions', checkSuiteId: 1),
        new GitHubCheckRun('smoke', 'success', null, 2, status: 'completed', headSha: $this->head, appSlug: 'github-actions', checkSuiteId: 2)];
    GitHubTestSupport::storeApp();
    $github = Mockery::mock(GitHubApi::class);
    $github->shouldReceive('repositoryInstallation')->andReturn(123);
    $github->shouldReceive('repositoryPullRequestReadToken')->andReturn('fixture-read');
    $github->shouldReceive('repositoryReadToken')->andReturn('fixture-contents');
    $github->shouldReceive('repositoryChecksToken')->andReturn('fixture-checks');
    $github->shouldReceive('checkRuns')->andReturnUsing(fn () => $this->checks);
    app()->instance(GitHubApi::class, $github);
    $this->github = $github;
    $this->content = ['composer.lock' => json_encode(['packages' => [], 'packages-dev' => []]), 'composer.json' => '{}', 'package.json' => '{}', 'package-lock.json' => '{"lockfileVersion":3,"packages":{}}'];
    $this->baseContent = $this->content;
    $this->reads = 0;
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (str_starts_with($path, '/repos/acme/widgets/contents/')) {
            $file = rawurldecode(basename($path));
            $content = $request['ref'] === $this->head ? ($this->content[$file] ?? null) : ($this->baseContent[$file] ?? null);
            if ($content === null) {
                return Http::response(['message' => 'Not Found'], 404);
            }

            return Http::response($this->contentResponse ?? ['type' => 'file', 'path' => $file, 'encoding' => 'base64', 'content' => base64_encode($content), 'size' => strlen($content)]);
        }
        if ($path === '/repos/acme/widgets/pulls/7' && ($this->disableDuringRead ?? false)) {
            config(['dependency_merge.projects' => []]);
        }

        return Http::response(match ($path) {
            '/repos/acme/widgets/installation' => ['id' => 123],
            '/app/installations/123/access_tokens' => ['token' => 'fixture-actions', 'permissions' => ['actions' => 'read']],
            '/repos/acme/widgets' => $this->repo,
            '/repos/acme/widgets/pulls/7/files' => ($this->filePages ?? [])[(int) $request['page']] ?? array_slice($this->files, ((int) $request['page'] - 1) * 100, 100),
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

it('requires the exact configured active smoke workflow', function (string $change): void {
    match ($change) {
        'removed' => array_pop($this->workflows),
        'renamed' => $this->workflows[1]['path'] = '.github/workflows/other.yml',
        'disabled' => $this->workflows[1]['state'] = 'disabled_manually',
        'unconfigured' => config(['dependency_merge.projects.'.$this->project->id.'.smoke_workflow' => null]),
    };
    $this->withHeader('Idempotency-Key', 'no-smoke')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.smoke_missing');
})->with(['removed', 'renamed', 'disabled', 'unconfigured']);

it('refuses paths outside dependency manifests including rename sources', function (string $path, ?string $previous): void {
    $this->files = [['filename' => $path, 'status' => $previous === null ? 'modified' : 'renamed']];
    if ($previous !== null) {
        $this->files[0]['previous_filename'] = $previous;
    }
    $this->withHeader('Idempotency-Key', 'files')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.files_forbidden');
    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
})->with([
    ['.github/workflows/ci.yml', null], ['.github/workflows/production-smoke.yml', null],
    ['app/Http/Controllers/HomeController.php', null], ['tests/Browser/SmokeTest.php', null],
    ['composer.lock', '.github/workflows/ci.yml'], ['package.json', 'app/code.php'],
    ['../composer.lock', null], ['nested/package.json', null], ['bun.lockb', null], ['bun.lock', 'bun.lockb'],
]);

it('fails closed on incomplete or ambiguous paged file evidence', function (string $change): void {
    match ($change) {
        'missing-count' => $this->pr['changed_files'] = null,
        'zero' => $this->pr['changed_files'] = 0,
        'cap' => $this->pr['changed_files'] = 3000,
        'over-cap' => $this->pr['changed_files'] = 3001,
        'short' => $this->pr['changed_files'] = 2,
        'truncated-page' => $this->pr['changed_files'] = 101,
        'extra-page' => $this->filePages = [2 => $this->files],
        'duplicate' => [$this->pr['changed_files'], $this->files] = [2, [...$this->files, ...$this->files]],
        'rename-missing-source' => $this->files[0]['status'] = 'renamed',
    };
    $this->withHeader('Idempotency-Key', 'unreadable')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.files_unreadable');
})->with(['missing-count', 'zero', 'cap', 'over-cap', 'short', 'truncated-page', 'extra-page', 'duplicate', 'rename-missing-source']);

it('accepts only complete dependency file evidence including both rename paths', function (): void {
    $this->files = [['filename' => 'composer.json', 'status' => 'modified'], ['filename' => 'package-lock.json', 'status' => 'renamed', 'previous_filename' => 'npm-shrinkwrap.json']];
    $this->pr['changed_files'] = 2;
    $this->withHeader('Idempotency-Key', 'allowed-files')->postJson($this->url, $this->body)->assertAccepted();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/files?per_page=100&page=2'));
});

it('rejects a forged batch marker without Gateway-recorded Services provenance', function (?string $creator): void {
    $this->group->update(['created_by_node_id' => $creator === null ? null : $this->fixture->owner->id]);
    $this->withHeader('Idempotency-Key', 'forged-batch')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.batch_required');
    Http::assertNothingSent();
})->with([null, 'other-node']);

it('records group creation from authenticated identity and refuses caller supplied provenance', function (): void {
    $payload = ['project_id' => $this->project->id, 'title' => 'Batch', 'brief' => 'Swamp schedule: batch-'.str_repeat('b', 64)];
    $id = $this->postJson('/api/v1/task-groups', $payload)->assertCreated()->json('data.id');
    expect(Task::topLevel()->findOrFail($id)->created_by_node_id)->toBe($this->fixture->caller->id);
    $this->postJson('/api/v1/task-groups', [...$payload, 'created_by_node_id' => $this->fixture->owner->id])->assertUnprocessable();
    $this->patchJson('/api/v1/task-groups/'.$id, ['created_by_node_id' => $this->fixture->owner->id])->assertUnprocessable();
    expect(Task::topLevel()->findOrFail($id)->created_by_node_id)->toBe($this->fixture->caller->id);
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
    $this->github->shouldReceive('repositorySquashToken')->andReturn('fixture-write-secret');
    $this->github->shouldReceive('squashPullRequest')->once()->with('fixture-write-secret', Mockery::any(), 7, $this->head)
        ->andReturn(new GitHubMergeResult(true, str_repeat('b', 40), 200, 'Merged'));
    $path = '/api/v1/operation-executor/'.$id;
    $proof = ['merged' => true, 'head_sha' => $this->head, 'merge_sha' => str_repeat('b', 40)];
    $this->postJson($path.'/complete', ['worker' => 'proof', 'status' => 'succeeded', 'result' => $proof])->assertConflict();
    $this->postJson($path.'/run', $request)->assertOk()->assertJsonPath('data', $proof);
    $this->postJson($path.'/run', $request)->assertOk()->assertJsonPath('data', $proof);
    $this->postJson($path.'/step', ['worker' => 'proof', 'key' => 'done', 'name' => 'squash', 'status' => 'succeeded', 'result' => $proof])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'proof', 'status' => 'succeeded', 'result' => $proof])->assertOk()->assertJsonPath('data.status', 'succeeded');
    $receipt = Operation::findOrFail($id);
    expect(json_encode($receipt->toArray()))->not->toContain('fixture-write-secret');
    expect($receipt->state['executor_runs']['squash']['references'])->toBe(['token' => ['$secret' => 'github.dependency_merge', 'id' => $this->project->id]]);
    expect($receipt->state['executor_runs']['squash']['fingerprint'])->toBe(hash('sha256', json_encode(array_diff_key($request, ['worker' => true]), JSON_THROW_ON_ERROR)));
});

it('rechecks enablement and exact-head CI at the effect boundary', function (string $change): void {
    [$id, $request] = dependencyMergeClaim($this);
    $this->github->shouldReceive('repositorySquashToken')->andReturn('fixture-write-secret');
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
    if ($change === 'files') {
        $this->files[0]['filename'] = '.github/workflows/ci.yml';
    }
    if ($change === 'smoke') {
        array_pop($this->workflows);
    }
    if ($change === 'policy') {
        config(['dependency_merge.projects.'.$this->project->id.'.smoke_workflow' => '.github/workflows/other.yml']);
    }
    if ($change === 'head') {
        $this->pr['head']['sha'] = str_repeat('d', 40);
    }
    $response = $this->postJson('/api/v1/operation-executor/'.$id.'/run', $request)->assertOk();
    expect($response->json('data.error_code'))->toStartWith('dependency_merge.');
})->with(['disabled', 'checks', 'head', 'during-read', 'files', 'smoke', 'policy']);

it('reconciles a lost GitHub merge response without sending another merge', function (): void {
    [$id, $request] = dependencyMergeClaim($this);
    $this->github->shouldReceive('repositorySquashToken')->andReturn('fixture-write-secret');
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
    $this->github->shouldNotReceive('repositorySquashToken');
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

it('refuses direct secret resolution for a different kind target or Project', function (string $change): void {
    $this->github->shouldNotReceive('repositorySquashToken');
    $op = new Operation(['kind' => 'tasks.dependency_merge', 'target_type' => 'task_group', 'target_id' => $this->group->id]);
    $id = $this->project->id;
    if ($change === 'kind') {
        $op->kind = 'tasks.publish';
    } elseif ($change === 'target') {
        $op->target_type = 'instance';
    } else {
        $id++;
    }
    expect(fn () => app(DependencyMergeSecret::class)->resolve($op, $id, 'squash', 'token'))->toThrow(ResourceOperationException::class);
})->with(['kind', 'target', 'owner']);

it('redacts both hydrated input frames when command execution throws', function (): void {
    $old = ini_set('zend.exception_ignore_args', '0');
    try {
        $op = new Operation(['kind' => 'tasks.dependency_merge', 'target_type' => 'task_group', 'target_id' => $this->group->id]);
        try {
            app(DependencyBatchMergeCommand::class)->run($op, ['publish_operation_id' => [], 'head_sha' => $this->head, 'token' => 'fixture-stack-secret']);
            test()->fail('The malformed input must throw.');
        } catch (InvalidArgumentException $exception) {
            $frames = array_values(array_filter($exception->getTrace(), static fn (array $frame): bool => ($frame['class'] ?? null) === DependencyBatchMergeCommand::class));
            expect(array_column($frames, 'function'))->toBe(['merge', 'run']);
            foreach ($frames as $frame) {
                expect($frame['args'][1])->toBeInstanceOf(SensitiveParameterValue::class);
            }
            expect($exception->getTraceAsString())->not->toContain('fixture-stack-secret');
        }
    } finally {
        ini_set('zend.exception_ignore_args', $old);
    }
});

function dependencyContent(object $test, string $file, string $head, string $base = '{}'): void
{
    $test->files = [['filename' => $file, 'status' => 'modified']];
    $test->content[$file] = $head;
    $test->baseContent[$file] = $base;
}

it('refuses a green PR that changes executable or repository manifest instructions', function (string $file, string $field, mixed $before, mixed $after): void {
    dependencyContent($this, $file, json_encode([$field => $after]), json_encode([$field => $before]));
    $this->withHeader('Idempotency-Key', 'manifest')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
})->with([
    ['composer.json', 'scripts', ['lint:check' => 'pint --test', 'analyse' => 'phpstan', 'test:full' => 'pest', 'test:smoke' => 'pest --group=smoke'], ['lint:check' => 'true', 'analyse' => 'true', 'test:full' => 'true', 'test:smoke' => 'true']],
    ['package.json', 'scripts', ['build' => 'vite build'], ['build' => 'true']],
    ['composer.json', 'repositories', [], [['type' => 'vcs', 'url' => 'https://github.com/fork/framework']]],
    ['composer.json', 'config', ['allow-plugins' => false], ['allow-plugins' => true]],
    ['composer.json', 'autoload', ['files' => []], ['files' => ['vendor/evil.php']]],
    ['composer.json', 'extra', ['laravel' => []], ['laravel' => ['providers' => ['Evil']]]],
    ['package.json', 'overrides', ['vite' => '1.0.0'], ['vite' => 'npm:evil@1.0.0']],
    ['package.json', 'resolutions', ['vite' => '1.0.0'], ['vite' => 'npm:evil@1.0.0']],
    ['package.json', 'packageManager', 'npm@11.0.0', 'npm@12.0.0'],
    ['composer.json', 'scripts', ['test:full' => 'pest'], null],
    ['package.json', 'scripts', ['test' => 'node test.js'], (object) []],
]);

it('allows only dependency constraint map changes with unchanged build and test instructions', function (string $file, string $field): void {
    $unchanged = ['scripts' => ['test' => 'real-test'], 'config' => ['allow-plugins' => false], 'extra' => ['first' => 1, 'second' => ['a', 'b']]];
    dependencyContent($this, $file, json_encode([...$unchanged, $field => ['acme/library' => '^2.0']]), json_encode([$field => ['acme/library' => '^1.0'], ...array_reverse($unchanged, true)]));
    $this->withHeader('Idempotency-Key', 'constraints')->postJson($this->url, $this->body)->assertAccepted();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/contents/'.$file.'?ref='.$this->head));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/contents/'.$file.'?ref='.str_repeat('b', 40)));
})->with([
    ['composer.json', 'require'], ['composer.json', 'require-dev'], ['package.json', 'dependencies'],
    ['package.json', 'devDependencies'], ['package.json', 'optionalDependencies'], ['package.json', 'peerDependencies'],
]);

it('refuses malformed or ambiguous manifests at either revision', function (string $head, string $base): void {
    dependencyContent($this, 'composer.json', $head, $base);
    $this->withHeader('Idempotency-Key', 'bad-json')->postJson($this->url, $this->body)->assertConflict();
    expect(Operation::query()->where('kind', 'tasks.dependency_merge')->count())->toBe(0);
})->with([
    ['{', '{}'], ['{}', '{'], ['[]', '{}'], ['{}', '[]'],
    ['{"scripts":{},"scripts":{"test":"true"}}', '{}'],
    ['{"require":[]}', '{}'], ['{"require":{"a/b":true}}', '{}'],
]);

it('refuses removed nondependency keys and ordered list changes', function (string $head, string $base): void {
    dependencyContent($this, 'package.json', $head, $base);
    $this->withHeader('Idempotency-Key', 'keys')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
})->with([
    ['{}', '{"scripts":{"test":"real-test"}}'],
    ['{"files":["b","a"]}', '{"files":["a","b"]}'],
    ['{"scripts":[]}', '{"scripts":{}}'],
]);

it('refuses unreadable or truncated GitHub content evidence', function (string $change): void {
    dependencyContent($this, 'composer.json', '{}');
    $this->contentResponse = ['type' => 'file', 'path' => 'composer.json', 'encoding' => 'base64', 'content' => base64_encode('{}'), 'size' => 2];
    match ($change) {
        'size' => $this->contentResponse['size'] = 99,
        'encoding' => $this->contentResponse['encoding'] = 'none',
        'base64' => $this->contentResponse['content'] = '%invalid%',
        'symlink' => $this->contentResponse['type'] = 'symlink',
        'path' => $this->contentResponse['path'] = 'other.json',
    };
    $this->withHeader('Idempotency-Key', 'content-evidence')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_unreadable');
})->with(['size', 'encoding', 'base64', 'symlink', 'path']);

it('refuses a manifest absent at the base instead of trusting new scripts', function (): void {
    dependencyContent($this, 'composer.json', '{"scripts":{"test:full":"true"}}');
    unset($this->baseContent['composer.json']);
    $this->withHeader('Idempotency-Key', 'absent-base')->postJson($this->url, $this->body)
        ->assertStatus(503)->assertJsonPath('error.code', 'dependency_merge.evidence_unavailable');
});

it('requires approved Composer dist and source hosts in both dependency sections', function (string $section, string $field, string $url): void {
    $package = ['name' => 'acme/library', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://api.github.com/repos/acme/library/zipball/'.str_repeat('a', 40)], 'source' => ['type' => 'git', 'url' => 'https://github.com/acme/library.git']];
    $package[$field]['url'] = $url;
    dependencyContent($this, 'composer.lock', json_encode(['packages' => $section === 'packages' ? [$package] : [], 'packages-dev' => $section === 'packages-dev' ? [$package] : []]));
    $this->withHeader('Idempotency-Key', 'composer-source')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
})->with([
    ['packages', 'dist', 'https://evil.example/library.zip'], ['packages-dev', 'dist', 'https://evil.example/library.zip'],
    ['packages', 'source', 'https://evil.example/library.git'], ['packages-dev', 'source', 'https://evil.example/library.git'],
    ['packages', 'dist', 'git+https://api.github.com/acme/library'], ['packages', 'dist', 'file:../payload.zip'],
    ['packages', 'source', 'link:../payload'], ['packages', 'source', 'git+https://github.com/acme/library.git'],
    ['packages', 'dist', 'https://api.github.com.evil.example/archive.zip'], ['packages', 'dist', 'https://token@api.github.com/archive.zip'],
    ['packages', 'dist', 'http://api.github.com/archive.zip'],
]);

it('accepts Composer registry archives and HTTPS source metadata', function (string $host): void {
    dependencyContent($this, 'composer.lock', json_encode(['packages' => [['dist' => ['type' => 'zip', 'url' => 'https://'.$host.'/archive.zip'], 'source' => ['type' => 'git', 'url' => 'https://github.com/acme/library.git']]], 'packages-dev' => []]));
    $this->withHeader('Idempotency-Key', 'composer-approved')->postJson($this->url, $this->body)->assertAccepted();
})->with(['repo.packagist.org', 'api.github.com', 'codeload.github.com']);

it('refuses off-registry and local JavaScript lock sources', function (string $file, string $source): void {
    $content = match ($file) {
        'package-lock.json', 'npm-shrinkwrap.json' => json_encode(['lockfileVersion' => 3, 'packages' => ['node_modules/library' => ['version' => '1.0.0', 'resolved' => $source]]]),
        'pnpm-lock.yaml' => "lockfileVersion: '9.0'\npackages:\n  library@1.0.0:\n    resolution:\n      tarball: ".json_encode($source)."\n",
        'bun.lock' => json_encode(['lockfileVersion' => 1, 'packages' => ['library' => ['library@1.0.0', $source, (object) [], 'sha512-fixture']]]),
    };
    dependencyContent($this, $file, $content);
    $this->withHeader('Idempotency-Key', 'js-source')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
})->with(['package-lock.json', 'npm-shrinkwrap.json', 'pnpm-lock.yaml', 'bun.lock'])
    ->with(['https://evil.example/library.tgz', 'git+https://github.com/acme/library.git', 'file:../library', 'link:../library', 'https://registry.npmjs.org.evil.example/library.tgz', 'https://token@registry.npmjs.org/library.tgz']);

it('accepts each supported registry lock format', function (string $file, string $content): void {
    dependencyContent($this, $file, $content);
    $this->withHeader('Idempotency-Key', 'registry-format')->postJson($this->url, $this->body)->assertAccepted();
})->with([
    ['package-lock.json', '{"lockfileVersion":3,"packages":{"node_modules/library":{"resolved":"https://registry.npmjs.org/library/-/library-1.0.0.tgz"}}}'],
    ['package-lock.json', '{"lockfileVersion":1,"dependencies":{"library":{"resolved":"https://registry.npmjs.org/library/-/library-1.0.0.tgz"}}}'],
    ['npm-shrinkwrap.json', '{"lockfileVersion":2,"packages":{},"dependencies":{"library":{"resolved":"https://registry.npmjs.org/library/-/library-1.0.0.tgz"}}}'],
    ['pnpm-lock.yaml', "lockfileVersion: '9.0'\npackages:\n  library@1.0.0:\n    resolution:\n      integrity: sha512-fixture\n"],
    ['bun.lock', "{\n// Bun text lock\n\"lockfileVersion\":2,\"packages\":{\"library\":[\"library@1.0.0\",\"\",{},\"sha512-fixture\"],},\n}"],
]);

it('refuses hidden npm v1 dependencies and local link records', function (string $content): void {
    dependencyContent($this, 'package-lock.json', $content);
    $this->withHeader('Idempotency-Key', 'nested-source')->postJson($this->url, $this->body)->assertConflict();
})->with([
    '{"lockfileVersion":1,"dependencies":{"library":{"resolved":"https://registry.npmjs.org/library.tgz","dependencies":{"hidden":{"resolved":"https://evil.example/hidden.tgz"}}}}}',
    '{"lockfileVersion":3,"packages":{"node_modules/library":{"link":true,"resolved":"https://registry.npmjs.org/library.tgz"}}}',
    '{"lockfileVersion":3,"packages":{"node_modules/library":{"version":"git+https://github.com/acme/library","resolved":"https://registry.npmjs.org/library.tgz"}}}',
    '{"lockfileVersion":3,"packages":{"node_modules/library":{}}}',
]);

it('refuses pnpm and Bun sources hidden in identities or dependency edges', function (string $file, string $content): void {
    dependencyContent($this, $file, $content);
    $this->withHeader('Idempotency-Key', 'source-edge')->postJson($this->url, $this->body)->assertConflict();
})->with([
    ['pnpm-lock.yaml', "lockfileVersion: '9.0'\npackages:\n  library@file:../library:\n    resolution: {integrity: sha512-fixture}\n"],
    ['pnpm-lock.yaml', "lockfileVersion: '9.0'\npackages: {}\nimporters:\n  .:\n    dependencies:\n      library:\n        version: link:../library\n"],
    ['pnpm-lock.yaml', "lockfileVersion: '9.0'\npackages: {}\nimporters:\n  .:\n    dependencies:\n      library:\n        version: https://evil.example/library.tgz\n"],
    ['bun.lock', '{"lockfileVersion":1,"packages":{"library":["library@git+https://github.com/acme/library","",{},"sha512-fixture"]}}'],
    ['bun.lock', '{"lockfileVersion":1,"packages":{"library":["library@1.0.0","",{"dependencies":{"hidden":"file:../hidden"}},"sha512-fixture"]}}'],
]);

it('refuses malformed and unsupported lock formats', function (string $file, string $content): void {
    dependencyContent($this, $file, $content);
    $this->withHeader('Idempotency-Key', 'invalid-lock')->postJson($this->url, $this->body)->assertConflict();
})->with([
    ['composer.lock', '{'], ['composer.lock', '{"packages":{},"packages-dev":[]}'],
    ['package-lock.json', '{'], ['package-lock.json', '{"lockfileVersion":99,"packages":{}}'],
    ['pnpm-lock.yaml', "packages: [\n"], ['pnpm-lock.yaml', "lockfileVersion: '9.0'\npackages: {123: {resolution: {integrity: sha512-fixture}}}\n"], ['pnpm-lock.yaml', "lockfileVersion: '9.0'\npackages: {}\npackages: {}\n"],
    ['bun.lock', '{"lockfileVersion":1,"packages":{'], ['bun.lock', "#!/usr/bin/env bun\0binary"],
    ['yarn.lock', '# yarn lockfile v1'],
]);

it('rechecks manifest content at the effect boundary after green admission', function (): void {
    dependencyContent($this, 'composer.json', '{"scripts":{"test:full":"pest"}}', '{"scripts":{"test:full":"pest"}}');
    [$id, $request] = dependencyMergeClaim($this);
    $this->github->shouldReceive('repositorySquashToken')->andReturn('fixture-write-secret');
    $this->github->shouldNotReceive('squashPullRequest');
    $this->content['composer.json'] = '{"scripts":{"test:full":"true"}}';
    $this->postJson('/api/v1/operation-executor/'.$id.'/run', $request)->assertOk()->assertJsonPath('data.error_code', 'dependency_merge.contents_forbidden');
});
