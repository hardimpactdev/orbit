<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * The intents of a task start (docs/cli/tasks/provision.mmd) and a release (docs/cli/tasks/cancel.mmd) in one slot. A task start copies the
 * host's base VM snapshot, writes the identity the admission minted, a new Pi token and the Pi models file with the fixed model key, and
 * adds the hub peer beside the boot; then it runs the converge and starts the task branch. A release deletes the VM and removes the hub
 * peer; the Gateway then deletes the Node. Neither calls the model router.
 */
final readonly class TaskVmSlotRuns
{
    public function __construct(private TaskVmSettings $settings, private TaskVmModelRoute $route,
        private SwampCommandStep $shared, private SwampSshExecutionInput $ssh, private TaskVmSlotWorkspace $workspace,
        private SystemdProcessRenderer $systemd, private StartOperationAction $start, private TaskVmIdentity $identity,
        private TaskVmConvergeInput $converge, private TaskVmPromotedImage $images) {}

    /**
     * Mints the claim's Pi token and renders the intent of a task start from `$snapshot` (the host's recorded base snapshot,
     * or the new one a refresh proves) at `$commit`, with the task branch `$branch`. Call it in the admission transaction.
     *
     * @return array{intent:array<string,mixed>,execution_secrets:array<string,mixed>}
     */
    public function claim(TaskVm $slot, ?string $branch, string $commit, ?string $snapshot = null): array
    {
        $this->route->assertReady();
        $slot->update(['pi_token' => bin2hex(random_bytes(32))]);
        $token = ValidatedData::string($slot->pi_token);
        // Every turn of the group reads the token by this key; the start writes it with the model file in one exec. The model key is the one
        // fixed placeholder key of the guard, the same for every VM.
        $this->shared->executionCopy('orbit-control-inputs', self::piTokenKey($slot), $token);
        $secrets = $token."\n".self::piModelsFile($this->route->origin(), $this->settings->piModels, $this->route->key());
        $host = $slot->hostNode ?? throw new ResourceOperationException('task_vm.slot_refused', 'A slot needs its host.', 409);
        $location = $this->settings->host($host->id);
        // A refresh proves its new snapshot before the Gateway records it; any other start copies the recorded one.
        $current = $snapshot === null ? $this->images->snapshot($host, $location)
            : ['vm' => TaskVmPromotedImage::baseVm($host), 'seed' => TaskVmPromotedImage::baseSeed($host), 'snapshot' => $snapshot, 'recipe_identity' => null];
        $converge = $this->converge->for($host, $commit, $branch, $slot->node);
        $resources = $slot->size->resources();
        $target = $this->target($slot);

        return [
            'intent' => [
                'host' => $target['host'],
                'slot' => [...$target['slot'], 'base' => ['vm' => $current['vm'], 'seed' => $current['seed'], 'snapshot' => $current['snapshot']], 'cpus' => $resources->cpus, 'memory' => $resources->memory,
                    'pi_home' => '/home/orbit', 'identity' => $this->identity->identity($slot)],
                // The VM's peer on the WireGuard hub, added while the VM boots.
                'hub_peer' => $this->identity->hubPeer($slot),
                // How every later step reaches the VM: ssh from a Swamp worker over WireGuard, with its pinned host key. Readiness proves it.
                'guest_ssh' => $this->ssh->forNode($slot->node ?? throw new ResourceOperationException('task_vm.slot_refused', 'A slot needs its Node.', 409)),
                'converge' => $converge['converge'],
                // The Pi server is fetched only when the base's recipe is older than the release's, so that the converge installs the new one.
                'with_pi' => $snapshot === null && $current['recipe_identity'] !== $converge['recipe_identity'],
            ],
            // One flat map of vault references, as every Operation's secrets are.
            'execution_secrets' => [...$this->identity->secrets($slot),
                'claim' => $this->shared->executionCopy('orbit-control-inputs', 'task-vm-pi-claim-'.$slot->id, $secrets)],
        ];
    }

    /** The release as its own run, for a task start that failed or a group that ended without a cancel run. */
    public function startRelease(TaskVm $slot, string $caller): Operation
    {
        return DB::transaction(function () use ($slot, $caller): Operation {
            $slot->update(['state' => TaskVmState::Releasing]);

            return $this->start->execute(TaskVmSlotPool::ReleaseKind, 'task_vm', $slot->id, (int) $slot->host_node_id, $this->release($slot), $caller, null);
        });
    }

    /**
     * The release intent: the VM and its seed clone to delete and its hub peer to remove. The delete takes no secret. The Gateway deletes the
     * Node record when the run passed.
     *
     * @return array{intent:array<string,mixed>,execution_secrets:array<string,mixed>}
     */
    public function release(TaskVm $slot): array
    {
        // A release needs only what it deletes: the VM, its seed clone and its identity volume by name, on its host. It deletes by name, so
        // a name that is the host's base or its seed, or that another live row of the host holds, is refused before anything runs.
        $host = $this->host($slot);
        $names = [$slot->name, self::seedClone($slot)];
        $node = $slot->hostNode ?? throw new ResourceOperationException('task_vm.slot_refused', 'A slot needs its host.', 409);
        $taken = $this->images->baseNames($node, $this->settings->host($node->id));
        foreach (TaskVm::query()->live()->where('host_node_id', $node->id)->whereKeyNot($slot->id)->get() as $other) {
            array_push($taken, $other->name, self::seedClone($other));
        }
        if (array_intersect($names, $taken) !== []) {
            throw new ResourceOperationException('task_vm.release_refused', "The release of task VM [{$slot->name}] would delete the base or another live task VM of [{$node->name}].", 409,
                details: ['task_vm_id' => $slot->id, 'names' => array_values(array_intersect($names, $taken))]);
        }

        return ['intent' => ['host' => $host, 'slot' => ['name' => $slot->name, 'seed_clone' => $names[1]],
            'hub_peer' => $this->identity->hubPeer($slot)], 'execution_secrets' => []];
    }

    /** A start's seed clone is `<name>-seed`; a VM of the old pool, which has no slot number, has `<name>-topology-seed`. */
    public static function seedClone(TaskVm $vm): string
    {
        return $vm->name.($vm->slot_number === null ? '-topology-seed' : '-seed');
    }

    /** The vault key of the claim's Pi token, which each turn of the group reads. */
    public static function piTokenKey(TaskVm $slot): string
    {
        return 'task-vm-pi-token-'.$slot->id;
    }

    /**
     * The Pi model file of a claim: the model router guard's provider with the fixed model key.
     *
     * @param  list<string>  $models
     */
    public static function piModelsFile(string $origin, array $models, #[\SensitiveParameter] string $modelKey): string
    {
        return json_encode(['providers' => ['orbit-task-vm' => [
            'baseUrl' => rtrim($origin, '/').'/v1', 'api' => 'openai-responses', 'apiKey' => $modelKey,
            'models' => array_map(static fn (string $id): array => ['id' => $id, 'reasoning' => true], $models)]]],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /** @return array{node_id:int,ssh:array<string,mixed>,project:string,pool:string} */
    private function host(TaskVm $slot): array
    {
        $host = $slot->hostNode ?? throw new ResourceOperationException('task_vm.slot_refused', 'A slot needs its host.', 409);
        $location = $this->settings->host($host->id);

        return ['node_id' => $host->id, 'ssh' => $this->ssh->forNode($host), 'project' => $location->project, 'pool' => $location->pool];
    }

    /** @return array{host:array<string,mixed>,slot:array<string,mixed>} the host and the VM, as every slot step names them */
    private function target(TaskVm $slot): array
    {
        $node = $slot->node ?? throw new ResourceOperationException('task_vm.slot_refused', 'A slot needs its Node.', 409);
        $pi = Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->where('name', 'pi-server')->sole();

        return [
            'host' => $this->host($slot),
            'slot' => ['name' => $slot->name, 'seed_clone' => self::seedClone($slot), 'checkout' => $this->workspace->checkoutPath((int) $slot->slot_project_id),
                'pi_unit' => $this->systemd->unitName($pi)],
        ];
    }
}
