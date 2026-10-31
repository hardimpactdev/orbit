<?php

declare(strict_types=1);

use App\Actions\TaskVms\StartTaskVmPackageCacheOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\PackageCacheEntry;
use App\Models\PackageCacheSnapshot;
use App\Models\Project;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\TaskVmSettingsBuilder;

function cacheFillArtifact(string $key): array
{
    return ['manager' => 'npm', 'package' => 'package-'.$key, 'version' => '1.0.0', 'url' => 'https://registry.npmjs.org/test/-/test-1.0.0.tgz', 'integrity' => null, 'key' => $key];
}

function cacheFillAdmission(array $artifacts): Operation
{
    $host = Node::query()->create(['name' => 'cache-host', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.10', 'public_ssh_port' => 22, 'user' => 'orbit', 'wireguard_ip' => '10.44.0.7']);
    $gateway = Node::query()->create(['public_ssh_host' => '192.0.2.11', 'user' => 'orbit', 'name' => 'gateway', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.1']);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $services = Node::query()->create(['public_ssh_host' => '192.0.2.12', 'user' => 'orbit', 'name' => 'services', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.3']);
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    test()->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($host->id)]]);
    $project = Project::query()->create(['name' => 'Cache', 'slug' => 'cache', 'repository_url' => 'https://github.com/example/cache.git']);
    PackageCacheSnapshot::query()->create(['project_id' => $project->id, 'blobs' => [], 'artifacts' => $artifacts]);

    return app(StartTaskVmPackageCacheOperationAction::class)->execute($host->id);
}

beforeEach(function (): void {
    $this->run = '80fc2fea-52e3-46ed-b789-d38219c231c6';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-task-vm-cache-fill', 'runId' => $this->run,
    ]]];
    app()->instance(WebSocketClient::class, $socket);
    app()->instance(AgentCommandRunner::class, Mockery::mock(AgentCommandRunner::class));
    config(['swamp.run_token' => 'fixture.token']);
    $this->outcome = ['run_id' => $this->run, 'status' => 'running', 'started_at' => '2026-10-31T00:00:00.000Z'];
});

function cacheBatch(array $hashes = [], array $failures = []): array
{
    return ['replace_inventory' => true, 'archive_ids' => array_keys($hashes), 'archive_digests' => array_values($hashes),
        'failed_ids' => array_keys($failures), 'failure_reasons' => array_values($failures)];
}

it('admits one exact-host native root with safe input and no executor fallback', function (): void {
    $op = cacheFillAdmission([cacheFillArtifact('archive')]);
    expect(app(StartTaskVmPackageCacheOperationAction::class)->execute($op->node_id)->id)->toBe($op->id);
    expect(app(OperationKinds::class)->directOnly($op->kind))->toBeTrue()->and(DirectOperation::owns($op))->toBeTrue()
        ->and(app(OperationKinds::class)->definition('task_vm.fill_package_cache_batch'))->toBeNull()
        ->and($op->input)->not->toHaveKeys(['batches', 'deadline_at'])->and($op->lock_key)->toBeNull();
    $this->getJson('/api/v1/direct-operations/'.$op->id.'?run_id='.$this->run)->assertOk()
        ->assertJsonPath('data.input.artifacts.0.key', 'archive')->assertJsonMissingPath('data.input.github_token')
        ->assertJsonMissingPath('data.input.snapshots');
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $op->kind, 'worker' => 'obsolete'])->assertStatus(422);
});

it('refuses oversized artifact keys before recording intent', function (): void {
    Queue::fake();
    expect(fn () => cacheFillAdmission([cacheFillArtifact(str_repeat('a', 1025))]))->toThrow(TaskVmException::class, 'cache key exceeds');
    $this->assertDatabaseCount('operations', 0);
    Queue::assertNothingPushed();
});

it('projects partial verified inventory and replays it without retaining executor receipts', function (): void {
    $op = cacheFillAdmission([cacheFillArtifact('verified'), cacheFillArtifact('refused')]);
    $path = '/api/v1/direct-operations/'.$op->id.'/result';
    $this->postJson($path, $this->outcome)->assertOk();
    $hash = 'sha256-'.str_repeat('a', 64);
    $batch = cacheBatch(['verified' => $hash], ['refused' => 'ChecksumMismatch']);
    $this->postJson($path, [...$this->outcome, 'result' => $batch])->assertOk();
    $this->postJson($path, [...$this->outcome, 'result' => $batch])->assertOk();
    $this->assertDatabaseHas('package_cache_entries', ['host_node_id' => $op->node_id, 'key' => 'verified', 'integrity' => $hash]);
    $this->assertDatabaseCount('package_cache_entries', 1);
    expect(PackageCacheSnapshot::query()->sole()->fill_pending)->toBeTrue();
    $final = [...$this->outcome, 'status' => 'failed', 'finished_at' => '2026-10-31T00:00:01.000Z',
        'error_code' => 'task_vm.package_cache_failed', 'result' => ['error_code' => 'task_vm.package_cache_failed']];
    $this->postJson($path, $final)->assertOk()->assertJsonPath('data.status', 'failed');
    $this->postJson($path, $final)->assertOk();
    $this->assertDatabaseCount('package_cache_entries', 1);
    expect($op->fresh()->state)->not->toHaveKeys(['children', 'executor_runs']);
});

