<?php

declare(strict_types=1);

use App\Actions\TaskVms\StartTaskVmPackageCacheOperationAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmPackageCacheProjection;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use App\Models\PackageCacheEntry;
use App\Models\PackageCacheSnapshot;
use App\Models\Project;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Schedules\NativeScheduleReplies;
use Tests\Support\TaskVmSettingsBuilder;

function cacheFillArtifact(string $key): array
{
    return ['manager' => 'npm', 'package' => 'package-'.$key, 'version' => '1.0.0', 'url' => 'https://registry.npmjs.org/test/-/test-1.0.0.tgz', 'integrity' => null, 'key' => $key];
}

function cacheFillAdmission(array $artifacts): Operation
{
    $host = Node::query()->create(['name' => 'cache-host', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.10', 'public_ssh_port' => 22, 'user' => 'orbit', 'wireguard_ip' => '10.44.0.7',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-host-pin']);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($host->id)]]);
    $project = Project::query()->create(['name' => 'Cache', 'slug' => 'cache', 'repository_url' => 'https://github.com/example/cache.git']);
    PackageCacheSnapshot::query()->create(['project_id' => $project->id, 'blobs' => [], 'artifacts' => $artifacts, 'fill_pending' => true]);

    return app(StartTaskVmPackageCacheOperationAction::class)->execute($host->id);
}

/** One batch reply as the pinned agent prints it. */
function cacheFillReply(array $hashes = [], array $failures = []): string
{
    return json_encode(['ok' => true, 'data' => ['hashes' => (object) $hashes, 'failures' => (object) $failures]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
}

beforeEach(function (): void {
    $this->run = '80fc2fea-52e3-46ed-b789-d38219c231c6';
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $message): array => NativeScheduleReplies::for($message) ?? [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-task-vm-cache-fill', 'runId' => $this->run,
    ]]];
    app()->instance(WebSocketClient::class, $this->socket);
    $this->manifest = tempnam(sys_get_temp_dir(), 'cache-fill-agent-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    config(['swamp.run_token' => 'fixture.token']);
});

afterEach(function (): void {
    unlink($this->manifest);
});

function cacheFillTerminal(Operation $op, string $status, array $replies): Operation
{
    $result = ['steps' => [], 'summary' => ['status' => $status, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0, 'failed_steps' => []]];
    foreach ($replies as $batch => $stdout) {
        $result['fill_'.$batch] = $stdout;
    }

    return app(TaskVmPackageCacheProjection::class)->apply($op->fresh(), ['run_id' => test()->run, 'status' => $status,
        'started_at' => '2026-11-02T00:00:00.000Z', 'finished_at' => '2026-11-02T00:00:01.000Z', 'result' => $result,
        'error_code' => $status === 'failed' ? 'operation.native_failed' : null,
        'failure' => $status === 'failed' ? ['failed_step' => 'Check every archive', 'detail' => 'task_vm.package_cache_failed'] : null]);
}

it('accepts the GitHub installation token forms the vault member may carry', function (): void {
    // A current installation token: ghs_, then about 390 characters including '-' and '.'.
    $current = 'ghs_'.str_repeat('Ab3-x.Y_', 48);
    expect(strlen($current))->toBeGreaterThan(255)
        ->and(TaskVmPackageCacheProjection::tokenFitsVault($current))->toBeTrue()
        ->and(TaskVmPackageCacheProjection::tokenFitsVault('ghs_'.str_repeat('a', 36)))->toBeTrue()
        ->and(TaskVmPackageCacheProjection::tokenFitsVault('ghs_a"b'))->toBeFalse()
        ->and(TaskVmPackageCacheProjection::tokenFitsVault("ghs_a\nb"))->toBeFalse()
        ->and(TaskVmPackageCacheProjection::tokenFitsVault(''))->toBeFalse();
});

it('starts one native run on role swamp with the public requests as inputs and only the token member in the vault', function (): void {
    $artifacts = array_map(cacheFillArtifact(...), array_map(static fn (int $i): string => 'archive-'.$i, range(0, 4096)));
    $op = cacheFillAdmission($artifacts);
    expect(app(StartTaskVmPackageCacheOperationAction::class)->execute($op->node_id)->id)->toBe($op->id)
        ->and(app(OperationKinds::class)->directOnly($op->kind))->toBeTrue()->and(DirectOperation::owns($op))->toBeTrue()
        ->and(app(DirectOperation::class)->definition($op->kind))->not->toHaveKey('private_material')
        ->and($op->input)->not->toHaveKey('github_token');
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'workflow.run'])
        ->and($this->socket->sent[0]['payload'])->toMatchArray(['key' => 'task-vm-cache-fill-github-'.$op->node_id, 'value' => '"github_token":null']);
    $inputs = $this->socket->sent[1]['payload']['inputs'];
    expect($inputs['intent']['batches'])->toBe([0, 1])
        ->and($inputs['intent']['ssh_execution']['target'])->toMatchArray(['host' => '10.44.0.7', 'host_keys' => ['ssh-ed25519 fixture-host-pin']])
        ->and($inputs['execution_secrets'])->toBe(['github_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-cache-fill-github-'.$op->node_id]]);
    // The step's stdin: the public request, the vault member, then request_end; the agent reads it as one JSON line.
    $member = $this->socket->sent[0]['payload']['value'];
    $requests = array_map(static fn (string $request): array => json_decode($request.$member.$inputs['intent']['request_end'], true, flags: JSON_THROW_ON_ERROR),
        $inputs['intent']['requests']);
    expect($inputs['intent']['request_end'])->toBe("}}\n")
        ->and($requests[0]['agent_sha256'])->toBe(str_repeat('a', 64))
        ->and($requests[0]['input'])->toMatchArray(['operation' => 'fill', 'github_token' => null])
        ->and(count($requests[0]['input']['artifacts']))->toBe(4096)
        ->and(array_column($requests[1]['input']['artifacts'], 'key'))->toBe(['archive-4096']);
    expect(OperationRunBudgets::seconds($op->fresh()))->toBe(2100);
});

