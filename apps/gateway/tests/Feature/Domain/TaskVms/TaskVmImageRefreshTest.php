<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageRefreshIntent;
use App\Domain\TaskVms\TaskVmImageRefreshProjection;
use App\Domain\TaskVms\TaskVmPromotedImage;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\TaskVmVolumes;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(LazilyRefreshDatabase::class);

const ImageRefreshCommit = 'dddddddddddd0000000000000000000000000000';
const ImageRefreshImage = 'c1c2cf4051fb0123456789abcdef0123456789abcdef0123456789abcdef0123';

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

/** A configured task VM host with a promoted image. */
function imageRefreshHost(): Node
{
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    $host = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.9',
        'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $cluster->id, '10.44.0.128/25',
        [new TaskVmHost($host->id, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 5, 4, '8GiB', '40GiB')],
        'http://10.44.0.3:8317', '/pi', str_repeat('a', 64), ['gpt-6']));
    TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => 'recipe-1', 'state' => TaskVmImageBuildState::Succeeded,
        'promoted_at' => now(), 'images' => ['base' => ['project' => 'orbit-tasks', 'type' => 'virtual-machine', 'fingerprint' => ImageRefreshImage]]]);

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

    $build = TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => 'recipe-2', 'state' => TaskVmImageBuildState::Running]);
    expect(imageRefreshRefusal(fn () => $intent->start($host, 'test')))->toBe('task_vm.image_build_running');
    $build->delete();

    TaskVmImageBuild::query()->where('host_node_id', $host->id)->delete();
    expect(imageRefreshRefusal(fn () => $intent->start($host, 'test')))->toBe('task_vm.image_unavailable');
    expect(Operation::query()->count())->toBe(0);
});

it('refuses a refresh when the Gateway release has no commit', function (): void {
    $host = imageRefreshHost();
    unlink($this->base.'/orbit');

    expect(imageRefreshRefusal(fn () => app(TaskVmImageRefreshIntent::class)->start($host, 'test')))->toBe('task_vm.image_refresh_unavailable');
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

it('records the image the promotion names as the host\'s latest build, and nothing for a failed run', function (): void {
    $host = imageRefreshHost();
    $new = str_repeat('e', 64);
    $operation = Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_vm_host', 'target_id' => $host->id, 'node_id' => $host->id,
        'status' => OperationStatus::Running, 'triggered_by' => 'test', 'input' => ['commit' => ImageRefreshCommit, 'recipe_identity' => 'recipe-9',
            'intent' => ['host' => ['node_id' => $host->id, 'project' => 'orbit-tasks', 'alias' => 'orbit-task-base', 'current' => ImageRefreshImage], 'vm' => [], 'converge' => []],
            'execution_secrets' => []]]);
    $promote = ['Read the aliases' => ['stdout' => json_encode([['name' => 'orbit-task-base-previous', 'target' => ImageRefreshImage], ['name' => 'orbit-task-base', 'target' => $new]])]];
    $projection = app(TaskVmImageRefreshProjection::class);

    expect($projection->fingerprint($operation, ['promote' => $promote]))->toBe($new)
        ->and(fn () => $projection->fingerprint($operation, ['promote' => ['Read the aliases' => ['stdout' => '[]']]]))->toThrow(ResourceOperationException::class);

    $failed = $operation->replicate();
    $failed->save();
    $projection->apply($failed, ['status' => 'failed', 'result' => []]);
    expect(app(TaskVmPromotedImage::class)->latest($host)?->image_fingerprint)->not->toBe($new);

    $projection->apply($operation, ['status' => 'succeeded', 'result' => ['promote' => $promote]]);
    $latest = app(TaskVmPromotedImage::class)->latest($host);
    expect($latest?->image_fingerprint)->toBe($new)->and($latest?->recipe_identity)->toBe('recipe-9')
        ->and($operation->refresh()->result)->toMatchArray(['refreshed' => true, 'image_fingerprint' => $new, 'previous_fingerprint' => ImageRefreshImage]);
});

it('names the seed of an image from its fingerprint, so the refreshed image has a seed of its own', function (): void {
    expect(TaskVmVolumes::seed(ImageRefreshImage))->toBe('orbit-topology-seed-c1c2cf4051fb')
        ->and(TaskVmVolumes::SeedPrefix.substr(ImageRefreshImage, 0, 12))->toBe(TaskVmVolumes::seed(ImageRefreshImage));
});
