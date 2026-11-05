<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\TaskVms\RecordTaskVmAction;
use App\Domain\Nodes\NodeNetworkConvergeInput;
use App\Domain\Nodes\NodeNetworkInput;
use App\Domain\Nodes\NodeWireGuardHubInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\WireGuardAddressAllocator;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Models\Node;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;
use Illuminate\Support\Str;

/**
 * Records one slot of a task VM host and renders its enrolment as the full workflow input: the Pi token, the model
 * key and the router credentials as vault names, and every other fact as public text. No host effects and no child
 * Operations. See docs/cli/tasks/vm-slot-enroll.mmd.
 */
final readonly class TaskVmSlotEnrollIntent
{
    public function __construct(private TaskVmSettings $settings, private TaskVmEnrollmentIntent $enrollment, private ModelKeyIssuer $keys,
        private TaskVmModelKeyInputs $models, private WireGuardAddressAllocator $addresses, private SwampSshExecutionInput $ssh,
        private SwampCommandStep $shared, private AgentDelivery $delivery, private PiConnection $pi, private NodeNetworkInput $network, private NodeAgentSshExecutor $agents) {}

    /**
     * The slot's row, its Node, its `app-dev` role and its `pi-server` Process. A slot that is still enrolling, or
     * failed, is recorded again with what it has: a rerun keeps the address, the Node and the secrets. Call it under
     * the admission fence and a transaction.
     */
    public function reserve(Node $host, int $slot): TaskVm
    {
        if (! $this->settings->enabled || ! $this->settings->hasHost($host->id) || $host->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('task_vm.host_unavailable', 'A slot needs an active, configured task VM host.', 409);
        }
        $location = $this->settings->host($host->id);
        $name = TaskVmSlot::name($host->name, $slot);
        $vm = TaskVm::query()->lockForUpdate()->where('host_node_id', $host->id)->where('slot', $slot)->first();
        if ($vm instanceof TaskVm && ! in_array($vm->state, [TaskVmState::Provisioning, TaskVmState::Failed], true)) {
            throw new ResourceOperationException('task_vm.slot_exists', "Slot [{$name}] is enrolled already.", 409);
        }
        if ($vm === null) {
            if (TaskVm::query()->where('name', $name)->exists()) {
                throw new ResourceOperationException('task_vm.slot_exists', "A task VM named [{$name}] exists.", 409);
            }
            $this->keys->assertReady();
            $vm = TaskVm::query()->create([
                'host_node_id' => $host->id, 'provider' => TaskVmProviderKind::Incus, 'name' => $name, 'state' => TaskVmState::Provisioning,
                'slot' => $slot, 'address' => TaskVmSlot::bridgeAddress($location, $slot), 'bridge_address' => TaskVmSlot::bridgeAddress($location, $slot),
                'bridge_mac' => TaskVmSlot::bridgeMac($name),
                'wireguard_ip' => $this->addresses->nextIn($this->settings->wireguardRange,
                    array_values(TaskVm::query()->live()->get()->map(static fn (TaskVm $row): string => $row->wireguard_ip)->all())),
                'pi_token' => RecordTaskVmAction::piToken(), 'model_key' => bin2hex(random_bytes(32)), 'ai_router_origin' => $this->keys->origin(),
            ]);
        } elseif ($vm->state === TaskVmState::Failed) {
            $vm->update(['state' => TaskVmState::Provisioning, 'error_code' => null, 'error_message' => null]);
        }
        $this->enrollment->reserve($vm);

        return $vm->refresh();
    }

    /**
     * The workflow input of the enrolment, as one flat array that `TaskVmProviderWorkflowInput::envelope()` splits into
     * its `intent` and its `execution_secrets`.
     *
     * @return array<string,mixed>
     */
    public function capture(TaskVm $vm): array
    {
        $vm->refresh();
        $guest = $vm->node;
        $host = $vm->hostNode;
        if ($vm->state !== TaskVmState::Provisioning || $vm->slot === null || $vm->group_id !== null || ! $guest instanceof Node || ! $host instanceof Node
            || $vm->pi_token === null || $vm->bridge_address === null || $vm->bridge_mac === null) {
            throw new ResourceOperationException('task_vm.slot_invalid', 'Enrolment needs a slot that is recording its first identity.', 409);
        }
        $location = $this->settings->host($host->id);
        $gateway = Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn ($q) => $q
            ->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->sole();
        $model = $this->models->capture($vm, $gateway->id, $this->shared->executionCopy(...));
        $network = $this->network->capturePublic($guest);
        $hub = Node::query()->findOrFail(ValidatedData::integer($network['hub_id']));
        $files = TaskVmSlotIdentity::files(NodeNetworkConvergeInput::peerFiles(ValidatedData::object($network['peer'])),
            ValidatedData::string($model['intent']['model_origin']), $this->settings->piModels,
            $this->agents->configurationForRoot($guest, null), ValidatedData::string($vm->wireguard_ip), $this->pi->port());
        $image = $this->image($host, $location);
        $identity = $this->ssh->identity();

        return [
            'task_vm_id' => $vm->id, 'node_id' => $host->id, 'host_node_id' => $host->id, 'guest_node_id' => $guest->id,
            'slot' => $vm->slot, 'name' => $vm->name, 'wireguard_ip' => $vm->wireguard_ip,
            'bridge_address' => $vm->bridge_address, 'bridge_mac' => $vm->bridge_mac,
            'gateway_node_id' => $gateway->id, 'model_router_node_id' => $model['intent']['model_router_node_id'], 'model_origin' => $model['intent']['model_origin'],
            'host_ssh_execution' => $this->ssh->forNode($host),
            // The slot's peer file on the hub, with a mark where the run puts the public key the identity phase prints.
            'hub' => NodeWireGuardHubInput::joining($hub, $guest->id, $guest->name, ValidatedData::string($vm->wireguard_ip)),
            'identity' => ['project' => $location->project, 'pool' => $location->pool, 'volume' => TaskVmSlot::identityVolume($vm->name),
                'files' => $files, 'revision' => TaskVmSlotIdentity::revision($vm->name, $files), 'model_key_mark' => TaskVmSlotIdentity::ModelKeyMark],
            'vm' => ['project' => $location->project, 'pool' => $location->pool, 'name' => $vm->name,
                'fingerprint' => $image['fingerprint'], 'recipe_identity' => $image['recipe_identity'],
                'seed_source' => TaskVmSlot::seedSource($image['fingerprint']), 'seed_clone' => TaskVmSlot::seedClone($vm->name),
                'config_json' => TaskVmSlotBoot::config($location, $vm->name, $identity['public_key'], $vm->bridge_address, $vm->bridge_mac)],
            'guest' => ['user' => $guest->user, 'address' => $vm->wireguard_ip, 'identity_file' => $identity['identity_file'],
                'pi_url' => $this->pi->baseUrl($guest), 'agent_sha256' => $this->delivery->pin($guest),
                'pi_sha256' => $this->settings->piArtifactSha256 ?? throw new ResourceOperationException('task_vm.invalid_config', 'A slot needs the pinned Pi artifact.', 409)],
            'execution_secrets' => [...$model['execution_secrets'],
                'pi_token' => $this->shared->executionCopy('orbit-control-inputs', 'task-vm-pi-token-'.$vm->id, $vm->pi_token)],
        ];
    }

    /**
     * The host's latest promoted image: a proof VM needs a bootable task image, not the current recipe.
     *
     * @return array{fingerprint:string,recipe_identity:string}
     */
    private function image(Node $host, TaskVmHost $location): array
    {
        $build = TaskVmImageBuild::query()->where('host_node_id', $host->id)->where('state', TaskVmImageBuildState::Succeeded)
            ->whereNotNull('promoted_at')->orderByDesc('promoted_at')->latest('id')->first();
        $base = $build?->images['base'] ?? null;
        if (! $build instanceof TaskVmImageBuild || $base === null || $base['project'] !== $location->project || $base['type'] !== 'virtual-machine'
            || ! Str::of($base['fingerprint'])->isMatch('/\A[a-f0-9]{12,64}\z/D')) {
            throw new ResourceOperationException('task_vm.image_unavailable', 'The host has no promoted task image for a proof VM.', 409);
        }

        return ['fingerprint' => $base['fingerprint'], 'recipe_identity' => (string) $build->recipe_identity];
    }
}
