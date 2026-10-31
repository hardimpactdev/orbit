<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageProjection;
use App\Domain\TaskVms\TaskVmUpCloudImageProjection;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use App\Models\UpCloudTaskVmImageBuild;
use Illuminate\Support\Facades\Http;

it('records a native image terminal result without the retired completion contract', function (string $provider, string $status): void {
    Http::preventStrayRequests();
    $node = Node::query()->create(['name' => 'image-host', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.13']);
    $identity = 'sha256:'.str_repeat('a', 64);
    $run = '00000000-0000-4000-8000-000000000007';
    if ($provider === 'incus') {
        $build = TaskVmImageBuild::query()->create(['host_node_id' => $node->id, 'recipe_identity' => $identity, 'recipe_generation' => 1,
            'current_step' => 'recipe', 'current_program' => 'base:reset',
            'steps' => [['step' => 'recipe', 'program' => 'base:reset', 'started_at' => now()->toISOString(), 'seconds' => null, 'error' => null]]]);
        $input = ['build_id' => $build->id, 'host_node_id' => $node->id, 'attempt' => $build->attempt,
            'identity' => $identity, 'generation' => 1, 'agent_sha256' => str_repeat('b', 64)];
        $kind = 'task_vm.image_build';
        $target = 'task_vm_image';
        $projection = app(TaskVmImageProjection::class);
    } else {
        $build = UpCloudTaskVmImageBuild::query()->create(['recipe_identity' => $identity, 'state' => 'running', 'started_at' => now()]);
        $input = ['build_id' => $build->id, 'node_id' => $node->id, 'identity' => $identity];
        $build->forceFill(['intent' => $input])->save();
        $kind = 'task_vm.upcloud_image_build';
        $target = 'task_vm_upcloud_image';
        $projection = app(TaskVmUpCloudImageProjection::class);
    }
    $op = Operation::query()->create(['kind' => $kind, 'target_type' => $target, 'target_id' => $build->id,
        'node_id' => $node->id, 'input' => $input, 'status' => 'running', 'state' => ['swamp_run_id' => $run]]);
    $code = $status === 'failed' ? 'task_vm.image_channel_lost' : null;
    $data = ['run_id' => $run, 'status' => $status, 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(),
        'error_code' => $code, 'result' => $code === null ? [] : ['error_code' => $code]];

    $projection->apply($op, $data);
    $projection->apply($op->fresh(), $data);

    expect($op->fresh()->status)->toBe(OperationStatus::from($status))
        ->and($op->fresh()->error_code)->toBe($code)
        ->and($op->fresh()->lock_key)->toBeNull()
        ->and($build->fresh()->state)->toBe(TaskVmImageBuildState::Failed);
    if ($provider === 'incus') {
        expect($build->fresh()->steps[0]['error'])->toBe($code ?? 'operation.cancelled');
    } else {
        expect($build->fresh()->finished_at)->not->toBeNull();
    }
    Http::assertNothingSent();
})->with([
    'Incus failure' => ['incus', 'failed'], 'Incus cancellation' => ['incus', 'cancelled'],
    'UpCloud failure' => ['upcloud', 'failed'], 'UpCloud cancellation' => ['upcloud', 'cancelled'],
]);
