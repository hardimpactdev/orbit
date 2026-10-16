<?php

declare(strict_types=1);

use App\Actions\TaskVms\PrepareTaskVmRuntimeAction;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\AgentThread;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\FakeAgentDriver;
use Tests\Support\TaskVmRecipeImages;
use Tests\Support\TaskVmSettingsBuilder;

/** @return array{Task, TaskVm, string} */
function roleImageRecoveryFixture(): array
{
    $host = Node::query()->create(['name' => 'host', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit']);
    $node = Node::query()->create(['name' => 'tvm-1', 'public_ssh_host' => '192.0.2.129', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.129', 'user' => 'orbit']);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($host->id)]]);
    $build = TaskVmRecipeImages::ready($host->id);
    $project = Project::query()->create(['name' => 'Recipe', 'slug' => 'recipe', 'repository_url' => 'https://github.com/acme/recipe.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Nodes', 'brief' => 'Work', 'status' => 'running', 'assistance_requested' => true]);
    $vm = TaskVm::query()->create(['group_id' => $group->id, 'node_id' => $node->id, 'host_node_id' => $host->id, 'provider' => 'incus', 'size' => 'medium', 'name' => 'tvm-1', 'state' => 'ready', 'wireguard_ip' => '10.44.0.129', 'recipe_identity' => $build->recipe_identity, 'image_fingerprint' => $build->image_fingerprint]);
    $manifest = json_encode(['recipe_identity' => $build->recipe_identity, 'images' => ['base' => $build->images['base']]], JSON_THROW_ON_ERROR);

    return [$group, $vm, 'orbit-role-images-ready:'.$vm->image_fingerprint.':'.hash('sha256', $manifest."\n")];
}

it('recovers only the owned ready VM pinned recipe and leaves assistance and lifecycle unchanged', function (): void {
    [$group, $vm, $receipt] = roleImageRecoveryFixture();
    DB::table('task_vm_image_recipe')->where('id', 1)->update(['identity' => 'sha256:'.str_repeat('b', 64)]);
    $before = $vm->fresh()->getAttributes();
    $ssh = new AppDevFakeSshExecutor([new CommandResult(0, $receipt."\n", '', 1, false)]);
    app()->instance(SshExecutor::class, $ssh);
    $driver = new FakeAgentDriver;
    $driver->observation = new AgentObservation(AgentThreadState::Done);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    AgentThread::query()->create(['task_group_id' => $group->id, 'driver' => 'example', 'runtime_key' => 'example', 'external_id' => 'thread', 'role' => 'reviewer', 'state' => 'working']);

    $this->artisan('task-vms:retry-role-images', ['group' => (string) $group->id, 'vm' => (string) $vm->id])->assertSuccessful();

    expect($vm->fresh()->getAttributes())->toBe($before);
    expect($group->fresh()->assistance_requested)->toBeTrue();
    expect($ssh->commands)->toHaveCount(1);
    $manifest = json_decode($ssh->commands[0]->arguments[7], true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['recipe_identity'])->toBe($vm->recipe_identity);
    expect($manifest['images']['base']['fingerprint'])->toBe($vm->image_fingerprint);
});

it('refuses unsafe recovery without transferring files', function (array $groupChanges, array $vmChanges): void {
    [$group, $vm] = roleImageRecoveryFixture();
    $group->update($groupChanges);
    $vm->update($vmChanges);
    $ssh = new AppDevFakeSshExecutor;
    app()->instance(SshExecutor::class, $ssh);

    $this->artisan('task-vms:retry-role-images', ['group' => (string) $group->id, 'vm' => (string) $vm->id])->assertFailed();

    expect($ssh->commands)->toBeEmpty();
})->with([
    'not waiting' => [['assistance_requested' => false], []],
    'completed' => [['status' => 'completed'], []],
    'completion authorized' => [['watched_pr_completion' => 'merged'], []],
    'not owned' => [[], ['group_id' => null]],
    'parked' => [[], ['state' => 'parked']],
    'not Incus' => [[], ['provider' => 'upcloud']],
    'not enrolled' => [[], ['node_id' => null]],
    'pre-recipe' => [[], ['recipe_identity' => null]],
    'no topology capacity' => [[], ['size' => 'normal']],
    'preparation active' => [[], ['topology_state' => 'preparing', 'topology_run' => 'run']],
    'topology ready' => [[], ['topology_state' => 'ready', 'topology_run' => 'run']],
]);

it('refuses an active or unobservable agent despite a cached done state', function (?AgentThreadState $observed): void {
    [$group, $vm] = roleImageRecoveryFixture();
    $driver = new FakeAgentDriver;
    $driver->observation = $observed === null ? null : new AgentObservation($observed);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    AgentThread::query()->create(['task_group_id' => $group->id, 'driver' => 'example', 'runtime_key' => 'example', 'external_id' => 'thread', 'role' => 'reviewer', 'state' => 'done']);
    $ssh = new AppDevFakeSshExecutor;
    app()->instance(SshExecutor::class, $ssh);

    $this->artisan('task-vms:retry-role-images', ['group' => (string) $group->id, 'vm' => (string) $vm->id])->assertFailed();

    expect($ssh->commands)->toBeEmpty();
})->with([AgentThreadState::Working, AgentThreadState::AskingForInput, null]);

it('refuses missing or mismatched completion receipts without marking a provisioning VM ready', function (string $stdout, bool $truncated): void {
    [, $vm] = roleImageRecoveryFixture();
    $vm->update(['state' => 'provisioning']);
    app()->instance(SshExecutor::class, new AppDevFakeSshExecutor([new CommandResult(0, $stdout, '', 1, $truncated)]));

    expect(fn () => app(PrepareTaskVmRuntimeAction::class)->execute($vm->id))->toThrow(TaskVmException::class);

    expect($vm->fresh()->state->value)->toBe('provisioning');
    expect($vm->fresh()->ready_at)->toBeNull();
})->with([['', false], ['orbit-role-images-ready:wrong:wrong', false], ['', true]]);

it('keeps recovery waiting after an incomplete transfer', function (): void {
    [$group, $vm] = roleImageRecoveryFixture();
    app()->instance(SshExecutor::class, new AppDevFakeSshExecutor);

    $this->artisan('task-vms:retry-role-images', ['group' => (string) $group->id, 'vm' => (string) $vm->id])->assertFailed();

    expect($group->fresh()->assistance_requested)->toBeTrue();
    expect($vm->fresh()->state->value)->toBe('ready');
    expect($vm->fresh()->topology_run)->toBeNull();
});

it('refuses recovery when the pinned promoted build is unavailable', function (): void {
    [$group, $vm] = roleImageRecoveryFixture();
    DB::table('task_vm_image_builds')->update(['promoted_at' => null]);
    $ssh = new AppDevFakeSshExecutor;
    app()->instance(SshExecutor::class, $ssh);

    $this->artisan('task-vms:retry-role-images', ['group' => (string) $group->id, 'vm' => (string) $vm->id])->assertFailed();

    expect($ssh->commands)->toBeEmpty();
    expect($group->fresh()->assistance_requested)->toBeTrue();
});

it('refuses a pending send lease even when the agent reports done', function (): void {
    [$group, $vm] = roleImageRecoveryFixture();
    $thread = AgentThread::query()->create(['task_group_id' => $group->id, 'driver' => 'example', 'runtime_key' => 'example', 'external_id' => 'thread', 'role' => 'reviewer', 'state' => 'done']);
    $thread->sendLeases()->create(['owner_token' => 'owner', 'expires_at' => now()->addMinute()]);
    $ssh = new AppDevFakeSshExecutor;
    app()->instance(SshExecutor::class, $ssh);

    $this->artisan('task-vms:retry-role-images', ['group' => (string) $group->id, 'vm' => (string) $vm->id])->assertFailed();

    expect($ssh->commands)->toBeEmpty();
});
