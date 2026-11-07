<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperationStartRefusal;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageRefreshIntent;
use App\Domain\TaskVms\TaskVmImageRefreshProjection;
use App\Domain\TaskVms\TaskVmPromotedImage;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Jobs\TaskVms\RefreshTaskVmImages;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

const ImageRefreshCommit = 'dddddddddddd0000000000000000000000000000';
const ImageRefreshImage = 'c1c2cf4051fb0123456789abcdef0123456789abcdef0123456789abcdef0123';
const ImageRefreshSnapshot = 'r20261106030000';

beforeEach(function (): void {
    // A release layout whose current release is ImageRefreshCommit.
    $this->base = sys_get_temp_dir().'/orbit-image-refresh-'.bin2hex(random_bytes(4));
    mkdir($this->base.'/releases/'.substr(ImageRefreshCommit, 0, 12).'/apps/gateway', 0700, true);
    file_put_contents($this->base.'/releases/'.substr(ImageRefreshCommit, 0, 12).'/REVISION', ImageRefreshCommit."\n");
    symlink('releases/'.substr(ImageRefreshCommit, 0, 12), $this->base.'/orbit');
    config(['orbit.gateway_checkout' => $this->base.'/orbit/apps/gateway']);
});

afterEach(function (): void {
    Process::run(['rm', '-rf', $this->base]);
});

/** A configured task VM host whose latest refresh recorded a snapshot of its base VM. */
function imageRefreshHost(): Node
{
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    $host = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.9',
        'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $cluster->id, '10.44.0.128/25',
        [new TaskVmHost($host->id, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 5, 4, '8GiB', '40GiB')],
        '/pi', str_repeat('a', 64), ['gpt-6']));
    TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => 'recipe-1', 'state' => TaskVmImageBuildState::Succeeded,
        'promoted_at' => now(), 'images' => ['base' => ['project' => 'orbit-tasks', 'type' => 'snapshot', 'vm' => 'beast-base', 'seed' => 'beast-base-seed',
            'snapshot' => ImageRefreshSnapshot]]]);

    return $host;
}

function imageRefreshRefusal(Closure $call): string
{
    try {
        $call();
    } catch (ResourceOperationException $error) {
        return $error->errorCode;
    }

    throw new RuntimeException('The call was not refused.');
}

it('refuses a host that cannot refresh', function (): void {
    $host = imageRefreshHost();
    $other = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'plain', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.8', 'wireguard_ip' => '10.44.0.8', 'user' => 'orbit']));
    $intent = app(TaskVmImageRefreshIntent::class);

    expect(imageRefreshRefusal(fn () => $intent->start($other, 'test')))->toBe('task_vm.host_unavailable');

    expect(Operation::query()->count())->toBe(0);
});

it('refuses a refresh when the Gateway release has no commit', function (): void {
    $host = imageRefreshHost();
    unlink($this->base.'/orbit');

    expect(imageRefreshRefusal(fn () => app(TaskVmImageRefreshIntent::class)->start($host, 'test')))->toBe('task_vm.converge_unavailable');
    expect(Operation::query()->count())->toBe(0);
});

it('returns the open refresh of a host for a second admission, and none once it ended', function (): void {
    $host = imageRefreshHost();
    $operation = Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_vm_host', 'target_id' => $host->id, 'node_id' => $host->id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'test']);

    expect(TaskVmImageRefreshIntent::open($host)?->id)->toBe($operation->id);
    expect(app(TaskVmImageRefreshIntent::class)->start($host, 'test')->id)->toBe($operation->id);
    expect(Operation::query()->where('kind', 'task_vm.image_refresh')->count())->toBe(1);

    $operation->update(['status' => OperationStatus::Succeeded]);
    expect(TaskVmImageRefreshIntent::open($host))->toBeNull();
});

