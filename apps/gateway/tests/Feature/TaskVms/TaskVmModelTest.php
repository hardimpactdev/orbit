<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmPlacementPolicy;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $project = Project::query()->create(['name' => 'DLF', 'slug' => 'dlf', 'repository_url' => 'https://github.com/acme/dlf.git', 'default_branch' => 'main']);
    $this->group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => 'todo']);
    $this->host = Node::query()->create([
        'name' => 'beast', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit',
    ]);
    $this->vm = fn (array $attributes = []): TaskVm => TaskVm::query()->create([
        'group_id' => $this->group->id, 'host_node_id' => $this->host->id, 'provider' => 'incus', 'name' => 'tvm-'.$this->group->id,
        'state' => TaskVmState::Provisioning, 'wireguard_ip' => '10.44.0.130', 'pi_token' => 'pi-secret', ...$attributes,
    ]);
});

it('casts the state and timestamps', function (): void {
    ($this->vm)(['state' => TaskVmState::Ready, 'ready_at' => '2026-10-09 12:00:00']);

    $vm = TaskVm::query()->sole();

    expect($vm->state)->toBe(TaskVmState::Ready)
        ->and($vm->ready_at?->toDateTimeString())->toBe('2026-10-09 12:00:00')
        ->and($vm->group->is($this->group))->toBeTrue()
        ->and($vm->hostNode->is($this->host))->toBeTrue()
        ->and($vm->node)->toBeNull();
});

it('casts the provider to its kind', function (): void {
    ($this->vm)();

    expect(TaskVm::query()->sole()->provider)->toBe(TaskVmProviderKind::Incus);
});

it('counts against a cap every live VM of the provider, and a failed one only at UpCloud', function (): void {
    $states = [TaskVmState::Ready, TaskVmState::Parked, TaskVmState::Failed, TaskVmState::Destroyed];
    foreach ([TaskVmProviderKind::Incus, TaskVmProviderKind::UpCloud] as $p => $provider) {
        foreach ($states as $s => $state) {
            $index = $p * 10 + $s;
            ($this->vm)([
                'group_id' => null, 'provider' => $provider, 'host_node_id' => $provider->needsHostNode() ? $this->host->id : null,
                'name' => 'tvm-cap-'.$index, 'wireguard_ip' => '10.44.0.'.(140 + $index), 'state' => $state,
            ]);
        }
    }

    expect(TaskVm::query()->countedAgainstCap(TaskVmProviderKind::Incus)->orderBy('id')->pluck('name')->all())->toBe(['tvm-cap-0', 'tvm-cap-1'])
        ->and(TaskVm::query()->countedAgainstCap(TaskVmProviderKind::UpCloud)->orderBy('id')->pluck('name')->all())->toBe(['tvm-cap-10', 'tvm-cap-11', 'tvm-cap-12']);
});

it('knows what each provider can do', function (TaskVmProviderKind $provider, bool $hostNode, array $sizes, bool $templates, bool $failedCount): void {
    expect($provider->needsHostNode())->toBe($hostNode)
        ->and(array_values(array_filter(TaskVmSize::cases(), $provider->supports(...))))->toBe($sizes)
        ->and($provider->hasWorkspaceTemplates())->toBe($templates)
        ->and($provider->countsFailedVms())->toBe($failedCount);
})->with([
    'incus' => [TaskVmProviderKind::Incus, true, TaskVmSize::cases(), true, false],
    'upcloud' => [TaskVmProviderKind::UpCloud, false, [TaskVmSize::Normal], false, true],
]);

it('defines what each size takes in one place', function (): void {
    $host = new TaskVmHost($this->host->id, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 4, 2, '4GiB', '20GiB');
    $takes = static fn (TaskVmSize $size): array => [
        $size->slots(), $size->resources()->warmEligible, $size->resources()->cpusOn($host), $size->resources()->memoryOn($host), $size->resources()->diskOn($host), $size->resources()->qemuConfig !== null,
    ];

    expect(array_map($takes, TaskVmSize::cases()))->toBe([
        [1, true, 2, '4GiB', '20GiB', false],
        [2, false, 4, '8GiB', '40GiB', true],
        [3, false, 6, '12GiB', '60GiB', true],
    ])
        ->and(TaskVmPlacementPolicy::noCapacityFor(TaskVmSize::Medium))->toBe('Task VM: no host has 2 free slots for a medium VM.')
        ->and(TaskVmPlacementPolicy::noCapacityFor(TaskVmSize::Large))->toBe('Task VM: no host has 3 free slots for a large VM.');
});

it('encrypts the Pi token and model key at rest and hides them', function (): void {
    ($this->vm)(['model_key' => 'model-secret']);

    $row = DB::table('task_vms')->sole();
    $vm = TaskVm::query()->sole();

    expect($row->pi_token)->not->toContain('pi-secret')
        ->and(Crypt::decryptString($row->pi_token))->toBe('pi-secret')
        ->and($row->model_key)->not->toContain('model-secret')
        ->and($vm->pi_token)->toBe('pi-secret')
        ->and($vm->model_key)->toBe('model-secret')
        ->and($vm->toArray())->not->toHaveKeys(['pi_token', 'model_key']);
});

it('allows one live task VM per group and per WireGuard address', function (): void {
    ($this->vm)();

    expect(fn () => ($this->vm)(['name' => 'tvm-second', 'wireguard_ip' => '10.44.0.131']))->toThrow(QueryException::class);

    TaskVm::query()->update(['state' => TaskVmState::Destroyed]);
    $next = ($this->vm)(['name' => 'tvm-second']);

    expect(TaskVm::query()->live()->sole()->is($next))->toBeTrue();
});

it('keeps the destroyed row when its Node is removed', function (): void {
    $node = Node::query()->create([
        'name' => 'tvm-1', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.20', 'wireguard_ip' => '10.44.0.130', 'user' => 'orbit',
    ]);
    $vm = ($this->vm)(['node_id' => $node->id, 'state' => TaskVmState::Destroying]);

    $node->delete();

    expect($vm->fresh()?->node_id)->toBeNull();
});

it('lists every task VM error code of the Gateway in TaskVmError', function (): void {
    $codes = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() === 'php' && preg_match_all("/'(task_vm\\.[a-z_]+)'/", (string) file_get_contents($file->getPathname()), $matches) > 0) {
            array_push($codes, ...$matches[1]);
        }
    }

    expect($codes)->not->toBeEmpty()
        ->and(array_values(array_diff(array_unique($codes), array_column(TaskVmError::cases(), 'value'))))->toBe([])
        ->and(new TaskVmException(TaskVmError::Parked)->errorCode)->toBe('task_vm.parked');
});
