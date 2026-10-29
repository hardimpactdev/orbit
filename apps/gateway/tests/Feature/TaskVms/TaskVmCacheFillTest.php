<?php

declare(strict_types=1);

use App\Actions\TaskVms\StartTaskVmPackageCacheOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmCacheFillBatches;
use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\TaskVms\TaskVmPackageCache;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\PackageCacheSnapshot;
use App\Models\Project;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
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

it('partitions a large inventory without losing artifacts and keeps worst-case child results inside kit C', function (): void {
    $artifacts = array_map(static fn (int $index): array => cacheFillArtifact(hash('sha256', (string) $index)), range(1, 1800));
    $batches = TaskVmCacheFillBatches::compile($artifacts, ['conflicting' => 'IntegrityConflict']);
    expect(count($batches))->toBeGreaterThan(1);
    expect(array_merge(...array_column($batches, 'artifacts')))->toBe($artifacts);
    expect(array_merge(...array_column($batches, 'conflicts')))->toBe(['conflicting' => 'IntegrityConflict']);
    foreach ($batches as $batch) {
        $keys = [...array_column($batch['artifacts'], 'key'), ...array_keys($batch['conflicts'])];
        $result = ['ready' => true, 'archive_ids' => [], 'archive_digests' => [], 'failed_ids' => $keys, 'failure_reasons' => array_fill(0, count($keys), str_repeat('\\', 160))];
        $safe = app(OperationExecutionContract::class)->result(new Operation(['kind' => 'task_vm.fill_package_cache_batch']), $result);
        expect($safe)->toBe($result);
        expect(strlen(json_encode($safe, JSON_THROW_ON_ERROR)))->toBeLessThan(256 * 1024);
    }
});

it('refuses a cache key that cannot fit a result before recording intent', function (): void {
    Queue::fake();
    expect(fn () => cacheFillAdmission([cacheFillArtifact(str_repeat('a', 1025))]))->toThrow(TaskVmException::class, 'cache key exceeds');
    $this->assertDatabaseCount('operations', 0);
    Queue::assertNothingPushed();
});

it('records one bounded host fill and admits only its immutable batch keys', function (): void {
    Queue::fake();
    $operation = cacheFillAdmission([cacheFillArtifact('archive')]);
    expect(app(StartTaskVmPackageCacheOperationAction::class)->execute($operation->node_id)->id)->toBe($operation->id);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'worker' => 'cache-parent'])->assertOk();
    $path = "/api/v1/operation-executor/{$operation->id}";
    $this->postJson($path.'/complete', ['worker' => 'cache-parent', 'status' => 'succeeded', 'result' => ['ready' => true, 'skipped' => []]])->assertConflict();
    $this->postJson($path.'/children', ['worker' => 'cache-parent', 'key' => 'fill-0'])->assertAccepted();
    $child = $operation->children()->sole();
    expect($child->kind)->toBe('task_vm.fill_package_cache_batch');
    expect($child->node_id)->toBe($operation->node_id);
    expect($child->input['artifacts'])->toBe($operation->input['batches'][0]['artifacts']);
    $this->postJson($path.'/children', ['worker' => 'cache-parent', 'key' => 'fill-0'])->assertOk()->assertJsonPath('data.id', $child->id);
    $this->postJson($path.'/children', ['worker' => 'cache-parent', 'key' => 'fill-1'])->assertUnprocessable();
    Queue::assertNothingPushed();
});

it('completes a Swamp batch from its exact proven step and projects the host inventory', function (): void {
    $parent = cacheFillAdmission([cacheFillArtifact('verified')]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $parent->kind, 'worker' => 'cache-parent'])->assertOk();
    $path = "/api/v1/operation-executor/{$parent->id}";
    $this->postJson($path.'/children', ['worker' => 'cache-parent', 'key' => 'fill-0'])->assertAccepted();
    $child = $parent->children()->sole();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $child->kind, 'operation_id' => $child->id, 'worker' => 'cache-child'])->assertOk();
    $hash = 'sha256-'.str_repeat('a', 64);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->andReturn(['hashes' => ['verified' => $hash], 'failures' => []]);
    app()->instance(AgentCommandRunner::class, $runner);
    $childPath = "/api/v1/operation-executor/{$child->id}";
    $result = ['ready' => true, 'archive_ids' => ['verified'], 'archive_digests' => [$hash], 'failed_ids' => [], 'failure_reasons' => []];
    $completion = ['worker' => 'cache-child', 'status' => 'succeeded', 'result' => $result];
    $this->postJson($childPath.'/run', ['worker' => 'cache-child', 'key' => 'fill-package-cache-batch', 'transport' => 'task_vm_package-cache', 'name' => 'task_vm.package-cache',
        'input' => Arr::only($child->input, ['host_node_id', 'artifacts', 'conflicts', 'max_bytes', 'github_token'])])->assertOk()->assertJsonPath('data', $result);
    $this->postJson($childPath.'/complete', $completion)->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $this->postJson($childPath.'/step', ['worker' => 'cache-child', 'key' => 'fill-package-cache-batch:finish', 'name' => 'fill-package-cache-batch', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson($childPath.'/complete', $completion)->assertOk()->assertJsonPath('data.result', $result);
    $this->postJson($childPath.'/complete', $completion)->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'cache-parent', 'status' => 'succeeded', 'result' => ['ready' => true, 'skipped' => []]])->assertOk();
    $this->assertDatabaseHas('package_cache_entries', ['host_node_id' => $parent->node_id, 'key' => 'verified', 'integrity' => $hash]);
    expect(PackageCacheSnapshot::query()->sole()->fill_pending)->toBeFalse();
});

