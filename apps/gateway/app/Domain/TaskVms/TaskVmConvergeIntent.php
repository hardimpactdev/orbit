<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\VitePlusRuntimeLock;
use App\Infrastructure\Swamp\PendingSshHostKey;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Node;
use App\Models\TaskVm;

/** The pins of the release and the VM's SSH target for one converge of the task VM role. No execution. */
final readonly class TaskVmConvergeIntent
{
    public function __construct(private SwampSshExecutionInput $ssh, private RecipeImageBuilds $recipes) {}

    /**
     * @param  array{subnet:string,interface:string}|null  $nested  The nested network the VM leads; null leaves Incus as it is.
     * @return array<string,mixed>
     */
    public function capture(TaskVm $vm, ?array $nested): array
    {
        $guest = $vm->node;
        if ($vm->state !== TaskVmState::Ready || ! $guest instanceof Node || $guest->wireguard_ip !== $vm->wireguard_ip || $guest->user !== 'orbit'
            || $guest->status !== LifecycleStatus::Active || $guest->ssh_host_key === null) {
            throw new ResourceOperationException('task_vm.converge_refused', 'A converge needs the ready VM and its active guest identity with its recorded host key.', 409);
        }

        return $this->build($vm, $guest, $nested);
    }

    /**
     * The same intent for a VM that a run is still provisioning, as the refresh of a task image captures it at its
     * admission. The caller wraps the call in {@see PendingSshHostKey::during()}: the run pins the guest key.
     *
     * @param  array{subnet:string,interface:string}|null  $nested
     * @return array<string,mixed>
     */
    public function provisioning(TaskVm $vm, ?array $nested): array
    {
        $guest = $vm->node;
        if (! $guest instanceof Node || $guest->wireguard_ip !== $vm->wireguard_ip || $guest->user !== 'orbit') {
            throw new ResourceOperationException('task_vm.converge_refused', 'A converge needs the guest identity of its VM.', 409);
        }

        return $this->build($vm, $guest, $nested);
    }

    /**
     * @param  array{subnet:string,interface:string}|null  $nested
     * @return array<string,mixed>
     */
    private function build(TaskVm $vm, Node $guest, ?array $nested): array
    {
        $architecture = MachineRecipe::architecture($guest->architecture)
            ?? throw new ResourceOperationException('task_vm.converge_refused', 'The guest architecture is unsupported.', 409);
        $recipe = $this->recipes->currentRecipe();
        $recipe->profile('base', $architecture);
        $pi = $recipe->tools['pi']->artifacts[$architecture] ?? throw new ResourceOperationException('task_vm.converge_refused', 'The recipe holds no Pi server for this architecture.', 409);
        $gateway = Node::query()->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->firstOrFail();

        return [
            'task_vm_id' => $vm->id,
            'guest_node_id' => $guest->id,
            'name' => $vm->name,
            'recipe_identity' => $recipe->identity,
            'ssh' => $this->ssh->forNode($guest),
            'profile' => 'base',
            'image' => false,
            'recipe' => json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR),
            'vp_lock' => VitePlusRuntimeLock::read($recipe->tools['vp']),
            // The run copies the Pi server from the Gateway host to 209; the playbook copies it on to the VM when the VM's copy differs.
            'pi' => ['sha256' => $pi->digest, 'source' => ['ssh' => $this->ssh->forNode($gateway), 'path' => config()->string('orbit.home').'/sandbox-artifacts/'.$pi->location]],
            'nested' => $nested,
        ];
    }
}
