<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmUpCloud;
use App\Infrastructure\TaskVms\TaskVmUpCloudImageCommand;
use App\Infrastructure\TaskVms\UpCloudApi;
use App\Infrastructure\TaskVms\UpCloudServers;
use App\Models\Node;
use App\Models\Operation;
use App\Models\UpCloudTaskVmImageBuild;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\TaskVmSettingsBuilder;

function upcloudDeletionServer(string $name = 'tvm-90', string $label = TaskVmUpCloud::Label, string $value = '90', string $state = 'stopped'): array
{
    return ['uuid' => '00000000-0000-4000-8000-000000000090', 'title' => $name, 'hostname' => $name, 'state' => $state,
        'labels' => ['label' => [['key' => $label, 'value' => $value]]], 'ip_addresses' => ['ip_address' => []],
        'storage_devices' => ['storage_device' => [['storage' => '00000000-0000-4000-8000-000000000091']]]];
}

function upcloudDeletionBoundary(array $server, bool $missing = false): UpCloudServers
{
    $api = new UpCloudApi(TaskVmSettingsBuilder::bind(), 'ucat_test_delete_boundary');
    app()->instance(UpCloudApi::class, $api);
    Http::preventStrayRequests();
    Http::fake([
        UpCloudApi::Origin.'/server/'.$server['uuid'] => ($missing ? Http::response([], 404) : Http::response(['server' => $server])),
        UpCloudApi::Origin.'/server/'.$server['uuid'].'?storages=1&backups=delete' => Http::response([], 202),
    ]);
    Sleep::fake();
    Sleep::whenFakingSleep(static fn () => throw new RuntimeException('The named delete primitive must not poll.'));

    return new UpCloudServers($api);
}

afterEach(function (): void {
    Sleep::fake(false);
});

it('requests owned stopped server deletion once and leaves completion observations to Swamp', function (): void {
    $server = upcloudDeletionServer();
    $servers = upcloudDeletionBoundary($server);

    $servers->requestDelete('tvm-90', TaskVmUpCloud::Label, '90', $server['uuid']);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === UpCloudApi::Origin.'/server/'.$server['uuid'].'?storages=1&backups=delete');
    Sleep::assertNeverSlept();
});

it('refuses a delete request until the owned server is stopped', function (string $state): void {
    $server = upcloudDeletionServer(state: $state);
    $servers = upcloudDeletionBoundary($server);

    expect(fn () => $servers->requestDelete('tvm-90', TaskVmUpCloud::Label, '90', $server['uuid']))
        ->toThrow(fn (TaskVmException $error) => expect($error->errorCode)->toBe('task_vm.not_settled'));

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    Sleep::assertNeverSlept();
})->with(['started', 'maintenance', 'error']);

it('refuses a delete request for another recorded server identity', function (string $field): void {
    $server = upcloudDeletionServer();
    $server[$field] = $field === 'labels' ? ['label' => [['key' => TaskVmUpCloud::Label, 'value' => '91']]] : 'operator-owned';
    $servers = upcloudDeletionBoundary($server);

    expect(fn () => $servers->requestDelete('tvm-90', TaskVmUpCloud::Label, '90', $server['uuid']))
        ->toThrow(fn (TaskVmException $error) => expect($error->errorCode)->toBe('task_vm.upcloud_invalid'));

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
})->with(['title', 'hostname', 'labels']);

it('accepts an absent owned server without a delete request', function (): void {
    $server = upcloudDeletionServer();
    $servers = upcloudDeletionBoundary($server, missing: true);

    $servers->requestDelete('tvm-90', TaskVmUpCloud::Label, '90', $server['uuid']);

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('keeps image cleanup to one owned delete request after its stop child', function (string $slot): void {
    $stamp = '20261029120000';
    $name = 'tvm-image-'.$stamp.($slot === 'smoke' ? '-smoke' : '');
    $server = upcloudDeletionServer($name, UpCloudServers::ImageLabel, $stamp);
    upcloudDeletionBoundary($server);
    $node = Node::query()->create(['name' => 'gateway', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1']);
    $build = UpCloudTaskVmImageBuild::query()->create(['recipe_identity' => 'sha256:'.str_repeat('a', 64), 'state' => 'running', 'started_at' => now()]);
    $build->forceFill(['intent' => ['node_id' => $node->id, 'name' => 'tvm-image-'.$stamp, 'stamp' => $stamp],
        'resources' => [$slot => ['uuid' => $server['uuid']]]])->save();
    $operation = new Operation(['kind' => 'task_vm.upcloud_image_delete_'.$slot, 'target_type' => 'task_vm_upcloud_image', 'target_id' => $build->id, 'node_id' => $node->id]);

    $result = app(TaskVmUpCloudImageCommand::class)->run($operation, [], ['action' => 'delete-'.$slot, 'token' => 'ucat_test_delete_boundary']);

    expect($result['ready'])->toBeTrue();
    Http::assertSentCount(2);
    Sleep::assertNeverSlept();
})->with(['builder', 'smoke']);
