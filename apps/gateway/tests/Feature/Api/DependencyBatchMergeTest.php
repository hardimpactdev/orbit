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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
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
    $this->registry = ['acme/library' => dependencyRegistryPackage()];
    $this->reads = 0;
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (parse_url($request->url(), PHP_URL_HOST) === 'repo.packagist.org') {
            $name = substr($path, strlen('/p2/'), -strlen('.json'));

            return Http::response($this->registryResponse ?? ['packages' => [$name => [$this->registry[$name] ?? []]]], $this->registryStatus ?? 200);
        }
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

function dependencyContent(object $test, string $file, string $head, ?string $base = null): void
{
    $test->files = [['filename' => $file, 'status' => 'modified']];
    $test->content[$file] = $head;
    $test->baseContent[$file] = $base ?? (in_array($file, ['composer.json', 'package.json'], true) ? '{}' : $head);
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
    dependencyContent($this, $file, json_encode([...$unchanged, $field => ['acme/library' => '^1.1']]), json_encode([$field => ['acme/library' => '^1.0'], ...array_reverse($unchanged, true)]));
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
    $package = dependencyRegistryPackage();
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

it('accepts only archives and source metadata attested by Packagist', function (string $host): void {
    $this->content['composer.json'] = '{"require":{"acme/library":"^1.0"}}';
    $package = dependencyRegistryPackage();
    $package['dist']['url'] = 'https://'.$host.'/archive.zip';
    $this->registry['acme/library'] = $package;
    dependencyContent($this, 'composer.lock', json_encode(['packages' => [$package], 'packages-dev' => []]));
    $this->withHeader('Idempotency-Key', 'composer-approved')->postJson($this->url, $this->body)->assertAccepted();
})->with(['repo.packagist.org', 'api.github.com', 'codeload.github.com']);

it('refuses off-registry and local JavaScript lock sources', function (string $file, string $source): void {
    $content = match ($file) {
        'package-lock.json', 'npm-shrinkwrap.json' => json_encode(['lockfileVersion' => 3, 'packages' => ['node_modules/library' => ['version' => '1.0.0', 'integrity' => dependencyIntegrity(), 'resolved' => $source]]]),
        'pnpm-lock.yaml' => "lockfileVersion: '9.0'\npackages:\n  library@1.0.0:\n    resolution:\n      tarball: ".json_encode($source)."\n",
        'bun.lock' => json_encode(['lockfileVersion' => 1, 'packages' => ['library' => ['library@1.0.0', $source, (object) [], 'sha512-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA==']]]),
    };
    dependencyContent($this, $file, $content);
    $this->withHeader('Idempotency-Key', 'js-source')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
})->with(['package-lock.json', 'npm-shrinkwrap.json', 'pnpm-lock.yaml', 'bun.lock'])
    ->with(['https://evil.example/library.tgz', 'git+https://github.com/acme/library.git', 'file:../library', 'link:../library', 'https://registry.npmjs.org.evil.example/library.tgz', 'https://token@registry.npmjs.org/library.tgz']);

it('accepts each supported registry lock format', function (string $file): void {
    $this->content['package.json'] = '{"dependencies":{"library":"^1.0"}}';
    $content = dependencyJsGraph($file, ['library' => '^1.0'], ['library' => ['1.0.0', []]]);
    if ($file === 'bun.lock') {
        $content = '// Bun text lock'."\n".substr($content, 0, -1).',}';
    }
    dependencyContent($this, $file, $content);
    $this->withHeader('Idempotency-Key', 'registry-format')->postJson($this->url, $this->body)->assertAccepted();
})->with(['package-lock.json', 'npm-shrinkwrap.json', 'pnpm-lock.yaml', 'bun.lock']);

it('refuses npm v1 without a complete package graph', function (): void {
    dependencyContent($this, 'package-lock.json', '{"lockfileVersion":1,"dependencies":{}}');
    $this->withHeader('Idempotency-Key', 'old-format')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
});

it('refuses hidden npm v1 dependencies and local link records', function (string $content): void {
    dependencyContent($this, 'package-lock.json', $content);
    $this->withHeader('Idempotency-Key', 'nested-source')->postJson($this->url, $this->body)->assertConflict();
})->with([
    '{"lockfileVersion":1,"dependencies":{"library":{"version":"1.0.0","integrity":"sha512-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA==","resolved":"https://registry.npmjs.org/library/-/library-1.0.0.tgz","dependencies":{"hidden":{"version":"1.0.0","integrity":"sha512-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA==","resolved":"https://evil.example/hidden.tgz"}}}}}',
    '{"lockfileVersion":3,"packages":{"node_modules/library":{"link":true,"resolved":"https://registry.npmjs.org/library/-/library-1.0.0.tgz"}}}',
    '{"lockfileVersion":3,"packages":{"node_modules/library":{"version":"git+https://github.com/acme/library","resolved":"https://registry.npmjs.org/library/-/library-1.0.0.tgz"}}}',
    '{"lockfileVersion":3,"packages":{"node_modules/library":{}}}',
]);

it('refuses pnpm and Bun sources hidden in identities or dependency edges', function (string $file, string $content): void {
    dependencyContent($this, $file, $content);
    $this->withHeader('Idempotency-Key', 'source-edge')->postJson($this->url, $this->body)->assertConflict();
})->with([
    ['pnpm-lock.yaml', "lockfileVersion: '9.0'\npackages:\n  library@file:../library:\n    resolution: {integrity: sha512-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA==}\n"],
    ['pnpm-lock.yaml', "lockfileVersion: '9.0'\npackages: {}\nimporters:\n  .:\n    dependencies:\n      library:\n        version: link:../library\n"],
    ['pnpm-lock.yaml', "lockfileVersion: '9.0'\npackages: {}\nimporters:\n  .:\n    dependencies:\n      library:\n        version: https://evil.example/library.tgz\n"],
    ['bun.lock', '{"lockfileVersion":1,"packages":{"library":["library@git+https://github.com/acme/library","",{},"sha512-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=="]}}'],
    ['bun.lock', '{"lockfileVersion":1,"packages":{"library":["library@1.0.0","",{"dependencies":{"hidden":"file:../hidden"}},"sha512-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=="]}}'],
]);

it('refuses malformed and unsupported lock formats', function (string $file, string $content): void {
    dependencyContent($this, $file, $content);
    $this->withHeader('Idempotency-Key', 'invalid-lock')->postJson($this->url, $this->body)->assertConflict();
})->with([
    ['composer.lock', '{'], ['composer.lock', '{"packages":{},"packages-dev":[]}'],
    ['package-lock.json', '{'], ['package-lock.json', '{"lockfileVersion":99,"packages":{}}'],
    ['pnpm-lock.yaml', "packages: [\n"], ['pnpm-lock.yaml', "lockfileVersion: '9.0'\npackages: {123: {resolution: {integrity: sha512-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA==}}}\n"], ['pnpm-lock.yaml', "lockfileVersion: '9.0'\npackages: {}\npackages: {}\n"],
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

function dependencyIntegrity(): string
{
    return 'sha512-'.base64_encode(str_repeat("\0", 64));
}

function dependencyRegistryPackage(string $version = '1.0.0'): array
{
    return ['name' => 'acme/library', 'version' => $version, 'type' => 'library',
        'dist' => ['type' => 'zip', 'url' => 'https://api.github.com/repos/acme/library/zipball/'.str_repeat('a', 40), 'reference' => str_repeat('a', 40), 'shasum' => ''],
        'source' => ['type' => 'git', 'url' => 'https://github.com/acme/library.git', 'reference' => str_repeat('a', 40)],
        'autoload' => ['psr-4' => ['Acme\\' => 'src/']], 'extra' => ['laravel' => ['providers' => []]], 'bin' => ['bin/library']];
}

it('refuses Composer mirror and transport instructions before any registry read', function (string $entry, string $field): void {
    $package = dependencyRegistryPackage();
    if ($entry === 'package') {
        $package[$field] = ['https://evil.example/payload.zip'];
    } else {
        $package[$entry][$field] = [['url' => 'https://evil.example/payload.zip', 'preferred' => true]];
    }
    dependencyContent($this, 'composer.lock', json_encode(['packages' => [$package], 'packages-dev' => []]));
    $this->withHeader('Idempotency-Key', 'mirror')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
    Http::assertNotSent(fn ($request) => parse_url($request->url(), PHP_URL_HOST) === 'repo.packagist.org');
})->with([
    ['dist', 'mirrors'], ['source', 'mirrors'], ['dist', 'transport-options'], ['source', 'transport-options'],
    ['package', 'transport-options'], ['package', 'unknown-execution-key'],
]);

it('accepts only Packagists notification URL', function (): void {
    $this->content['composer.json'] = '{"require":{"acme/library":"^1.0"}}';
    $package = dependencyRegistryPackage();
    $package['notification-url'] = 'https://evil.example/downloads/';
    dependencyContent($this, 'composer.lock', json_encode(['packages' => [$package], 'packages-dev' => []]));
    $this->withHeader('Idempotency-Key', 'notification')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
    $package['notification-url'] = 'https://packagist.org/downloads/';
    dependencyContent($this, 'composer.lock', json_encode(['packages' => [$package], 'packages-dev' => []]));
    $this->withHeader('Idempotency-Key', 'notification-approved')->postJson($this->url, $this->body)->assertAccepted();
});

it('binds Composer package code and executable metadata to its Packagist version', function (string $field, mixed $value): void {
    $package = dependencyRegistryPackage();
    Arr::set($package, $field, $value);
    dependencyContent($this, 'composer.lock', json_encode(['packages' => [$package], 'packages-dev' => []]));
    $this->withHeader('Idempotency-Key', 'packagist-binding')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
    Http::assertSent(fn ($request) => $request->url() === 'https://repo.packagist.org/p2/acme/library.json');
})->with([
    ['dist.url', 'https://api.github.com/repos/attacker/x/zipball/'.str_repeat('a', 40)],
    ['dist.url', 'https://api.github.com/repos/acme/library/zipball/'.str_repeat('b', 40)],
    ['dist.reference', str_repeat('b', 40)], ['source.url', 'https://github.com/attacker/x.git'],
    ['source.reference', str_repeat('b', 40)], ['type', 'composer-plugin'],
    ['autoload', ['files' => ['payload.php']]], ['extra', ['laravel' => ['providers' => ['Evil']]]],
    ['bin', ['bin/evil']],
]);

it('fails closed when Packagist metadata is unavailable or unreadable', function (string $change): void {
    dependencyContent($this, 'composer.lock', json_encode(['packages' => [dependencyRegistryPackage()], 'packages-dev' => []]));
    match ($change) {
        'unavailable' => $this->registryStatus = 503,
        'missing' => $this->registryStatus = 404,
        'json' => $this->registryResponse = '{',
        'shape' => $this->registryResponse = ['packages' => []],
        'unknown-minifier' => $this->registryResponse = ['minified' => 'unrecognized', 'packages' => ['acme/library' => []]],
    };
    $this->withHeader('Idempotency-Key', 'packagist-unavailable')->postJson($this->url, $this->body)
        ->assertStatus(503)->assertJsonPath('error.code', 'dependency_merge.packagist_unavailable');
    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
})->with(['unavailable', 'missing', 'json', 'shape', 'unknown-minifier']);

it('expands Packagist P2 inheritance and unset fields before matching', function (): void {
    $this->content['composer.json'] = '{"require":{"acme/library":"^1.0"}}';
    $package = dependencyRegistryPackage();
    unset($package['bin']);
    $this->registryResponse = ['minified' => 'composer/2.0', 'packages' => ['acme/library' => [dependencyRegistryPackage('1.0.1'), ['version' => '1.0.0', 'bin' => '__unset']]]];
    dependencyContent($this, 'composer.lock', json_encode(['packages' => [$package], 'packages-dev' => []]));
    $this->withHeader('Idempotency-Key', 'minified')->postJson($this->url, $this->body)->assertAccepted();
});

it('refuses a Composer version absent from Packagist', function (): void {
    dependencyContent($this, 'composer.lock', json_encode(['packages' => [dependencyRegistryPackage('1.0.2')], 'packages-dev' => []]));
    $this->withHeader('Idempotency-Key', 'version-not-published')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
});

it('binds npm installs to the final path name version URL and integrity', function (string $change): void {
    $package = ['name' => 'library', 'version' => '1.0.0', 'resolved' => 'https://registry.npmjs.org/library/-/library-1.0.0.tgz', 'integrity' => dependencyIntegrity()];
    match ($change) {
        'name-swap' => $package['name'] = 'attacker',
        'tarball-swap' => $package['resolved'] = 'https://registry.npmjs.org/attacker/-/attacker-1.0.0.tgz',
        'version-swap' => $package['resolved'] = 'https://registry.npmjs.org/library/-/library-2.0.0.tgz',
        'missing-integrity' => $package['integrity'] = null,
        'invalid-integrity' => $package['integrity'] = 'sha512-invalid',
    };
    dependencyContent($this, 'package-lock.json', json_encode(['lockfileVersion' => 3, 'packages' => ['node_modules/parent/node_modules/library' => $package]]));
    $this->withHeader('Idempotency-Key', 'npm-binding')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
})->with(['name-swap', 'tarball-swap', 'version-swap', 'missing-integrity', 'invalid-integrity']);

it('accepts a scoped nested npm package with its exact registry tarball', function (): void {
    $this->content['package.json'] = '{"dependencies":{"parent":"^1.0"}}';
    $lock = json_decode(dependencyJsGraph('package-lock.json', ['parent' => '^1.0'], ['parent' => ['1.0.0', ['@acme/library' => '^1.0']], '@acme/library' => ['1.0.0', []]]), true);
    $lock['packages']['node_modules/parent/node_modules/@acme/library'] = $lock['packages']['node_modules/@acme/library'];
    unset($lock['packages']['node_modules/@acme/library']);
    dependencyContent($this, 'package-lock.json', json_encode($lock));
    $this->withHeader('Idempotency-Key', 'scoped-npm')->postJson($this->url, $this->body)->assertAccepted();
});

it('refuses pnpm tarball overrides and package identity swaps', function (string $change): void {
    $record = ['resolution' => ['integrity' => dependencyIntegrity()]];
    match ($change) {
        'tarball' => $record['resolution']['tarball'] = 'https://registry.npmjs.org/attacker/-/attacker-1.0.0.tgz',
        'name' => $record['name'] = 'attacker',
        'version' => $record['version'] = '2.0.0',
    };
    dependencyContent($this, 'pnpm-lock.yaml', Yaml::dump(['lockfileVersion' => '9.0', 'packages' => ['library@1.0.0' => $record]]));
    $this->withHeader('Idempotency-Key', 'pnpm-binding')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
})->with(['tarball', 'name', 'version']);

it('refuses Bun package name swaps and explicit tarballs', function (string $change): void {
    $record = ['library@1.0.0', '', (object) [], dependencyIntegrity()];
    if ($change === 'name') {
        $record[0] = 'attacker@1.0.0';
    } else {
        $record[1] = 'https://registry.npmjs.org/library/-/library-1.0.0.tgz';
    }
    dependencyContent($this, 'bun.lock', json_encode(['lockfileVersion' => 2, 'packages' => ['parent/library' => $record]]));
    $this->withHeader('Idempotency-Key', 'bun-binding')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
})->with(['name', 'tarball']);

it('refuses executable local git and alias dependency specifications', function (string $file, string $spec): void {
    $field = $file === 'composer.json' ? 'require' : 'dependencies';
    dependencyContent($this, $file, json_encode([$field => ['library' => $spec]]), json_encode([$field => ['library' => '^1.0']]));
    $this->withHeader('Idempotency-Key', 'spec')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
})->with(['composer.json', 'package.json'])->with(['file:../x', 'link:../x', 'workspace:*', 'npm:attacker@1.0.0', 'user/repo', 'https://registry.npmjs.org/x.tgz', 'git+https://github.com/user/repo', '^1.0#deadbeef', 'dev-main']);

it('refuses new dependency names', function (string $file, string $field): void {
    dependencyContent($this, $file, json_encode([$field => ['library' => '^1.0', 'new-package' => '^1.0']]), json_encode([$field => ['library' => '^1.0']]));
    $this->withHeader('Idempotency-Key', 'new-key')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
})->with([['composer.json', 'require'], ['composer.json', 'require-dev'], ['package.json', 'dependencies'], ['package.json', 'devDependencies'], ['package.json', 'optionalDependencies'], ['package.json', 'peerDependencies']]);

it('refuses major constraint changes with a hand-test error and no merge effect', function (string $file, string $before, string $after): void {
    $field = $file === 'composer.json' ? 'require' : 'dependencies';
    dependencyContent($this, $file, json_encode([$field => ['library' => $after]]), json_encode([$field => ['library' => $before]]));
    $this->withHeader('Idempotency-Key', 'major-range')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.major_bump');
    expect($this->group->fresh()->pr_url)->toBe('https://github.com/acme/widgets/pull/7');
    expect(Operation::query()->where('kind', 'tasks.dependency_merge')->count())->toBe(0);
    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
})->with([
    ['composer.json', '^1.0', '^2.0'], ['package.json', '^1.0', '^2.0'], ['package.json', '^1.0', 'v2'],
    ['composer.json', '^0.1', '^0.2'], ['package.json', '^0.1', '^0.2'], ['package.json', '^0.4', '^0.9'], ['composer.json', '^0.0.1', '^0.0.2'], ['package.json', '^0.0.1', '^0.0.2'],
    ['composer.json', '>=1.0 <2.0', '^2.0'], ['package.json', '^1 || ^3', '^1 || ^2 || ^3'],
]);

it('refuses a locked top-level major even when its manifest constraint did not change', function (string $file): void {
    if ($file === 'composer.lock') {
        $this->registry['acme/library'] = dependencyRegistryPackage('2.0.0');
        $head = json_encode(['packages' => [dependencyRegistryPackage('2.0.0')], 'packages-dev' => []]);
        $base = json_encode(['packages' => [dependencyRegistryPackage()], 'packages-dev' => []]);
        $this->content['composer.json'] = '{"require":{"acme/library":"*"}}';
    } else {
        $head = dependencyJsGraph($file, ['library' => '*'], ['library' => ['2.0.0', []]]);
        $base = dependencyJsGraph($file, ['library' => '*'], ['library' => ['1.0.0', []]]);
        $this->content['package.json'] = '{"dependencies":{"library":"*"}}';
    }
    dependencyContent($this, $file, $head, $base);
    $this->withHeader('Idempotency-Key', 'major-lock')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.major_bump');
})->with(['composer.lock', 'package-lock.json', 'pnpm-lock.yaml', 'bun.lock']);

it('keeps an agent-repaired batch eligible for the same exact-head gates', function (): void {
    Operation::query()->create(['kind' => 'tasks.turn', 'target_type' => 'task_group', 'target_id' => $this->group->id, 'status' => 'succeeded', 'sequence' => 2, 'input' => ['role' => 'implementer'], 'result' => ['receipt' => ['outcome' => 'repaired']]]);
    $this->withHeader('Idempotency-Key', 'repaired-batch')->postJson($this->url, $this->body)->assertAccepted();
});

it('refuses a removed lockfile explicitly before trying to read it', function (): void {
    $this->files[0]['status'] = 'removed';
    $this->withHeader('Idempotency-Key', 'removed-lock')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.lock_removed');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/contents/'));
});

it('rechecks fresh Packagist evidence before the merge effect', function (): void {
    $this->content['composer.json'] = '{"require":{"acme/library":"^1.0"}}';
    dependencyContent($this, 'composer.lock', json_encode(['packages' => [dependencyRegistryPackage()], 'packages-dev' => []]));
    [$id, $request] = dependencyMergeClaim($this);
    $this->registryStatus = 503;
    $this->github->shouldReceive('repositorySquashToken')->andReturn('fixture-write-secret');
    $this->github->shouldNotReceive('squashPullRequest');
    $this->postJson('/api/v1/operation-executor/'.$id.'/run', $request)->assertOk()->assertJsonPath('data.error_code', 'dependency_merge.packagist_unavailable');
});

it('allows a minor constraint update and npm registry dist tags', function (string $before, string $after): void {
    dependencyContent($this, 'package.json', json_encode(['dependencies' => ['library' => $after]]), json_encode(['dependencies' => ['library' => $before]]));
    $this->withHeader('Idempotency-Key', 'registry-spec')->postJson($this->url, $this->body)->assertAccepted();
})->with([['^1.0', '^1.2'], ['~1.0.0', '~1.1.0'], ['latest', 'next'], ['^0.1.1', '^0.1.2'], ['~0.1.1', '~0.1.2']]);

it('refuses removed dependency names and Composer development constraints', function (string $file, string $head, string $base): void {
    dependencyContent($this, $file, $head, $base);
    $this->withHeader('Idempotency-Key', 'forbidden-map')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
})->with([
    ['composer.json', '{"require":{}}', '{"require":{"library":"^1.0"}}'],
    ['package.json', '{"dependencies":{}}', '{"dependencies":{"library":"^1.0"}}'],
    ['composer.json', '{"require":{"library":"1.x-dev"}}', '{"require":{"library":"^1.0"}}'],
    ['composer.json', '{"require":{"library":"^1.0@dev"}}', '{"require":{"library":"^1.0"}}'],
]);

it('refuses missing top-level lock evidence instead of skipping the major guard', function (): void {
    $this->content['package.json'] = '{"dependencies":{"library":"*"}}';
    dependencyContent($this, 'pnpm-lock.yaml', "lockfileVersion: '9.0'\npackages: {}\n");
    $this->withHeader('Idempotency-Key', 'missing-top-level')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
});

it('permits a registry-attested transitive major while the top-level lock stays in its major', function (): void {
    $root = dependencyRegistryPackage();
    $root['name'] = 'acme/root';
    $root['require'] = ['acme/library' => '^2.0'];
    $this->registry['acme/root'] = $root;
    $this->registry['acme/library'] = dependencyRegistryPackage('2.0.0');
    $this->content['composer.json'] = '{"require":{"acme/root":"^1.0"}}';
    dependencyContent($this, 'composer.lock', json_encode(['packages' => [$root, dependencyRegistryPackage('2.0.0')], 'packages-dev' => []]), json_encode(['packages' => [$root, dependencyRegistryPackage()], 'packages-dev' => []]));
    $this->withHeader('Idempotency-Key', 'transitive-major')->postJson($this->url, $this->body)->assertAccepted();
});

/** @param array<string,string> $roots @param array<string,array{string,array<string,string>}> $packages */
function dependencyJsGraph(string $file, array $roots, array $packages): string
{
    if ($file === 'pnpm-lock.yaml') {
        $entries = $snapshots = $root = [];
        foreach ($packages as $name => [$version, $dependencies]) {
            $entries[$name.'@'.$version] = ['resolution' => ['integrity' => dependencyIntegrity()]];
            $snapshots[$name.'@'.$version] = $dependencies === [] ? (object) [] : ['dependencies' => (object) $dependencies];
        }
        foreach ($roots as $name => $constraint) {
            $root[$name] = ['specifier' => $constraint, 'version' => $packages[$name][0]];
        }

        return Yaml::dump(['lockfileVersion' => '9.0', 'importers' => ['.' => ['dependencies' => (object) $root]], 'packages' => (object) $entries, 'snapshots' => (object) $snapshots], 8, 2, Yaml::DUMP_OBJECT_AS_MAP);
    }
    $entries = [];
    foreach ($packages as $name => [$version, $dependencies]) {
        $info = $dependencies === [] ? (object) [] : ['dependencies' => (object) $dependencies];
        $entries[$file === 'bun.lock' ? $name : 'node_modules/'.$name] = $file === 'bun.lock'
            ? [$name.'@'.$version, '', $info, dependencyIntegrity()]
            : ['version' => $version, 'resolved' => 'https://registry.npmjs.org/'.$name.'/-/'.basename($name).'-'.$version.'.tgz', 'integrity' => dependencyIntegrity(), ...(array) $info];
    }
    $root = ['dependencies' => (object) $roots];
    if ($file === 'bun.lock') {
        return json_encode(['lockfileVersion' => 2, 'workspaces' => ['' => $root], 'packages' => (object) $entries], JSON_THROW_ON_ERROR);
    }

    return json_encode(['lockfileVersion' => 3, 'packages' => (object) ['' => $root, ...$entries]], JSON_THROW_ON_ERROR);
}

it('refuses an unreferenced lock package even with trusted sources and an unchanged base name', function (string $file): void {
    if ($file === 'composer.lock') {
        $this->content['composer.json'] = '{"require":{"acme/library":"^1.0"}}';
        $orphan = dependencyRegistryPackage();
        $orphan['name'] = 'acme/orphan';
        $orphan['autoload'] = ['files' => ['force-green.php']];
        $this->registry['acme/orphan'] = $orphan;
        $clean = json_encode(['packages' => [dependencyRegistryPackage()], 'packages-dev' => []]);
        $hostile = json_encode(['packages' => [dependencyRegistryPackage()], 'packages-dev' => [$orphan]]);
    } else {
        $this->content['package.json'] = '{"dependencies":{"library":"^1.0"}}';
        $clean = dependencyJsGraph($file, ['library' => '^1.0'], ['library' => ['1.0.0', []]]);
        $hostile = dependencyJsGraph($file, ['library' => '^1.0'], ['library' => ['1.0.0', []], 'orphan' => ['1.0.0', []]]);
    }
    dependencyContent($this, $file, $clean);
    $this->withHeader('Idempotency-Key', 'reachable-control')->postJson($this->url, $this->body)->assertAccepted();
    // The name restriction alone cannot reject this: it is also in the base lock.
    dependencyContent($this, $file, $hostile);
    $this->withHeader('Idempotency-Key', 'orphan')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
})->with(['composer.lock', 'package-lock.json', 'npm-shrinkwrap.json', 'pnpm-lock.yaml', 'bun.lock']);

it('refuses newly introduced npm and Bun names even when a lock edge claims them', function (string $file): void {
    $this->content['package.json'] = '{"dependencies":{"library":"^1.0"}}';
    $base = dependencyJsGraph($file, ['library' => '^1.0'], ['library' => ['1.0.0', []]]);
    $head = dependencyJsGraph($file, ['library' => '^1.0'], ['library' => ['1.0.0', ['injected' => '1.0.0']], 'injected' => ['1.0.0', []]]);
    dependencyContent($this, $file, $head, $base);
    $this->withHeader('Idempotency-Key', 'new-lock-name')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
})->with(['package-lock.json', 'npm-shrinkwrap.json', 'bun.lock']);

it('refuses pnpm snapshot name aliases even when they form a reachable graph', function (): void {
    $this->content['package.json'] = '{"dependencies":{"library":"^1.0"}}';
    $head = dependencyJsGraph('pnpm-lock.yaml', ['library' => '^1.0'], ['library' => ['1.0.0', ['lodash' => 'evil@1.0.0']], 'evil' => ['1.0.0', []]]);
    dependencyContent($this, 'pnpm-lock.yaml', $head);
    $this->withHeader('Idempotency-Key', 'snapshot-alias')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
});

it('walks Packagist-attested Composer provider and replacement edges', function (string $field): void {
    $root = dependencyRegistryPackage();
    $root['require'] = ['acme/virtual' => '^1.0'];
    $provider = dependencyRegistryPackage();
    $provider['name'] = 'acme/provider';
    $provider[$field] = ['acme/virtual' => 'self.version'];
    $this->registry['acme/library'] = $root;
    $this->registry['acme/provider'] = $provider;
    $this->content['composer.json'] = '{"require":{"acme/library":"^1.0"}}';
    dependencyContent($this, 'composer.lock', json_encode(['packages' => [$root, $provider], 'packages-dev' => []]));
    $this->withHeader('Idempotency-Key', 'provider')->postJson($this->url, $this->body)->assertAccepted();
})->with(['provide', 'replace']);

it('refuses unapproved Composer lock root instructions', function (string $key, mixed $value): void {
    dependencyContent($this, 'composer.lock', json_encode(['packages' => [], 'packages-dev' => [], $key => $value]));
    $this->withHeader('Idempotency-Key', 'lock-root')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
})->with([
    ['aliases', [['package' => 'acme/library', 'version' => '1.0.0', 'alias' => '2.0.0']]],
    ['platform-overrides', ['php' => '99.0.0']], ['repositories', [['url' => 'https://evil.example']]], ['unknown', true],
]);

it('refuses numeric ranges becoming tags and archive-shaped tags', function (string $after): void {
    dependencyContent($this, 'package.json', json_encode(['dependencies' => ['library' => $after]]), '{"dependencies":{"library":"^1.0.0"}}');
    $this->withHeader('Idempotency-Key', 'range-tag')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
})->with(['latest', 'x.tgz', 'x.tar.gz']);

it('refuses npm names outside the registry name grammar', function (string $name): void {
    $this->content['package.json'] = json_encode(['dependencies' => [$name => '^1.0']]);
    dependencyContent($this, 'package-lock.json', dependencyJsGraph('package-lock.json', [$name => '^1.0'], [$name => ['1.0.0', []]]));
    $this->withHeader('Idempotency-Key', 'npm-name')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
})->with(['.', '..', '_private', '@acme/_private', '@_private/library', 'Capital']);

it('refuses locked zero-major breaking changes for every format', function (string $file, string $before, string $after): void {
    if ($file === 'composer.lock') {
        $this->registry['acme/library'] = dependencyRegistryPackage($after);
        $this->content['composer.json'] = '{"require":{"acme/library":"*"}}';
        $head = json_encode(['packages' => [dependencyRegistryPackage($after)], 'packages-dev' => []]);
        $base = json_encode(['packages' => [dependencyRegistryPackage($before)], 'packages-dev' => []]);
    } else {
        $this->content['package.json'] = '{"dependencies":{"library":"*"}}';
        $head = dependencyJsGraph($file, ['library' => '*'], ['library' => [$after, []]]);
        $base = dependencyJsGraph($file, ['library' => '*'], ['library' => [$before, []]]);
    }
    dependencyContent($this, $file, $head, $base);
    $this->withHeader('Idempotency-Key', 'zero-breaking')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.major_bump');
})->with(['composer.lock', 'package-lock.json', 'pnpm-lock.yaml', 'bun.lock'])
    ->with([['0.4.0', '0.9.0'], ['0.0.4', '0.0.9']]);

it('refuses archive-shaped tags independently of the range-to-tag guard', function (string $tag): void {
    dependencyContent($this, 'package.json', json_encode(['dependencies' => ['library' => $tag]]), '{"dependencies":{"library":"latest"}}');
    $this->withHeader('Idempotency-Key', 'archive-tag')->postJson($this->url, $this->body)
        ->assertConflict()->assertJsonPath('error.code', 'dependency_merge.contents_forbidden');
})->with(['x.tgz', 'x.tar.gz']);

it('checks the actual top-level Composer provider version for breaking changes', function (string $version, bool $accepted): void {
    $base = dependencyRegistryPackage();
    $base['provide'] = ['acme/virtual' => '*'];
    $head = dependencyRegistryPackage($version);
    $head['provide'] = ['acme/virtual' => '*'];
    $this->registry['acme/library'] = $head;
    $this->content['composer.json'] = '{"require":{"acme/virtual":"*"}}';
    dependencyContent($this, 'composer.lock', json_encode(['packages' => [$head], 'packages-dev' => []]), json_encode(['packages' => [$base], 'packages-dev' => []]));
    $response = $this->withHeader('Idempotency-Key', 'provider-version')->postJson($this->url, $this->body);
    if ($accepted) {
        $response->assertAccepted();
    } else {
        $response->assertConflict()->assertJsonPath('error.code', 'dependency_merge.major_bump');
    }
})->with([['1.1.0', true], ['2.0.0', false]]);
