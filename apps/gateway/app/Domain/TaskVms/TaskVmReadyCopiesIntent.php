<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\PendingSshHostKey;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;

/**
 * Renders "Save the new images" of the seed refresh (docs/cli/tasks/vm-ready-copies.mmd) as the full workflow input: the
 * VM that runs the nested fleet, with its SSH identity and host key pin, the fixed names of the nested VMs and their
 * network, and the proof Swamp Club key as a vault name. No host effects.
 */
final readonly class TaskVmReadyCopiesIntent
{
    /** The nested guests (system containers) in a task VM, by role. The stage 2 steps create them under these names. */
    public const array Guests = ['gateway' => 'nested-gateway', 'app-dev' => 'nested-app-dev', 'app-prod' => 'nested-app-prod'];

    /** The nested network of a task VM. The stage 2 steps create it from the subnet the ready copies store. */
    public const string Network = 'oe-nested';

    /** The subnet of the nested network. The ready copies store the MAC and address of each Node inside it, so a converge and the fleet steps use this one. */
    public const string Subnet = '10.232.2.0/24';

    /**
     * The format of the ready copies, written to each copy as `user.ready.format`. A copy of another format, or none, is not usable: the
     * provision skips the nested fleet. Raise it when the steps that start or apply a fleet need something new in the copies. Format 4: the
     * nested guests are Incus system containers, not VMs; a copy of format 3 is a VM and no container step can start it. Format 3 had the nested
     * Gateway hold the control plan of its Swamp (`/opt/orbit/bootstrap/control.yml`) that the re-mint of the Swamp tokens reads, and format 4 keeps it.
     */
    public const string Format = '4';

    /**
     * The nested fleet of a task VM: the names of its VMs and of its network, for the steps that start it and the steps that save it.
     *
     * @return array{guests:array<string,string>,network:string,format:string}
     */
    public static function fleet(): array
    {
        return ['guests' => self::Guests, 'network' => self::Network, 'format' => self::Format];
    }

    public function __construct(private SwampSshExecutionInput $ssh, private SwampCommandStep $shared) {}

    /** @return array<string,mixed> one flat array that `TaskVmProviderWorkflowInput::envelope()` splits into its `intent` and its `execution_secrets` */
    public function capture(TaskVm $vm): array
    {
        $guest = $vm->node;
        if ($vm->state !== TaskVmState::Ready || $vm->provider !== TaskVmProviderKind::Incus || $vm->host_node_id === null || ! $guest instanceof Node || $guest->wireguard_ip !== $vm->wireguard_ip || $guest->user !== 'orbit'
            || $guest->status !== LifecycleStatus::Active || $guest->ssh_host_key === null) {
            throw new ResourceOperationException('task_vm.ready_copies_refused', 'Saving ready copies needs a ready Incus VM and its active guest identity with its recorded host key.', 409);
        }
        $club = config('swamp.proof_club_api_key');
        if (! is_string($club) || trim($club) === '') {
            throw new ResourceOperationException('task_vm.ready_copies_unavailable', 'Saving ready copies needs the proof Swamp Club key.', 409);
        }

        return $this->build($vm, $guest, $club);
    }

    /**
     * The same intent for a VM that a run is still provisioning, as the refresh of a task image captures it at its
     * admission. The caller wraps the call in {@see PendingSshHostKey::during()}: the run pins the guest key.
     *
     * @return array<string,mixed>
     */
    public function provisioning(TaskVm $vm): array
    {
        $guest = $vm->node;
        $club = config('swamp.proof_club_api_key');
        if (! $guest instanceof Node || $vm->provider !== TaskVmProviderKind::Incus || $vm->host_node_id === null || ! is_string($club) || trim($club) === '') {
            throw new ResourceOperationException('task_vm.ready_copies_unavailable', 'Saving ready copies needs an Incus VM with its guest and the proof Swamp Club key.', 409);
        }

        return $this->build($vm, $guest, $club);
    }

    /** @return array<string,mixed> */
    private function build(TaskVm $vm, Node $guest, string $club): array
    {
        return [
            'task_vm_id' => $vm->id, 'node_id' => $vm->host_node_id, 'guest_node_id' => $guest->id,
            'ssh_execution' => $this->ssh->forNode($guest),
            'fleet' => self::fleet(),
            'execution_secrets' => ['proof_club' => $this->shared->executionCopy('orbit-control-inputs', 'swamp-proof-club-api-key', $club)],
        ];
    }

    /** The VM of an admitted operation, still the one it was admitted for. */
    public function owned(Operation $operation): TaskVm
    {
        $input = $operation->input;
        $vm = TaskVm::query()->findOrFail($operation->target_id);
        if ($operation->kind !== 'task_vm.ready_copies' || $operation->target_type !== 'task_vm' || ($input['task_vm_id'] ?? null) !== $vm->id
            || ($input['node_id'] ?? null) !== $operation->node_id || ($input['guest_node_id'] ?? null) !== $vm->node?->id || ! is_array($input['fleet'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The ready copies no longer belong to their admitted VM.', 422);
        }

        return $vm;
    }
}
