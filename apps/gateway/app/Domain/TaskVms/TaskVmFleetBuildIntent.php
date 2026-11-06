<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;

/**
 * Renders the fleet build (docs/cli/tasks/vm-fleet-build.mmd) as the full workflow input: the VM that runs the nested fleet, with
 * its SSH identity and host key pin, the fixed layout of the nested VMs and their network, and the public pins the new Gateway is
 * prepared with. No secret and no host effects.
 */
final readonly class TaskVmFleetBuildIntent
{
    /**
     * The nested VMs by role: their address and MAC on the nested network, their address on the private network of the fleet, their
     * memory, and the TLD an `app-dev` or `app-prod` Node serves. The ready copies store the address and the MAC, so a fleet that is built
     * again must have the same ones: the Gateway's records name them.
     */
    public const array Layout = [
        'gateway' => ['address' => '10.232.2.11', 'hwaddr' => '10:66:6a:5d:00:11', 'wireguard' => '10.45.0.1', 'memory' => '4GiB', 'tld' => null],
        'app-dev' => ['address' => '10.232.2.12', 'hwaddr' => '10:66:6a:5d:00:12', 'wireguard' => '10.45.0.2', 'memory' => '3GiB', 'tld' => 'nested-dev'],
        'app-prod' => ['address' => '10.232.2.13', 'hwaddr' => '10:66:6a:5d:00:13', 'wireguard' => '10.45.0.3', 'memory' => '3GiB', 'tld' => 'nested-prod'],
    ];

    /** The bridge of the nested network: the first address of its subnet. */
    public const string Bridge = '10.232.2.1/24';

    /** The private network of the nested fleet, which its Gateway leads. */
    public const string WireGuardSubnet = '10.45.0.0/24';

    public function __construct(private SwampSshExecutionInput $ssh, private AgentDelivery $delivery) {}

    /** @return array<string,mixed> one flat array that `TaskVmProviderWorkflowInput::envelope()` splits into its `intent` and its `execution_secrets` */
    public function capture(TaskVm $vm): array
    {
        $guest = $vm->node;
        if ($vm->state !== TaskVmState::Ready || $vm->provider !== TaskVmProviderKind::Incus || $vm->host_node_id === null || ! $guest instanceof Node
            || $guest->wireguard_ip !== $vm->wireguard_ip || $guest->status !== LifecycleStatus::Active || $guest->ssh_host_key === null) {
            throw new ResourceOperationException('task_vm.fleet_build_refused', 'Building a fleet needs a ready Incus VM and its active guest identity with its recorded host key.', 409);
        }

        return [
            'task_vm_id' => $vm->id, 'node_id' => $vm->host_node_id, 'guest_node_id' => $guest->id,
            'ssh_execution' => $this->ssh->forNode($guest),
            'fleet' => [...TaskVmReadyCopiesIntent::fleet(), 'bridge' => self::Bridge, 'layout' => self::Layout],
            'bootstrap' => ['fragment' => $this->fragment()],
            'execution_secrets' => [],
        ];
    }

    /**
     * The part of the bootstrap workflow input that the Gateway knows at admission, as JSON members without their braces: the pinned Swamp
     * release, the agent delivery, the Gateway's address and the checkout that renders its plan. The run adds the machine and the commit.
     */
    private function fragment(): string
    {
        $swamp = new RecipeLoader()->load()->tools['swamp']->artifacts['amd64'];
        $static = [
            'swamp' => ['url' => $swamp->location, 'sha256' => $swamp->digest],
            'gateway' => ['url' => 'https://'.self::Layout['gateway']['wireguard'], 'wireguard_ip' => self::Layout['gateway']['wireguard'],
                'wireguard_subnet' => self::WireGuardSubnet],
            'operator' => ['checkout' => '/home/orbit/orbit'],
            'agent_delivery' => json_encode(['commit' => $this->delivery->commit(), 'version' => $this->delivery->version(), 'artifacts' => $this->delivery->artifacts()],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];

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
