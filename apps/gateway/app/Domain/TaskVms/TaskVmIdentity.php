<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\NodeNetworkConvergeInput;
use App\Domain\Nodes\NodeNetworkInput;
use App\Domain\Nodes\NodeWireGuardHubInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\TaskVm;
use App\Support\ValidatedData;

/**
 * The identity of one task VM (docs/cli/tasks/provision.mmd), minted at its task start and removed at its release: its Node, WireGuard
 * key and hub peer, SSH host key and agent secret. A task start writes it onto the VM it makes and adds the hub peer beside the boot.
 */
final readonly class TaskVmIdentity
{
    public function __construct(private TaskVmEnrollmentIntent $enrollment, private SwampSshExecutionInput $ssh,
        private SwampCommandStep $shared) {}

    /**
     * The public files of the identity that a task start writes onto its new VM; the private keys go as vault copies ({@see self::secrets()}).
     *
     * @return array<string,string>
     */
    public function identity(TaskVm $vm): array
    {
        $guest = $vm->node ?? throw new ResourceOperationException('task_vm.slot_refused', 'A slot needs its Node.', 409);
        $files = $this->enrollment->slotFiles($guest);
        $peer = NodeNetworkConvergeInput::peerFiles(ValidatedData::object(app(NodeNetworkInput::class)->capture($guest)['peer']));

        return [
            'wireguard_config' => ValidatedData::string($peer['config']), 'dns_marker' => ValidatedData::string($peer['dns_marker']),
            'ssh_host_public_key' => TaskVmSlotIdentity::sshPublicKey(ValidatedData::string($vm->ssh_host_private_key)),
            'agent_config' => ValidatedData::string($files['agent']['configuration']), 'agent_unit' => ValidatedData::string($files['agent']['unit']),
            'pi_unit' => $files['unit'], 'pi_unit_name' => $files['unit_name'], 'pi_unit_path' => $files['unit_path'],
        ];
    }

    /**
     * The vault copies of the identity's private keys, which a task start writes onto its new VM. The VM has the proof Swamp Club key
     * from the base it copies.
     *
     * @return array<string,array{vault:string,key:string}>
     */
    public function secrets(TaskVm $vm): array
    {
        return [
            'wireguard' => $this->shared->executionCopy('orbit-control-inputs', 'task-vm-slot-wireguard-'.$vm->id, ValidatedData::string($vm->wireguard_private_key)),
            'ssh_host' => $this->shared->executionCopy('orbit-control-inputs', 'task-vm-slot-ssh-host-'.$vm->id, ValidatedData::string($vm->ssh_host_private_key)),
            'agent_secret' => $this->shared->executionCopy('orbit-control-inputs', 'task-vm-slot-agent-'.$vm->id, ValidatedData::string($vm->agent_secret)),
        ];
    }

    /**
     * The identity's peer on the WireGuard hub: the task start adds it, the release removes it.
     *
     * @return array{hub:array<string,mixed>,hub_ssh_execution:array<string,mixed>,public_key:string,guest_node_id:int}
     */
    public function hubPeer(TaskVm $vm): array
    {
        $guest = $vm->node ?? throw new ResourceOperationException('task_vm.slot_refused', 'A task VM needs its Node.', 409);
        $hub = $this->hub();

        return ['hub' => NodeWireGuardHubInput::joining($hub, $guest->id, $guest->name, ValidatedData::string($vm->wireguard_ip)),
            'hub_ssh_execution' => $this->ssh->forNode($hub),
            'public_key' => TaskVmSlotIdentity::wireguardPublicKey(ValidatedData::string($vm->wireguard_private_key)), 'guest_node_id' => $guest->id];
    }

    /** The WireGuard hub: the Node with the `vpn` role. */
    private function hub(): Node
    {
        return Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Vpn))->sole();
    }
}
