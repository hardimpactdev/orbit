<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Actions\TaskVms\StartTaskVmResumeAction;
use App\Domain\Operations\OperationStatus;
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
 * The intents of a task start (docs/cli/task/start.mmd) and a release (docs/cli/task/cancel.mmd) in one slot. A task start copies the
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
        $host = $slot->hostNode ?? throw new ResourceOperationException('task_vm.slot_refused', 'A task VM needs its host.', 409);
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
                'guest_ssh' => $this->ssh->forNode($slot->node ?? throw new ResourceOperationException('task_vm.slot_refused', 'A task VM needs its Node.', 409)),
                'converge' => $converge['converge'],
                // The Pi server is fetched only when the base's recipe is older than the release's, so that the converge installs the new one.
                'with_pi' => $snapshot === null && $current['recipe_identity'] !== $converge['recipe_identity'],
            ],
            // One flat map of vault references, as every Operation's secrets are.
            'execution_secrets' => [...$this->identity->secrets($slot),
                'claim' => $this->shared->executionCopy('orbit-control-inputs', 'task-vm-pi-claim-'.$slot->id, $secrets)],
        ];
    }

    /** The release as its own run, for a task start that failed or a group that ended without a cancel run. An open release is returned as it is. */
    public function startRelease(TaskVm $slot, string $caller): Operation
    {
        return DB::transaction(function () use ($slot, $caller): Operation {
            $slot = TaskVm::query()->lockForUpdate()->findOrFail($slot->id);
            $open = Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->where('target_type', 'task_vm')->where('target_id', $slot->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->first();

            return $open ?? $this->start->execute(TaskVmSlotPool::ReleaseKind, 'task_vm', $slot->id, (int) $slot->host_node_id, $this->reserveRelease($slot), $caller, null);
        });
    }

    /**
     * The one admission of a release, for its own run and for a cancel run: it locks and re-reads the VM, refuses while any Operation is open
     * on it, such as a resume that boots it, and marks it releasing, so no later resume starts it ({@see StartTaskVmResumeAction}). Call it in
     * the transaction that admits the release.
     *
     * @return array{intent:array<string,mixed>,execution_secrets:array<string,mixed>}
     */
    public function reserveRelease(TaskVm $slot): array
    {
        $slot = TaskVm::query()->lockForUpdate()->findOrFail($slot->id);
        self::assertReleasable($slot);
        $release = $this->release($slot);
        $slot->update(['state' => TaskVmState::Releasing]);

        return $release;
    }

    /** Refuses with `operation.target_busy` while an Operation, a release or a resume, is open on the VM. Reads without a lock. */
    public static function assertReleasable(TaskVm $slot): void
    {
        $open = Operation::query()->where('target_type', 'task_vm')->where('target_id', $slot->id)
            ->whereNotIn('status', OperationStatus::terminalValues())->first();
        if ($open !== null) {
            throw new ResourceOperationException('operation.target_busy', "The {$open->kind} Operation of task VM [{$slot->name}] is still running; try again when it ends.", 409,
                details: ['operation_id' => $open->id]);
        }
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
        // it deletes only a task VM's name: `<host>-tvm-<n>` of slot n, under the host name it had then, or `tvm-<id>` of the old pool. A
        // name of another shape, such as a base or a seed, refuses. Names are unique, so no task VM name is another VM's or its seed.
        if (! TaskVmName::accepts($slot)) {
            throw new ResourceOperationException('task_vm.release_refused', "Task VM [{$slot->name}] has no task VM name (<host>-tvm-<place> or tvm-<id>), so no release deletes it.", 409,
                details: ['task_vm_id' => $slot->id]);
        }

        return ['intent' => ['host' => $this->host($slot), 'slot' => ['name' => $slot->name, 'seed_clone' => self::seedClone($slot)],
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
        $host = $slot->hostNode ?? throw new ResourceOperationException('task_vm.slot_refused', 'A task VM needs its host.', 409);
        $location = $this->settings->host($host->id);

        return ['node_id' => $host->id, 'ssh' => $this->ssh->forNode($host), 'project' => $location->project, 'pool' => $location->pool];
    }

    /** @return array{host:array<string,mixed>,slot:array<string,mixed>} the host and the VM, as every slot step names them */
    private function target(TaskVm $slot): array
    {
        $node = $slot->node ?? throw new ResourceOperationException('task_vm.slot_refused', 'A task VM needs its Node.', 409);
        $pi = Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->where('name', 'pi-server')->sole();

        return [
            'host' => $this->host($slot),
            'slot' => ['name' => $slot->name, 'seed_clone' => self::seedClone($slot), 'checkout' => $this->workspace->checkoutPath((int) $slot->slot_project_id),
                'pi_unit' => $this->systemd->unitName($pi)],
        ];
    }
}