it('projects partial archives only from proven child results and derives failed batch identities', function (): void {
    Log::spy();
    $parent = cacheFillAdmission([cacheFillArtifact('verified'), cacheFillArtifact('refused')]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $parent->kind, 'worker' => 'cache-parent'])->assertOk();
    $path = "/api/v1/operation-executor/{$parent->id}";
    $this->postJson($path.'/children', ['worker' => 'cache-parent', 'key' => 'fill-0'])->assertAccepted();
    $child = $parent->children()->sole();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $child->kind, 'operation_id' => $child->id, 'worker' => 'cache-child'])->assertOk();
    $hash = 'sha256-'.str_repeat('a', 64);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->andReturn(['hashes' => ['verified' => $hash], 'failures' => ['refused' => 'ChecksumMismatch']]);
    app()->instance(AgentCommandRunner::class, $runner);
    $childPath = "/api/v1/operation-executor/{$child->id}";
    $result = ['ready' => true, 'archive_ids' => ['verified'], 'archive_digests' => [$hash], 'failed_ids' => ['refused'], 'failure_reasons' => ['ChecksumMismatch']];
    $this->postJson($childPath.'/run', ['worker' => 'cache-child', 'key' => 'fill-package-cache-batch', 'transport' => 'task_vm_package-cache', 'name' => 'task_vm.package-cache',
        'input' => Arr::only($child->input, ['host_node_id', 'artifacts', 'conflicts', 'max_bytes', 'github_token'])])->assertOk()->assertJsonPath('data', $result);
    $this->postJson($childPath.'/step', ['worker' => 'cache-child', 'key' => 'finish', 'name' => 'fill-package-cache-batch', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson($childPath.'/complete', ['worker' => 'cache-child', 'status' => 'failed', 'error_code' => 'task_vm.package_cache_failed', 'result' => $result])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'cache-parent', 'status' => 'failed', 'error_code' => 'task_vm.package_cache_failed', 'result' => []])
        ->assertOk()->assertJsonPath('data.result.skipped.0.child_id', $child->id)->assertJsonPath('data.result.skipped.0.key', 'fill-0')
        ->assertJsonPath('data.result.skipped.0.kind', 'task_vm.fill_package_cache_batch');
    $this->assertDatabaseHas('package_cache_entries', ['host_node_id' => $parent->node_id, 'key' => 'verified', 'integrity' => $hash]);
    $this->assertDatabaseMissing('package_cache_entries', ['key' => 'refused']);
    expect(PackageCacheSnapshot::query()->sole()->fill_pending)->toBeTrue();
    Log::shouldHaveReceived('warning')->once()->with('Task VM package cache artifact failed.', ['host_node_id' => $parent->node_id, 'archive_id' => 'refused', 'reason' => 'ChecksumMismatch']);
});

it('refuses an unproven failed batch result before projecting archive inventory', function (): void {
    $parent = cacheFillAdmission([cacheFillArtifact('forged')]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $parent->kind, 'worker' => 'cache-parent'])->assertOk();
    $path = "/api/v1/operation-executor/{$parent->id}";
    $this->postJson($path.'/children', ['worker' => 'cache-parent', 'key' => 'fill-0'])->assertAccepted();
    $child = $parent->children()->sole();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $child->kind, 'operation_id' => $child->id, 'worker' => 'cache-child'])->assertOk();
    $this->postJson("/api/v1/operation-executor/{$child->id}/complete", ['worker' => 'cache-child', 'status' => 'failed', 'error_code' => 'task_vm.package_cache_failed',
        'result' => ['ready' => true, 'archive_ids' => ['forged'], 'archive_digests' => ['sha256-'.str_repeat('a', 64)], 'failed_ids' => [], 'failure_reasons' => []]])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'cache-parent', 'status' => 'failed', 'error_code' => 'task_vm.package_cache_failed', 'result' => []])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $this->assertDatabaseCount('package_cache_entries', 0);
});

it('returns bounded redacted cache transport failures without projecting archive inventory', function (): void {
    $parent = cacheFillAdmission([cacheFillArtifact('archive')]);
    PackageCacheSnapshot::query()->sole()->update(['fill_pending' => true]);
    $child = DB::transaction(fn () => app(OperationChildren::class)->admit($parent, ['key' => 'fill-0']))['operation'];
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $child->kind, 'worker' => 'cache-child'])->assertOk();
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->andThrow(new AgentCommandRefused('package-cache.host', 'task_vm.package_cache_failed', 'Fill refused.', [
        'operation' => 'fill', 'path' => TaskVmPackageCache::HostPath,
        'reason' => "old output\n".str_repeat("progress\n", 1000)."PASSWORD=cache-secret\nPermissionError: cache file\n",
    ]));
    app()->instance(AgentCommandRunner::class, $runner);

    $response = $this->postJson("/api/v1/operation-executor/{$child->id}/run", ['worker' => 'cache-child', 'key' => 'fill-package-cache-batch',
        'transport' => 'task_vm_package-cache', 'name' => 'task_vm.package-cache',
        'input' => Arr::only($child->input, ['host_node_id', 'artifacts', 'conflicts', 'max_bytes', 'github_token'])]);

    $response->assertStatus(502)->assertJsonPath('error.code', 'task_vm.package_cache_failed');
    expect($response->json('error.message'))->toBe('The named operation refused this request.');
    expect($response->getContent())->not->toContain('cache-secret', 'old output', 'PermissionError');
    expect(mb_strlen($response->json('error.message')))->toBeLessThanOrEqual(TaskVmException::MessageLimit);
    $this->assertDatabaseCount('package_cache_entries', 0);
    expect(PackageCacheSnapshot::query()->sole()->fill_pending)->toBeTrue();
});
