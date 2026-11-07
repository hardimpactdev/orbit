<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;

/**
 * Renders the fleet build (docs/cli/tasks/vm-fleet-build.mmd) as the full workflow input: the VM that runs the nested fleet, with
 * its SSH identity and host key pin, the fixed layout of the nested containers and their network, and the public pins the new Gateway is
 * prepared with. No secret and no host effects.
 */
final readonly class TaskVmFleetBuildIntent
{
    public function __construct(private SwampSshExecutionInput $ssh, private AgentDelivery $delivery, private SwampCommandStep $shared) {}

    /** @return array<string,mixed> one flat array that `TaskVmProviderWorkflowInput::envelope()` splits into its `intent` and its `execution_secrets` */
    public function capture(TaskVm $vm): array
    {
        $this->assertLarge($vm);
        $guest = $vm->node;
        if ($vm->state !== TaskVmState::Ready || $vm->provider !== TaskVmProviderKind::Incus || $vm->host_node_id === null || ! $guest instanceof Node
            || $guest->wireguard_ip !== $vm->wireguard_ip || $guest->status !== LifecycleStatus::Active || $guest->ssh_host_key === null) {
            throw new ResourceOperationException('task_vm.fleet_build_refused', 'Building a fleet needs a ready Incus VM and its active guest identity with its recorded host key.', 409);
        }

        return $this->build($vm, $guest, $this->club(), $this->workspace($vm));
    }

    /**
     * The same intent for a VM that the run itself creates, as the refresh of a task image admits it: the VM is not ready yet, so its host key
     * is not recorded and the guest's SSH target carries `host_keys: null` until the run pins it.
     *
     * @return array<string,mixed>
     */
    public function provisioning(TaskVm $vm, string $workspace): array
    {
        $this->assertLarge($vm);
        $guest = $vm->node;
        if (! $guest instanceof Node || $vm->provider !== TaskVmProviderKind::Incus || $vm->host_node_id === null) {
            throw new ResourceOperationException('task_vm.fleet_build_refused', 'Building a fleet needs an Incus VM and its guest Node.', 409);
        }

        return $this->build($vm, $guest, $this->club(), $workspace);
    }

    /** The checkout of the workspace of the VM's group: the checkout the build reads on the VM, and the one its nested guests mount. */
    private function workspace(TaskVm $vm): string
    {
        $path = $vm->group_id === null ? null : Instance::query()->where('task_workspace_owner_id', $vm->group_id)->where('node_id', $vm->node_id)->value('checkout_path');
        if (! is_string($path) || $path === '') {
            throw new ResourceOperationException('task_vm.fleet_build_refused', 'Building a fleet needs the checkout of the workspace of the VM.', 409);
        }

        return $path;
    }

    /** The three nested containers may use 10 GiB, more than a medium VM has: the build runs only in a large one, which the admission of the refresh sets. */
    private function assertLarge(TaskVm $vm): void
    {
        if ($vm->size !== TaskVmSize::Large) {
            throw new ResourceOperationException('task_vm.fleet_build_refused', 'Building a fleet needs a large task VM: its nested containers may use more memory than a '.$vm->size->value.' VM has.', 409);
        }
    }

    private function club(): string
    {
        $club = config('swamp.proof_club_api_key');
        if (! is_string($club) || trim($club) === '') {
            throw new ResourceOperationException('task_vm.fleet_build_unavailable', 'Building a fleet needs the proof Swamp Club key.', 409);
        }

        return $club;
    }

    /** @return array<string,mixed> */
    private function build(TaskVm $vm, Node $guest, string $club, string $workspace): array
    {
        return [
            'task_vm_id' => $vm->id, 'node_id' => $vm->host_node_id, 'guest_node_id' => $guest->id,
            'ssh_execution' => $this->ssh->forNode($guest),
            'operator_checkout' => $workspace,
            'fleet' => [...TaskVmReadyCopiesIntent::fleet(), 'bridge' => TaskVmNestedFleet::Bridge, 'proof_network' => TaskVmNestedFleet::Subnet, 'layout' => TaskVmNestedFleet::Layout,
                'checkout' => ['source' => $workspace, ...TaskVmNestedFleet::checkout()]],
            'bootstrap' => ['fragment' => $this->fragment($workspace)],
            // The key the new Gateway's Swamp runs with; it reaches the guest through stdin and is in no copy.
            'execution_secrets' => ['proof_club' => $this->shared->executionCopy('orbit-control-inputs', 'swamp-proof-club-api-key', $club)],
        ];
    }

    /**
     * The part of the bootstrap workflow input that the Gateway knows at admission, as JSON members without their braces: the pinned Swamp
     * release, the agent delivery, the Gateway's address and the checkout that renders its plan. The run adds the machine and the commit.
     */
    private function fragment(string $workspace): string
    {
        $swamp = new RecipeLoader()->load()->tools['swamp']->artifacts['amd64'];
        $static = ['swamp' => ['url' => $swamp->location, 'sha256' => $swamp->digest], ...TaskVmNestedFleet::bootstrap($this->delivery, $workspace)];

        return substr(json_encode($static, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 1, -1);
    }

    /** The VM of an admitted operation, still the one it was admitted for. */
    public function owned(Operation $operation): TaskVm
    {
        $input = $operation->input;
        $vm = TaskVm::query()->findOrFail($operation->target_id);
        if ($operation->kind !== 'task_vm.fleet_build' || $operation->target_type !== 'task_vm' || ($input['task_vm_id'] ?? null) !== $vm->id
            || ($input['node_id'] ?? null) !== $operation->node_id || ($input['guest_node_id'] ?? null) !== $vm->node?->id || ! is_array($input['fleet'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The fleet build no longer belongs to its admitted VM.', 422);
        }

        return $vm;
    }
}
