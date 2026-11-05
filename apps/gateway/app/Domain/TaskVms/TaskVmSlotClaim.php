<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\TaskVm;
use App\Support\ValidatedData;

/**
 * The claim of a task VM slot: the full workflow input of the VM step, and the record of its result. A slot already
 * has its Node, address, keys and tokens, so the claim makes none of them and the input holds no secret. The
 * Operation is the task's own `tasks.provision`, which runs the `task-vm-slot-claim` workflow in place of
 * `task-vm-provision`. See docs/cli/tasks/vm-slot-claim.mmd.
 */
final readonly class TaskVmSlotClaim
{
    public function __construct(private TaskVmSettings $settings, private SwampSshExecutionInput $ssh, private PiConnection $pi, private AgentDelivery $delivery) {}

    /**
     * The identity fields of the Operation input, which {@see TaskVmProvisionIntent::owned()} checks, and the claim's own
     * `slot_claim` object, which the workflow reads. The workspace and the git credential come from the Tasks intent.
     *
     * @return array<string,mixed>
     */
    public function capture(TaskVm $vm): array
    {
        $vm->refresh();
        $guest = $vm->node;
        $host = $vm->hostNode;
        if ($vm->state !== TaskVmState::Provisioning || $vm->slot === null || $vm->group_id === null || ! $guest instanceof Node || ! $host instanceof Node
            || $vm->image_fingerprint === null || $vm->recipe_identity === null || $vm->bridge_address === null || $vm->bridge_mac === null
            || ! is_string($guest->ssh_host_key_type) || ! is_string($guest->ssh_host_key)) {
            throw new ResourceOperationException('task_vm.slot_invalid', 'A claim needs a slot that a task holds, with its recorded image and host key.', 409);
        }
        $location = $this->settings->host($host->id);
        $identity = $this->ssh->identity();
        $receipt = ['task_vm_id' => $vm->id, 'guest_node_id' => $guest->id, 'ready' => true,
            'provider' => ['provider_id' => null, 'address' => $vm->bridge_address, 'image_fingerprint' => $vm->image_fingerprint, 'running' => true, 'settled' => true],
            // The workspace steps pin the guest to this key. The slot's key is fixed, so the claim reports the recorded one.
            'enrollment' => ['ready' => true, 'node' => ['ssh_host_key_type' => $guest->ssh_host_key_type, 'ssh_host_key' => $guest->ssh_host_key], 'process' => (object) []]];

        return [
            'placement' => 'slot', 'task_vm_id' => $vm->id, 'node_id' => $host->id, 'host_node_id' => $host->id, 'guest_node_id' => $guest->id,
            'name' => $vm->name, 'provider' => TaskVmProviderKind::Incus->value, 'provider_id' => $vm->provider_id, 'wireguard_ip' => $vm->wireguard_ip,
            'image_fingerprint' => $vm->image_fingerprint, 'recipe_identity' => $vm->recipe_identity,
            'execution_secrets' => (object) [],
            'slot_claim' => [
                'host_node_id' => $host->id, 'host_ssh_execution' => $this->ssh->forNode($host),
                'vm' => ['project' => $location->project, 'pool' => $location->pool, 'name' => $vm->name, 'fingerprint' => $vm->image_fingerprint,
                    'seed_source' => TaskVmSlot::seedSource($vm->image_fingerprint), 'seed_clone' => TaskVmSlot::seedClone($vm->name),
                    'config_json' => TaskVmSlotBoot::config($location, $vm->name, $identity['public_key'], $vm->bridge_address, $vm->bridge_mac)],
                'guest' => ['user' => $guest->user, 'address' => $vm->wireguard_ip, 'identity_file' => $identity['identity_file'], 'host_key' => $guest->ssh_host_key_type.' '.$guest->ssh_host_key,
                    'pi_url' => $this->pi->baseUrl($guest), 'agent_sha256' => $this->delivery->pin($guest),
                    'pi_sha256' => $this->settings->piArtifactSha256 ?? throw new ResourceOperationException('task_vm.invalid_config', 'A slot claim needs the pinned Pi artifact.', 409)],
                // The `task-vm-nested-reset` workflow lands with B3/B4; the step is skipped until `nested_reset` is on.
                'nested_reset' => $this->settings->nestedReset,
                'receipt' => $receipt, 'receipt_json' => json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ],
        ];
    }

    /**
     * Whether the slot's agent has reported since the claim. The claim removed the slot Node's old facts, and an
     * unchanged report is never rewritten, so a `host` fact received after `claimed_at` is the new VM's own.
     */
    public function reports(TaskVm $vm): bool
    {
        return $vm->claimed_at !== null && NodeFact::query()->where('node_id', $vm->node_id)->where('kind', NodeFactKind::Host->value)
            ->where('received_at', '>=', $vm->claimed_at)->exists();
    }

    /**
     * Makes the claimed slot `ready` from the claim's receipt. The Node, its keys and its Process stay as the enrolment
     * recorded them; only the machine is new.
     *
     * @param  array<string,mixed>  $result
     */
    public function record(TaskVm $vm, array $result): void
    {
        $provider = ValidatedData::object($result['provider'] ?? []);
        TaskVm::query()->whereKey($vm->id)->where('state', TaskVmState::Provisioning)->update([
            'state' => TaskVmState::Ready, 'ready_at' => now(), 'error_code' => null, 'error_message' => null,
            ...isset($provider['address']) ? ['address' => $provider['address']] : [],
        ]);
    }
}