it('keeps every vault value small, because serve redacts each value it reads with a pattern built from it', function (): void {
    // Operation 8235: a whole agent request as a vault value failed the step before SSH with "Invalid regular expression".
    cacheFillAdmission(array_map(cacheFillArtifact(...), array_map(static fn (int $i): string => 'archive-'.$i, range(0, 2404))));
    $values = array_column(array_column(array_filter($this->socket->sent, static fn (array $frame): bool => $frame['type'] === 'vault.put'), 'payload'), 'value');
    expect($values)->not->toBeEmpty()->and(max(array_map(strlen(...), $values)))->toBeLessThanOrEqual(300);
});

it('refuses oversized artifact keys and inventories past the batch bound before recording intent', function (array $artifacts, string $message): void {
    Queue::fake();
    expect(fn () => cacheFillAdmission($artifacts))->toThrow(TaskVmException::class, $message);
    $this->assertDatabaseCount('operations', 0);
    Queue::assertNothingPushed();
})->with([
    'key' => [[cacheFillArtifact(str_repeat('a', 1025))], 'cache key exceeds'],
    'inventory' => [array_map(static fn (int $i): array => cacheFillArtifact('a-'.$i), range(0, 2 * 4096)), 'batch bound'],
]);

it('records verified archives from the batch replies when a refused archive fails the run', function (): void {
    $op = cacheFillAdmission([cacheFillArtifact('verified'), cacheFillArtifact('refused')]);
    $hash = 'sha256-'.str_repeat('a', 64);
    $reply = cacheFillReply(['verified' => $hash], ['refused' => 'Archive checksum mismatch']);
    cacheFillTerminal($op, 'failed', [$reply]);
    expect($op->fresh()->status->value)->toBe('failed')->and($op->fresh()->error_code)->toBe('task_vm.package_cache_failed');
    $this->assertDatabaseHas('package_cache_entries', ['host_node_id' => $op->node_id, 'key' => 'verified', 'integrity' => $hash]);
    $this->assertDatabaseCount('package_cache_entries', 1);
    expect(PackageCacheSnapshot::query()->sole()->fill_pending)->toBeTrue();
    cacheFillTerminal($op, 'failed', [cacheFillReply()]);
    $this->assertDatabaseCount('package_cache_entries', 1);
});

it('updates only the admitted snapshot and completes fully verified inventory', function (bool $stale): void {
    $op = cacheFillAdmission([cacheFillArtifact('archive')]);
    if ($stale) {
        PackageCacheSnapshot::query()->sole()->update(['blobs' => ['composer.lock' => 'new']]);
    }
    cacheFillTerminal($op, 'succeeded', [cacheFillReply(['archive' => 'sha256-'.str_repeat('b', 64)])]);
    expect($op->fresh()->status->value)->toBe('succeeded')->and($op->fresh()->result)->toBe(['ready' => true])
        ->and(PackageCacheEntry::query()->count())->toBe($stale ? 0 : 1);
    if (! $stale) {
        expect(PackageCacheSnapshot::query()->sole()->fill_pending)->toBeFalse();
    }
})->with([false, true]);

it('refuses a reply that names an archive outside its batch without changing inventory', function (): void {
    $op = cacheFillAdmission([cacheFillArtifact('archive')]);
    expect(fn () => cacheFillTerminal($op, 'succeeded', [cacheFillReply(['other' => 'sha256-'.str_repeat('a', 64)])]))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.result_invalid'));
    $this->assertDatabaseCount('package_cache_entries', 0);
    expect($op->fresh()->status->value)->not->toBe('succeeded');
});

it('keeps verified inventory when the run is cancelled before a fill reply', function (): void {
    $op = cacheFillAdmission([cacheFillArtifact('archive')]);
    $snapshot = PackageCacheSnapshot::query()->sole();
    PackageCacheEntry::query()->create(['project_id' => $snapshot->project_id, 'host_node_id' => $op->node_id,
        'manager' => 'npm', 'package' => 'package-archive', 'version' => '1.0.0', 'key' => 'archive', 'integrity' => 'sha256-'.str_repeat('a', 64)]);
    cacheFillTerminal($op, 'cancelled', []);
    expect($op->fresh()->status->value)->toBe('cancelled');
    $this->assertDatabaseCount('package_cache_entries', 1);
});

it('does not count another hosts stale version as a fully replicated archive', function (): void {
    $op = cacheFillAdmission([cacheFillArtifact('archive')]);
    $other = Node::query()->create(['name' => 'other-cache-host', 'status' => LifecycleStatus::Active, 'public_ssh_host' => '192.0.2.21', 'user' => 'orbit']);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($op->node_id), TaskVmSettingsBuilder::host($other->id)]]);
    $snapshot = PackageCacheSnapshot::query()->sole();
    $hash = 'sha256-'.str_repeat('a', 64);
    PackageCacheEntry::query()->create(['project_id' => $snapshot->project_id, 'host_node_id' => $other->id,
        'manager' => 'npm', 'package' => 'package-archive', 'version' => '0.9.0', 'key' => 'archive', 'integrity' => $hash]);
    cacheFillTerminal($op, 'succeeded', [cacheFillReply(['archive' => $hash])]);
    expect($snapshot->fresh()->fill_pending)->toBeTrue();
});