it('queues the newest release commit behind the open refresh, and admits it when that refresh ends', function (): void {
    Queue::fake();
    $host = imageRefreshHost();
    $operation = Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_vm_host', 'target_id' => $host->id, 'node_id' => $host->id,
        'status' => OperationStatus::Running, 'triggered_by' => 'test', 'state' => ['swamp_run_id' => 'fixture-refresh', 'native_progress' => ['run' => 'fixture-refresh']],
        'input' => ['commit' => ImageRefreshCommit, 'recipe_identity' => 'recipe-9', 'task_vm_id' => 0, 'intent' => ['host' => ['node_id' => $host->id,
            'project' => 'orbit-tasks', 'current' => null, 'snapshot' => 'r20261107131500', 'base' => ['vm' => 'beast-base', 'seed' => 'beast-base-seed']], 'converge' => []],
            'execution_secrets' => []]]);
    $intent = app(TaskVmImageRefreshIntent::class);
    $same = ImageRefreshCommit;
    $older = str_repeat('1', 40);
    $newest = str_repeat('2', 40);

    // The open refresh already runs for this commit: nothing waits. A newer commit waits, and the newest of two wins; the run's own state stays.
    expect($intent->start($host, 'test', commit: $same)->id)->toBe($operation->id);
    expect($operation->refresh()->state)->not->toHaveKey(TaskVmImageRefreshIntent::Next);
    // A plain admission, as the daily trigger makes it, queues nothing.
    $intent->start($host, 'test');
    expect($operation->refresh()->state)->not->toHaveKey(TaskVmImageRefreshIntent::Next);
    $intent->start($host, 'test', commit: $older);
    $intent->start($host, 'test', commit: $newest);
    expect($operation->refresh()->state)->toMatchArray([TaskVmImageRefreshIntent::Next => $newest, 'swamp_run_id' => 'fixture-refresh'])
        ->and(Operation::query()->where('kind', 'task_vm.image_refresh')->count())->toBe(1);

    app(TaskVmImageRefreshProjection::class)->apply($operation, ['status' => 'failed', 'result' => [], 'run_id' => 'fixture-refresh',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => 'task_vm.image_refresh_failed']);

    Queue::assertPushed(RefreshTaskVmImages::class, static fn (RefreshTaskVmImages $job): bool => $job->hostId === $host->id && $job->commit === $newest);
});

it('fails the proof slot of a refresh that Swamp refused to start, so the retry finds the capacity it left', function (): void {
    $host = imageRefreshHost();
    $slot = TaskVm::query()->create(['host_node_id' => $host->id, 'slot_number' => 1, 'provider' => TaskVmProviderKind::Incus, 'size' => TaskVmSize::Large,
        'name' => 'beast-tvm-1', 'state' => TaskVmState::Provisioning, 'wireguard_ip' => '10.44.0.201']);
    $operation = Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_vm_host', 'target_id' => $host->id, 'node_id' => $host->id,
        'status' => OperationStatus::Failed, 'triggered_by' => 'test', 'error_code' => 'swamp.vault_sync_unavailable', 'input' => ['task_vm_id' => $slot->id]]);

    expect(app(TaskVmImageRefreshProjection::class))->toBeInstanceOf(DirectOperationStartRefusal::class);
    app(TaskVmImageRefreshProjection::class)->refused($operation);

    expect($slot->refresh()->state)->toBe(TaskVmState::Failed)->and($slot->error_code)->toBe('swamp.vault_sync_unavailable');
});

