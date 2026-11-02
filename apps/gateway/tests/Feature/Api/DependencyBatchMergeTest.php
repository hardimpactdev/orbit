<?php

declare(strict_types=1);

use App\Actions\Tasks\CreateTaskGroupAction;
use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Domain\GitHub\GitHubAccessToken;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubTokenGrant;
use App\Domain\Tasks\DependencyMergeProjection;
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
    $this->socket->reply = fn (array $message): array => $message['type'] === 'vault.put' ? [['type' => 'vault.put', 'id' => $message['id'], 'payload' => ['data' => ['vaultName' => $message['payload']['vaultName'], 'secretKey' => $message['payload']['key'], 'vaultType' => 'local_encryption']]]] : [['type' => 'event', 'id' => $message['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-dependency-merge', 'runId' => $this->run]]];
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
    $this->grants = [];
    $this->github->shouldReceive('scopedRepositoryToken')->andReturnUsing(function ($credentials, GitHubTokenGrant $grant): GitHubAccessToken {
        $this->grants[] = $grant;

        return new GitHubAccessToken('synthetic-dependency-'.count($this->grants), now()->addHour()->toIso8601String());
    });
    app()->instance(GitHubApi::class, $this->github);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/widgets/installation' => Http::response(['id' => 123]),
        'https://api.github.com/app/installations/123/access_tokens' => Http::response(['token' => 'fixture-actions', 'permissions' => ['actions' => 'read']]),
    ]);
});

it('mints two exact repository grants once and records only vault descriptors', function (): void {
    $this->withHeader('Idempotency-Key', 'batch-merge')->postJson($this->url, $this->body)->assertAccepted()->assertJsonPath('data.kind', 'tasks.dependency_merge');
    $id = Operation::query()->where('kind', 'tasks.dependency_merge')->sole()->id;
    $this->postJson($this->url, $this->body)->assertAccepted()->assertJsonPath('data.id', $id);
    $operation = Operation::findOrFail($id);
    expect($this->grants)->toHaveCount(2)
        ->and($this->grants[0]->permissions)->toBe(['contents' => 'write', 'pull_requests' => 'write'])
        ->and($this->grants[1]->permissions)->toBe(['contents' => 'read', 'pull_requests' => 'read', 'checks' => 'read', 'actions' => 'read'])
        ->and($this->grants[0]->repository->owner)->toBe('acme')
        ->and($this->grants[0]->repository->name)->toBe('widgets')
        ->and($operation->input)->not->toHaveKey('token')
        ->and($operation->input['execution_secrets'])->toHaveKeys(['read_token', 'write_token'])
        ->and(json_encode($operation->toArray()))->not->toContain('synthetic-dependency-');
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

it('refuses a busy group before minting tokens', function (): void {
    Operation::create(['kind' => 'tasks.check', 'target_type' => 'task_group', 'target_id' => $this->group->id,
        'node_id' => 209, 'status' => 'running', 'input' => []]);
    $this->withHeader('Idempotency-Key', 'busy')->postJson($this->url, $this->body)->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    expect($this->grants)->toBe([]);
});

it('has no callback credential or result endpoint', function (): void {
    $id = dependencyMergeDirect($this);
    $this->getJson('/api/v1/direct-operations/'.$id.'/private-material?run_id='.$this->run)->assertUnprocessable();
    $this->postJson('/api/v1/direct-operations/'.$id.'/result', [])->assertForbidden();
});

it('projects the native exact head receipt and bounded policy failure', function (): void {
    $id = dependencyMergeDirect($this);
    $operation = Operation::findOrFail($id);
    $proof = ['merged' => true, 'head_sha' => $this->head, 'merge_sha' => str_repeat('b', 40)];
    $projection = app(DependencyMergeProjection::class);
    $projection->apply($operation, ['status' => 'succeeded', 'run_id' => $this->run, 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['merge_receipt' => json_encode($proof)]]);
    expect($operation->refresh()->result)->toBe($proof)->and($operation->status->value)->toBe('succeeded');
});

it('keeps one failed intent when the native start is refused', function (): void {
    $this->socket->reply = fn (array $message): array => $message['type'] === 'vault.put' ? [['type' => 'vault.put', 'id' => $message['id'], 'payload' => ['data' => ['vaultName' => $message['payload']['vaultName'], 'secretKey' => $message['payload']['key'], 'vaultType' => 'local_encryption']]]] : [['type' => 'error', 'id' => $message['id']]];
    $this->withHeader('Idempotency-Key', 'start-refused')->postJson($this->url, $this->body)
        ->assertServiceUnavailable()->assertJsonPath('error.code', 'swamp.start_refused');
    $operation = Operation::query()->where('kind', 'tasks.dependency_merge')->sole();
    expect($operation->status->value)->toBe('failed')->and($operation->error_code)->toBe('swamp.start_refused')
        ->and($operation->state['swamp_run_id'] ?? null)->toBeNull();
    $this->postJson($this->url, $this->body)->assertAccepted()->assertJsonPath('data.id', $operation->id)->assertJsonPath('data.status', 'failed');
    expect(Operation::query()->where('kind', 'tasks.dependency_merge')->count())->toBe(1)
        ->and(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'vault.put', 'workflow.run']);
    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
});
