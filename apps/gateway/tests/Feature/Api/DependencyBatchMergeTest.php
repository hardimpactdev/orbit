<?php

declare(strict_types=1);

use App\Actions\Tasks\CreateTaskGroupAction;
use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Domain\GitHub\GitHubApi;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Orb220DeploymentApiFixture;
use Tests\Support\ProductionSmokeRoute;

beforeEach(function (): void {
    enable_operation_queue();
    app(TaskExtensionState::class)->enable();
    $this->fixture = Orb220DeploymentApiFixture::create();
    $this->worker = Node::query()->forceCreate(['id' => 209, 'name' => 'swamp-worker', 'public_ssh_host' => '192.0.2.209', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.209', 'user' => 'orbit']);
    $this->worker->accessibleNodes()->attach($this->fixture->caller->id);
    config(['swamp.run_token' => 'fixture.fake']);
    $this->run = '3fc3ad54-423a-4b4b-8187-99514098426b';
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-dependency-merge', 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $this->socket);
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
    GitHubTestSupport::storeApp();
    $this->github = Mockery::mock(GitHubApi::class);
    $this->github->shouldReceive('repositoryInstallation')->andReturn(123);
    $this->github->shouldReceive('repositoryReadToken')->andReturn('fixture-contents');
    $this->github->shouldReceive('repositoryChecksToken')->andReturn('fixture-checks');
    app()->instance(GitHubApi::class, $this->github);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/widgets/installation' => Http::response(['id' => 123]),
        'https://api.github.com/app/installations/123/access_tokens' => Http::response(['token' => 'fixture-actions', 'permissions' => ['actions' => 'read']]),
    ]);
});

it('records and replays one keyed merge intent without GitHub work', function (): void {
    $this->withHeader('Idempotency-Key', 'batch-merge')->postJson($this->url, $this->body)->assertAccepted()->assertJsonPath('data.kind', 'tasks.dependency_merge');
    $id = Operation::query()->where('kind', 'tasks.dependency_merge')->sole()->id;
    $this->postJson($this->url, $this->body)->assertAccepted()->assertJsonPath('data.id', $id);
    expect(Operation::findOrFail($id)->input['token'])->toBe(['$secret' => 'github.dependency_merge', 'id' => $this->project->id]);
    Http::assertNothingSent();
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

function dependencyMergeDirect(object $test): int
{
    $id = $test->withHeader('Idempotency-Key', 'direct-merge')->postJson($test->url, $test->body)->assertAccepted()->json('data.id');
    $test->withServerVariables(['REMOTE_ADDR' => $test->worker->wireguard_ip]);

    return $id;
}

it('hydrates only the owned Project credential through the shared private reader', function (): void {
    $id = dependencyMergeDirect($this);
    $this->github->shouldReceive('repositorySquashToken')->once()->andReturn('fixture-write-secret');
    $path = '/api/v1/direct-operations/'.$id;
    $this->getJson($path.'?run_id='.$this->run)->assertOk()->assertJsonMissingPath('data.input.token')
        ->assertJsonPath('data.node_id', 209);
    $this->getJson($path.'/private-material?run_id='.$this->run)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.token', ['write_token' => 'fixture-write-secret', 'read_token' => 'fixture-contents', 'checks_token' => 'fixture-checks', 'actions_token' => 'fixture-actions']);
    $operation = Operation::findOrFail($id);
    expect($operation->state['swamp_run_id'])->toBe($this->run);
    expect(json_encode($operation->toArray()))->not->toContain('fixture-write-secret');
    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
});

it('refuses changed local ownership before releasing private material', function (string $change): void {
    $id = dependencyMergeDirect($this);
    $this->github->shouldNotReceive('repositorySquashToken');
    match ($change) {
        'disabled' => config(['dependency_merge.projects' => []]),
        'policy' => config(['dependency_merge.projects.'.$this->project->id.'.smoke_workflow' => '.github/workflows/other.yml']),
        'publication' => $this->publish->update(['status' => 'failed']),
        'repository' => $this->project->update(['repository_url' => 'https://github.com/acme/foreign.git']),
    };
    $this->getJson('/api/v1/direct-operations/'.$id.'/private-material?run_id='.$this->run)->assertConflict();
    Http::assertNothingSent();
})->with(['disabled', 'policy', 'publication', 'repository']);

it('refuses a foreign secret owner Node or run before credential resolution', function (string $change): void {
    $id = dependencyMergeDirect($this);
    $this->github->shouldNotReceive('repositorySquashToken');
    $operation = Operation::findOrFail($id);
    if ($change === 'owner') {
        $operation->update(['input' => [...$operation->input, 'token' => ['$secret' => 'github.dependency_merge', 'id' => $this->project->id + 1]]]);
    } elseif ($change === 'node') {
        $operation->update(['node_id' => $this->fixture->owner->id]);
    }
    $response = $this->getJson('/api/v1/direct-operations/'.$id.'/private-material?run_id='.($change === 'run' ? '04ca3c51-31cb-4a99-8408-78f49551fd03' : $this->run));
    expect($response->status())->toBe(match ($change) {
        'owner' => 422, 'node' => 403, 'run' => 409
    });
})->with(['owner', 'node', 'run']);

it('records the native public result without repeating native policy and replays it', function (): void {
    $id = dependencyMergeDirect($this);
    $path = '/api/v1/direct-operations/'.$id.'/result';
    $proof = ['merged' => true, 'head_sha' => str_repeat('c', 40), 'merge_sha' => str_repeat('b', 40)];
    $body = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-31T00:00:00.000Z', 'finished_at' => '2026-10-31T00:00:01.000Z', 'result' => $proof, 'error_code' => null];
    $this->postJson($path, $body)->assertOk()->assertJsonPath('data.result', $proof);
    $this->postJson($path, $body)->assertOk()->assertJsonPath('data.status', 'succeeded');
    $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])->postJson($this->url, $this->body)->assertAccepted()->assertJsonPath('data.id', $id);
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    $this->github->shouldNotReceive('repositorySquashToken');
    $this->getJson('/api/v1/direct-operations/'.$id.'/private-material?run_id='.$this->run)->assertConflict();
    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
});