it('promotes the proven snapshot of the base VM as the host\'s latest build and releases the slot that proved it; nothing for a failed run', function (): void {
    $host = imageRefreshHost();
    $slot = TaskVm::query()->create(['host_node_id' => $host->id, 'slot_number' => 1, 'provider' => TaskVmProviderKind::Incus, 'size' => TaskVmSize::Large,
        'name' => 'beast-tvm-1', 'state' => TaskVmState::Provisioning, 'wireguard_ip' => '10.44.0.201']);
    $operation = Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_vm_host', 'target_id' => $host->id, 'node_id' => $host->id,
        'status' => OperationStatus::Running, 'triggered_by' => 'test', 'state' => ['swamp_run_id' => 'fixture-refresh'], 'input' => ['commit' => ImageRefreshCommit, 'recipe_identity' => 'recipe-9', 'task_vm_id' => $slot->id,
            'intent' => ['host' => ['node_id' => $host->id, 'project' => 'orbit-tasks', 'current' => null, 'snapshot' => 'r20261107131500',
                'base' => ['vm' => 'beast-base', 'seed' => 'beast-base-seed']], 'converge' => []],
            'execution_secrets' => []]]);
    $projection = app(TaskVmImageRefreshProjection::class);

    $failed = $operation->replicate();
    $failed->save();
    $projection->apply($failed, ['status' => 'failed', 'result' => [], 'run_id' => 'fixture-refresh', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => 'task_vm.image_refresh_failed']);
    expect(app(TaskVmPromotedImage::class)->snapshot($host, app(TaskVmSettings::class)->host($host->id))['snapshot'])->toBe(ImageRefreshSnapshot)
        // A refresh that failed never reached the cleanup.
        ->and($failed->refresh()->result)->toMatchArray(['error_code' => 'task_vm.image_refresh_failed', 'host_clean' => 'skipped']);
    // A refresh that failed before its proof released leaves the slot failed, for the sweep to release.
    expect($slot->refresh()->state)->toBe(TaskVmState::Failed);
    $slot->update(['state' => TaskVmState::Provisioning]);

    $projection->apply($operation, ['status' => 'succeeded', 'result' => ['steps' => ['Prove the candidate' => ['status' => 'succeeded'],
        'Remove host leftovers' => ['status' => 'failed']]], 'run_id' => 'fixture-refresh', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => null]);
    expect(app(TaskVmPromotedImage::class)->snapshot($host, app(TaskVmSettings::class)->host($host->id)))
        ->toBe(['vm' => 'beast-base', 'seed' => 'beast-base-seed', 'snapshot' => 'r20261107131500', 'recipe_identity' => 'recipe-9'])
        // A failed leftover cleanup fails no refresh, and the result says so.
        ->and($operation->refresh()->result)->toMatchArray(['refreshed' => true, 'snapshot' => 'r20261107131500', 'previous_snapshot' => null, 'host_clean' => 'failed']);
    expect($slot->refresh()->state)->toBe(TaskVmState::Destroyed);
});

it('uses only a refresh snapshot as the base: a promoted image of the retired build is no base', function (): void {
    $host = imageRefreshHost();
    $location = app(TaskVmSettings::class)->host($host->id);
    $images = app(TaskVmPromotedImage::class);
    TaskVmImageBuild::query()->where('host_node_id', $host->id)->delete();

    // A promoted virtual-machine build is not a usable base.
    TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => 'recipe-1', 'state' => TaskVmImageBuildState::Succeeded, 'promoted_at' => now(),
        'images' => ['base' => ['project' => 'orbit-tasks', 'type' => 'virtual-machine', 'fingerprint' => ImageRefreshImage]]]);
    expect($images->recorded($host, $location))->toBeNull()
        ->and(imageRefreshRefusal(fn () => $images->forHost($host, $location)))->toBe('task_vm.image_unavailable')
        ->and(imageRefreshRefusal(fn () => $images->snapshot($host, $location)))->toBe('task_vm.image_unavailable');

    // A snapshot is the base, and an image promoted after it does not replace it.
    TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => 'recipe-2', 'state' => TaskVmImageBuildState::Succeeded, 'promoted_at' => now()->subHour(),
        'images' => ['base' => ['project' => 'orbit-tasks', 'type' => 'snapshot', 'vm' => 'beast-base', 'seed' => 'beast-base-seed', 'snapshot' => ImageRefreshSnapshot]]]);
    TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => 'recipe-3', 'state' => TaskVmImageBuildState::Succeeded, 'promoted_at' => now(),
        'images' => ['base' => ['project' => 'orbit-tasks', 'type' => 'virtual-machine', 'fingerprint' => ImageRefreshImage]]]);

    expect($images->recorded($host, $location))->toBe(['recipe_identity' => 'recipe-2', 'snapshot' => ImageRefreshSnapshot])
        ->and($images->forHost($host, $location))->toBe(['recipe_identity' => 'recipe-2', 'snapshot' => ImageRefreshSnapshot])
        ->and($images->snapshot($host, $location)['snapshot'])->toBe(ImageRefreshSnapshot);
});
