<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\Nodes\RecordNodeSshHostKeyAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Proof\ProofSettings;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskWait;
use App\Domain\WireGuard\WireGuardAddressAllocator;
use App\Infrastructure\Ssh\HostKey;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;

/**
 * The slots of the task VM hosts (docs/cli/task/start.mmd). A slot is one place in the request's pool; a task start
 * takes the lowest free slot number and mints a fresh identity for the VM it makes, `<host>-tvm-<n>`, and its release removes both
 * (docs/cli/task/cancel.mmd). Every task VM copies the host's base VM, so each has the size of a large one.
 */
final readonly class TaskVmSlotPool
{
    public const string ReleaseKind = 'task_vm.slot_release';

    public function __construct(private TaskVmSettings $settings, private TaskVmCapacity $capacity, private TaskVmPromotedImage $images,
        private TaskVmSlotIdentity $keys, private TaskVmEnrollmentIntent $enrollment, private WireGuardAddressAllocator $addresses,
        private ProofSettings $proof) {}

    /**
     * Takes one place in the request's pool on a host with a base snapshot, or on `$host`. A refresh uses the CI pool.
     * Records the VM and mints its fresh Node identity and keys before execution.
     *
     * @throws TaskCapacityException when no host has room
     * @throws TaskVmException `task_vm.project_unsupported` when the group's Project is not the one whose checkout the base carries
     */
    public function take(?Task $group, ?int $host = null, bool $proof = false): TaskVm
    {
        if ($group !== null) {
            $this->assertServes($group);
        }
        $size = TaskVmSize::Large;
        $room = $this->room($group !== null, $host, $proof || $group === null);
        $node = Node::query()->findOrFail(array_key_first($room));
        $used = TaskVm::query()->live()->where('host_node_id', $node->id)->whereNotNull('slot_number')->get(['slot_number'])
            ->map(static fn (TaskVm $vm): ?int => $vm->slot_number)->all();
        $number = 1;
        while (in_array($number, $used, true)) {
            $number++;
        }
        $vm = TaskVm::query()->create([
            'host_node_id' => $node->id, 'slot_number' => $number, 'slot_project_id' => $this->proof->projectId(),
            'provider' => TaskVmProviderKind::Incus, 'capacity_pool' => $proof || $group === null ? 'ci' : 'task', 'size' => $size, 'name' => $node->name.'-tvm-'.$number, 'state' => TaskVmState::Provisioning,
            'group_id' => $group?->id, 'claimed_at' => now(),
            'wireguard_ip' => $this->addresses->nextIn($this->settings->wireguardRange,
                array_values(TaskVm::query()->live()->get()->map(static fn (TaskVm $vm): string => $vm->wireguard_ip)->all())),
        ]);
        $this->pin($this->keys->ensure($vm));

        return $vm->refresh();
    }

    /**
     * The base VM carries the checkout of the proof Project only, so a task VM can serve no other Project. No place that frees up
     * changes that, so admission refuses such a group at once instead of queueing it, where it would hold up every later admission.
     *
     * @throws TaskVmException `task_vm.project_unsupported`
     */
    public function assertServes(Task $group): void
    {
        if ($group->project_id !== $this->proof->projectId()) {
            throw new TaskVmException(TaskVmError::ProjectUnsupported, "A task VM serves only Project {$this->proof->projectId()}: its base carries only that checkout.");
        }
    }

    /** Free CI places under the same image and seed policy as take(). Call in the admission transaction. */
    public function freeCiPlaces(): int
    {
        return array_sum($this->room(true, null, true));
    }

    /** @return array<int,int> usable host id => free places */
    private function room(bool $needsImage, ?int $host, bool $ci): array
    {
        // A group needs a host with a base snapshot; a refresh proves the snapshot it just took, before the Gateway records it.
        $hosts = array_values(array_filter($this->capacity->activeHosts(), fn (array $pair): bool => ($host === null || $pair[1]->id === $host)
            && (! $needsImage || $this->currentImage($pair[1], $pair[0]) !== null)));
        $free = $this->capacity->freeSlots($hosts, $ci);
        arsort($free);
        $room = array_filter($free, static fn (int $places): bool => $places > 0);
        // A proof takes only a host whose base includes every change of what the base carries up to its merge base.
        $skipped = ! $needsImage ? [] : array_filter($room, static fn (int $slots, int $id): bool => ! app(TaskVmSeedFloor::class)->allows($id), ARRAY_FILTER_USE_BOTH);
        $room = array_diff_key($room, $skipped);
        if ($room === []) {
            $names = Node::query()->whereKey(array_keys($skipped))->get()->mapWithKeys(static fn (Node $node): array => [$node->id => $node->name])->all();
            throw new TaskCapacityException(TaskWait::Capacity, 'Task VM: waiting for a free '.($ci ? 'CI' : 'task').' place.'
                .($names === [] ? '' : self::staleImages($names)));
        }

        return $room;
    }

    /** @param array<int,string> $skipped host Node id => name */
    private static function staleImages(array $skipped): string
    {
        $text = ' Hosts with room but no usable nested fleet seed are skipped: '.implode(', ', $skipped).'.'
            .' A proof needs a base that includes every change of the playbooks, pins and lock files up to the merge base of its commit.';
        foreach ($skipped as $id => $name) {
            $refresh = TaskVmImageRefreshIntent::open($id);
            $text .= " {$name}: ".(app(TaskVmSeedFloor::class)->reason($id) ?? 'no refresh has promoted a usable base for it').'; '
                .($refresh instanceof Operation ? "a refresh is running (operation {$refresh->id}), run the proof again when it ends." : 'no refresh is running; a release that changes the playbooks, pins or lock files starts one.');
        }

        return $text;
    }

    /** @return array{vm:string,seed:string,snapshot:string,recipe_identity:string}|null the base snapshot a task start on the host copies */
    public function currentImage(Node $host, TaskVmHost $location): ?array
    {
        try {
            return $this->images->snapshot($host, $location);
        } catch (ResourceOperationException) {
            return null;
        }
    }

    /** The Node of the identity, active with its keys from the start: the agent, SSH and WireGuard trust them before the VM exists. */
    private function pin(TaskVm $vm): void
    {
        $node = $this->enrollment->slotNode($vm);
        $sshHost = ValidatedData::string($vm->ssh_host_private_key);
        [$type, $key] = explode(' ', TaskVmSlotIdentity::sshPublicKey($sshHost), 2);
        app(RecordNodeSshHostKeyAction::class)->execute($node, (string) $vm->wireguard_ip, new HostKey($type, $key, TaskVmSlotIdentity::sshFingerprint($sshHost)));
        $node->forceFill(['agent_secret_hash' => hash('sha256', ValidatedData::string($vm->agent_secret)),
            'wireguard_public_key' => TaskVmSlotIdentity::wireguardPublicKey(ValidatedData::string($vm->wireguard_private_key)),
            'status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null])->save();
        $node->roles()->where('role', RoleName::AppDev)->update(['status' => LifecycleStatus::Active]);
        Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->where('name', 'pi-server')
            ->where('desired_state', DesiredProcessState::Running)->update(['status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);
    }
}
