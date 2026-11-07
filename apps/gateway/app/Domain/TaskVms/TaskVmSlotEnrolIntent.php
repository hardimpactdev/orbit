<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\NodeNetworkConvergeInput;
use App\Domain\Nodes\NodeNetworkInput;
use App\Domain\Nodes\NodeWireGuardHubInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Models\Node;
use App\Models\TaskVm;
use App\Support\ValidatedData;

/**
 * A slot is only a fixed identity that the Gateway keeps (docs/cli/tasks/vm-slot-enrol.mmd): its Node, WireGuard key and hub peer, SSH
 * host key and agent secret. Its enrol makes them once and puts the peer on the hub; each task start writes them onto a new VM.
 */
final readonly class TaskVmSlotEnrolIntent
{
    public function __construct(private TaskVmEnrollmentIntent $enrollment, private TaskVmSlotIdentity $keys, private SwampSshExecutionInput $ssh,
        private SwampCommandStep $shared, private PiConnection $pi) {}

    /**
     * The enrol: the identity's Node and keys, made once, and its peer on the hub.
     *
     * @return array{intent:array<string,mixed>}
     */
    public function input(TaskVm $vm): array
    {
        $vm = $this->keys->ensure($vm);
        $guest = $this->enrollment->slotNode($vm);
        $hub = $this->hub($guest);

        return ['intent' => [
            'hub' => NodeWireGuardHubInput::joining($hub, $guest->id, $guest->name, ValidatedData::string($vm->wireguard_ip)),
            'hub_ssh_execution' => $this->ssh->forNode($hub),
            'public_key' => TaskVmSlotIdentity::wireguardPublicKey(ValidatedData::string($vm->wireguard_private_key)),
            'guest_node_id' => $guest->id,
        ]];
    }

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
        $gateway = Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn ($q) => $q
            ->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->sole();
        $control = Node::query()->findOrFail($this->ssh->identity()['control_node_id']);

        return [
            'wireguard_config' => ValidatedData::string($peer['config']), 'dns_marker' => ValidatedData::string($peer['dns_marker']),
            'ssh_host_public_key' => TaskVmSlotIdentity::sshPublicKey(ValidatedData::string($vm->ssh_host_private_key)),
            'agent_config' => ValidatedData::string($files['agent']['configuration']), 'agent_unit' => ValidatedData::string($files['agent']['unit']),
            'certificate' => ValidatedData::string($files['agent']['certificate']),
            'pi_unit' => $files['unit'], 'pi_unit_name' => $files['unit_name'], 'pi_unit_path' => $files['unit_path'], 'pi_port' => (string) $this->pi->port(),
            'control_ip' => ValidatedData::string($control->wireguard_ip), 'gateway_ip' => ValidatedData::string($gateway->wireguard_ip),
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

    /** The WireGuard hub of the identity's network. */
    private function hub(Node $guest): Node
    {
        $network = app(NodeNetworkInput::class)->capture($guest);

        return Node::query()->findOrFail(ValidatedData::integer($network['hub_id']));
    }
}
