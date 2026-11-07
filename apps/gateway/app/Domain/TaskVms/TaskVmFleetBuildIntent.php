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
    /**
     * The nested containers by role: their address and MAC on the nested network, their address on the private network of the fleet, their
     * memory limit, and the TLD an `app-dev` or `app-prod` Node serves. The ready copies store the address and the MAC, so a fleet that is built
     * again must have the same ones: the Gateway's records name them.
     */
    public const array Layout = [
        'gateway' => ['address' => '10.232.2.11', 'hwaddr' => '10:66:6a:5d:00:11', 'wireguard' => '10.45.0.1', 'memory' => '4GiB', 'tld' => null],
        'app-dev' => ['address' => '10.232.2.12', 'hwaddr' => '10:66:6a:5d:00:12', 'wireguard' => '10.45.0.2', 'memory' => '3GiB', 'tld' => 'nested-dev'],
        'app-prod' => ['address' => '10.232.2.13', 'hwaddr' => '10:66:6a:5d:00:13', 'wireguard' => '10.45.0.3', 'memory' => '3GiB', 'tld' => 'nested-prod'],
    ];

    /** The bridge of the nested network: the first address of its subnet. */
    public const string Bridge = '10.232.2.1/24';

    /** Where the guests see the checkout of the outer VM (an Incus disk device), and where their own state lives (stage 2). */
    public const string CheckoutPath = '/home/orbit/orbit';

    public const string StatePath = '/var/lib/orbit-checkout-state';

    /** The state of the checkout on the Gateway guest, over the shared checkout: the application's and the Swamp repository's own. */
    private const array GatewayState = ['apps/gateway/.env', 'apps/gateway/storage', 'apps/gateway/bootstrap/cache', 'swamp/.swamp', 'swamp/vaults', 'swamp/grants'];

    /** The state of the checkout on the app-dev guest. */
    private const array AppDevState = ['apps/gateway/.env', 'apps/gateway/storage', 'apps/gateway/bootstrap/cache'];

    /** The private network of the nested fleet, which its Gateway leads. */
    public const string WireGuardSubnet = '10.45.0.0/24';

    /** The task VM's address in that network, as operator of the fleet (stage 2 joins with it). */
    public const string OperatorAddress = '10.45.0.10';

    public function __construct(private SwampSshExecutionInput $ssh, private AgentDelivery $delivery, private SwampCommandStep $shared) {}

    /** @return array<string,mixed> one flat array that `TaskVmProviderWorkflowInput::envelope()` splits into its `intent` and its `execution_secrets` */
    public function capture(TaskVm $vm): array
    {
        $this->assertLarge($vm);
        $guest = $vm->node;
        if ($vm->state !== TaskVmState::Ready || $vm->host_node_id === null || ! $guest instanceof Node
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
        if (! $guest instanceof Node || $vm->host_node_id === null) {
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
            'fleet' => [...TaskVmReadyCopiesIntent::fleet(), 'bridge' => self::Bridge, 'proof_network' => TaskVmReadyCopiesIntent::Subnet, 'layout' => self::Layout, 'checkout' => $this->checkout($workspace)],
            'bootstrap' => ['fragment' => $this->fragment($workspace)],
            // The key the new Gateway's Swamp runs with; it reaches the guest through stdin and is in no copy.
            'execution_secrets' => ['proof_club' => $this->shared->executionCopy('orbit-control-inputs', 'swamp-proof-club-api-key', $club)],
        ];
    }

    /**
     * The checkout of the outer VM and the mount units of the guests' own state. The checkout is an Incus disk device of the Gateway and
     * app-dev containers (`source` to `path`); the build attaches it after cloud-init made the `orbit` account. A bind mount of each piece
     * of state over it is a stock systemd unit, named as systemd names it for `/home/orbit/orbit/<piece>`, which the stage 2 apply step starts.
     *
     * @return array{source:string,path:string,state:string,units:list<array{guest:string,name:string,content:string}>,binds:array<string,list<string>>}
     */
    private function checkout(string $workspace): array
    {
        $units = [];
        $binds = [];
        foreach (['gateway' => self::GatewayState, 'app-dev' => self::AppDevState] as $role => $state) {
            $guest = TaskVmReadyCopiesIntent::Guests[$role];
            $binds[$role] = [];
            foreach ($state as $relative) {
                $name = self::unitName(self::CheckoutPath.'/'.$relative);
                $binds[$role][] = $name;
                $units[] = ['guest' => $guest, 'name' => $name, 'content' => implode("\n", [
                    '[Unit]', "Description=Orbit state {$relative}", 'RequiresMountsFor='.self::CheckoutPath, '',
                    '[Mount]', 'What='.self::StatePath.'/'.$relative, 'Where='.self::CheckoutPath.'/'.$relative, 'Type=none', 'Options=bind', '',
                    '[Install]', 'WantedBy=multi-user.target', '',
                ])];
            }
        }

        return ['source' => $workspace, 'path' => self::CheckoutPath, 'state' => self::StatePath, 'units' => $units, 'binds' => $binds];
    }

    /** The name of the mount unit of a path, as `systemd-escape --path --suffix=mount` writes it for these paths (no dash in a part, no escaped byte). */
    private static function unitName(string $path): string
    {
        return str_replace('/', '-', ltrim($path, '/')).'.mount';
    }

    /**
     * The part of the bootstrap workflow input that the Gateway knows at admission, as JSON members without their braces: the pinned Swamp
     * release, the agent delivery, the Gateway's address and the checkout that renders its plan. The run adds the machine and the commit.
     */
    private function fragment(string $workspace): string
    {
        $swamp = new RecipeLoader()->load()->tools['swamp']->artifacts['amd64'];
        $static = [
            'swamp' => ['url' => $swamp->location, 'sha256' => $swamp->digest],
            // The package cache of this VM for the apt runs of the nested Gateway, named the way every nested Node gets it.
            'apt_proxy' => TaskVmAptCache::forAddress(self::Layout['gateway']['address']),
            'gateway' => ['url' => 'https://'.self::Layout['gateway']['wireguard'], 'wireguard_ip' => self::Layout['gateway']['wireguard'],
                'wireguard_subnet' => self::WireGuardSubnet],
            'operator' => ['checkout' => $workspace, 'wireguard_ip' => self::OperatorAddress],
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