it('updates only the admitted snapshot and completes fully verified inventory', function (bool $stale): void {
    $op = cacheFillAdmission([cacheFillArtifact('archive')]);
    if ($stale) {
        PackageCacheSnapshot::query()->sole()->update(['blobs' => ['composer.lock' => 'new']]);
    }
    $path = '/api/v1/direct-operations/'.$op->id.'/result';
    $this->postJson($path, [...$this->outcome, 'result' => cacheBatch(['archive' => 'sha256-'.str_repeat('b', 64)])])->assertOk();
    $this->postJson($path, [...$this->outcome, 'status' => 'succeeded', 'finished_at' => '2026-10-31T00:00:01.000Z', 'result' => ['ready' => true]])->assertOk();
    expect(PackageCacheEntry::query()->count())->toBe($stale ? 0 : 1);
    if (! $stale) {
        expect(PackageCacheSnapshot::query()->sole()->fill_pending)->toBeFalse();
    }
})->with([false, true]);

it('rejects foreign keys, bad hashes and unsafe results before inventory mutation', function (string $variant): void {
    $op = cacheFillAdmission([cacheFillArtifact('archive')]);
    $result = match ($variant) {
        'foreign' => cacheBatch(['foreign' => 'sha256-'.str_repeat('a', 64)]),
        'hash' => cacheBatch(['archive' => 'incorrect']),
        'overlap' => cacheBatch(['archive' => 'sha256-'.str_repeat('a', 64)], ['archive' => 'Failed']),
        'unsafe' => cacheBatch([], ['archive' => "unsafe\nsecret"]),
        'long' => cacheBatch([], ['archive' => str_repeat('a', 161)]),
    };
    $this->postJson('/api/v1/direct-operations/'.$op->id.'/result', [...$this->outcome, 'result' => $result])->assertUnprocessable();
    $this->assertDatabaseCount('package_cache_entries', 0);
})->with(['foreign', 'hash', 'overlap', 'unsafe', 'long']);

it('refuses an explicit polled option before admission', function (): void {
    config(['swamp.direct_writers' => ['task_vm.fill_package_cache' => 'polled']]);
    expect(fn () => cacheFillAdmission([]))->toThrow(ResourceOperationException::class, 'This kind requires its direct Swamp writer.');
    $this->assertDatabaseCount('operations', 0);
});

it('keeps verified inventory when cancellation happens before a fill result', function (): void {
    $op = cacheFillAdmission([cacheFillArtifact('archive')]);
    $snapshot = PackageCacheSnapshot::query()->sole();
    PackageCacheEntry::query()->create(['project_id' => $snapshot->project_id, 'host_node_id' => $op->node_id,
        'manager' => 'npm', 'package' => 'package-archive', 'version' => '1.0.0', 'key' => 'archive', 'integrity' => 'sha256-'.str_repeat('a', 64)]);
    $path = '/api/v1/direct-operations/'.$op->id.'/result';
    $this->postJson($path, $this->outcome)->assertOk();
    $this->postJson($path, [...$this->outcome, 'status' => 'cancelled', 'finished_at' => '2026-10-31T00:00:01.000Z', 'result' => []])->assertOk();
    $this->assertDatabaseCount('package_cache_entries', 1);
});

it('hydrates only the owning root through the shared private callback', function (): void {
    $op = cacheFillAdmission([cacheFillArtifact('archive')]);
    $path = '/api/v1/direct-operations/'.$op->id.'/private-material?run_id='.$this->run;
    $this->getJson($path)->assertForbidden();
    $host = Node::query()->findOrFail($op->node_id);
    $host->accessibleNodes()->attach(Node::query()->where('name', 'gateway')->sole());
    config(['operation_executor.worker_node_ids' => [$host->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $host->wireguard_ip]);
    $this->getJson($path)->assertOk()->assertJsonPath('data.github_token', null)->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson('/api/v1/direct-operations/'.$op->id.'?run_id='.$this->run)->assertOk()->assertJsonMissingPath('data.input.github_token');
    $this->getJson(str_replace($this->run, '413c7594-13e7-40bf-b952-90df5a0eb737', $path))->assertConflict();
});

it('does not count another hosts stale version as a fully replicated archive', function (): void {
    $op = cacheFillAdmission([cacheFillArtifact('archive')]);
    $other = Node::query()->create(['name' => 'other-cache-host', 'status' => LifecycleStatus::Active, 'public_ssh_host' => '192.0.2.21', 'user' => 'orbit']);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($op->node_id), TaskVmSettingsBuilder::host($other->id)]]);
    $snapshot = PackageCacheSnapshot::query()->sole();
    $hash = 'sha256-'.str_repeat('a', 64);
    PackageCacheEntry::query()->create(['project_id' => $snapshot->project_id, 'host_node_id' => $other->id,
        'manager' => 'npm', 'package' => 'package-archive', 'version' => '0.9.0', 'key' => 'archive', 'integrity' => $hash]);
    $this->postJson('/api/v1/direct-operations/'.$op->id.'/result', [...$this->outcome, 'result' => cacheBatch(['archive' => $hash])])->assertOk();
    expect($snapshot->fresh()->fill_pending)->toBeTrue();
});
