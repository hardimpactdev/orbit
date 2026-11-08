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
 * The slots of the task VM hosts (docs/cli/task/start.mmd). A slot is only a place under a host's capacity (`max_vms`); a task start
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
     * Takes a slot in the admission transaction: on the host with the most room that has a base snapshot, or on `$host`. Only an authenticated CI
     * proof (`$proof`) or a refresh (no group) may take the reserve, slot 1, or use a CI-only host. CI prefers CI-only hosts; a refresh stays on its own host. It records the task VM and mints its identity: the Node with its `app-dev` role
     * and `pi-server` Process, a WireGuard key pair, the agent secret and an SSH host key, pinned on the Node now so nothing is read back.
     *
     * @throws TaskCapacityException when no host has room, or the group's Project is not the one whose checkout the base carries
     */
    public function take(?Task $group, ?int $host = null, bool $proof = false): TaskVm
    {
        // The base VM carries the checkout of the proof Project only, so a task VM can serve no other Project.
        if ($group !== null && $group->project_id !== $this->proof->projectId()) {
            throw new TaskCapacityException(TaskWait::Capacity, "Task VM: the task VM base carries only the checkout of Project {$this->proof->projectId()}.");
        }
        $size = TaskVmSize::Large;
        // A group needs a host with a base snapshot; a refresh proves the snapshot it just took, before the Gateway records it.
        $hosts = array_values(array_filter($this->capacity->activeHosts(), fn (array $pair): bool => ($host === null || $pair[1]->id === $host)
            && ($group === null || $this->currentImage($pair[1], $pair[0]) !== null)));
        $free = $this->capacity->freeSlots($hosts, $proof || $group === null);
        $ciOnly = [];
        foreach ($hosts as [$location]) {
            $ciOnly[$location->nodeId] = $location->ciOnly;
        }
        uksort($free, static fn (int $a, int $b): int => ($ciOnly[$b] <=> $ciOnly[$a]) ?: ($free[$b] <=> $free[$a]));
        $room = array_filter($free, static fn (int $slots): bool => $slots >= $size->slots());
        // A proof takes only a host whose base includes every change of what the base carries up to its merge base.
        $skipped = $group === null ? [] : array_filter($room, static fn (int $slots, int $id): bool => ! app(TaskVmSeedFloor::class)->allows($id), ARRAY_FILTER_USE_BOTH);
        $room = array_diff_key($room, $skipped);
        if ($room === []) {
            $names = Node::query()->whereKey(array_keys($skipped))->get()->mapWithKeys(static fn (Node $node): array => [$node->id => $node->name])->all();
            throw new TaskCapacityException(TaskWait::Capacity, 'Task VM: no host has room for another task VM'.($proof || $group === null ? ' (including the shared CI/refresh reserve).' : ' within the task VM limit and outside the CI/refresh reserve.')
                .($names === [] ? '' : self::staleImages($names)));
        }
        $node = Node::query()->findOrFail(array_key_first($room));
        $used = TaskVm::query()->live()->where('host_node_id', $node->id)->whereNotNull('slot_number')->get(['slot_number'])
            ->map(static fn (TaskVm $vm): ?int => $vm->slot_number)->all();
        $number = $proof || $group === null ? 1 : 2;
        while (in_array($number, $used, true)) {
            $number++;
        }
        $vm = TaskVm::query()->create([
            'host_node_id' => $node->id, 'slot_number' => $number, 'slot_project_id' => $this->proof->projectId(),
            'provider' => TaskVmProviderKind::Incus, 'size' => $size, 'name' => $node->name.'-tvm-'.$number, 'state' => TaskVmState::Provisioning,
            'group_id' => $group?->id, 'claimed_at' => now(),
            'wireguard_ip' => $this->addresses->nextIn($this->settings->wireguardRange,
                array_values(TaskVm::query()->live()->get()->map(static fn (TaskVm $vm): string => $vm->wireguard_ip)->all())),
        ]);
        $this->pin($this->keys->ensure($vm));

        return $vm->refresh();
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