it('keeps one committed intent when the native start is refused', function (): void {
    $this->socket->reply = fn (array $message): array => [['type' => 'error', 'id' => $message['id']]];
    $this->withHeader('Idempotency-Key', 'start-refused')->postJson($this->url, $this->body)
        ->assertServiceUnavailable()->assertJsonPath('error.code', 'swamp.start_refused');
    $operation = Operation::query()->where('kind', 'tasks.dependency_merge')->sole();
    expect($operation->status->value)->toBe('queued')->and($operation->state['swamp_run_id'] ?? null)->toBeNull();
    $this->socket->reply = fn (array $message): array => [['type' => 'workflow.run.search', 'id' => $message['id'], 'payload' => ['total' => 1, 'data' => ['results' => [[
        'runId' => $this->run, 'workflowName' => 'operation-dependency-merge', 'status' => 'running',
        'inputs' => ['operation_id' => $operation->id, 'kind' => 'tasks.dependency_merge', 'node_id' => 209],
        'tags' => ['orbit_start_key' => 'operation:'.$operation->id],
    ]]]]]];
    $this->postJson($this->url, $this->body)->assertAccepted()->assertJsonPath('data.id', $operation->id);
    expect(Operation::query()->where('kind', 'tasks.dependency_merge')->count())->toBe(1)
        ->and(array_column($this->socket->sent, 'type'))->toBe(['workflow.run', 'workflow.run.search']);
    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
});

it('does not release private material before a native root is bound', function (): void {
    $id = dependencyMergeDirect($this);
    $operation = Operation::findOrFail($id);
    $operation->update(['state' => array_diff_key($operation->state, ['swamp_run_id' => true])]);
    $this->getJson('/api/v1/direct-operations/'.$id.'/private-material?run_id='.$this->run)->assertConflict();
    Http::assertNothingSent();
});
