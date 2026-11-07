<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
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
 * The intents of a task start (docs/cli/tasks/provision.mmd) and a release (docs/cli/tasks/cancel.mmd) on one slot identity. A task start
 * makes a new VM from the host's image, writes the slot's fixed identity and the two secrets a task could misuse, a new Pi token and model
 * key, then runs the converge and starts the task branch. A release deletes the VM and revokes the model key; the Pi token goes with the VM.
 */
final readonly class TaskVmSlotRuns
{
    public function __construct(private TaskVmSettings $settings, private ModelKeyIssuer $models, private TaskVmModelKeyInputs $modelInputs,
        private SwampCommandStep $shared, private SwampSshExecutionInput $ssh, private TaskVmSlotWorkspace $workspace,
        private SystemdProcessRenderer $systemd, private StartOperationAction $start, private TaskVmSlotEnrolIntent $identity,
        private TaskVmConvergeInput $converge, private TaskVmPromotedImage $images) {}

    /**
     * Mints the claim's Pi token and model key and renders the intent of a task start from `$snapshot` (the host's recorded base snapshot,
     * or the new one a refresh proves) at `$commit`, with the task branch `$branch`. Call it in the admission transaction.
     *
     * @return array{intent:array<string,mixed>,execution_secrets:array<string,mixed>}
     */
    public function claim(TaskVm $slot, ?string $branch, string $commit, ?string $snapshot = null): array
    {
        $this->models->assertReady();
        $origin = $this->models->origin();
        $slot->update(['pi_token' => bin2hex(random_bytes(32)), 'model_key' => bin2hex(random_bytes(32)), 'ai_router_origin' => $origin]);
        $model = $this->modelInputs->capture($slot, $this->gateway()->id, $this->shared->executionCopy(...));
        $token = ValidatedData::string($slot->pi_token);
        // Every turn of the group reads the token by this key; the start writes it with the model file in one exec.
        $this->shared->executionCopy('orbit-control-inputs', self::piTokenKey($slot), $token);
        $secrets = $token."\n".self::piModelsFile($origin, $this->settings->piModels, ValidatedData::string($slot->model_key));
        $host = $slot->hostNode ?? throw new ResourceOperationException('task_vm.slot_refused', 'A slot needs its host.', 409);
        $location = $this->settings->host($host->id);
        // A refresh proves its new snapshot before the Gateway records it; any other start copies the recorded one.
        $current = $snapshot === null ? $this->images->snapshot($host, $location)
            : ['vm' => TaskVmPromotedImage::baseVm($host), 'seed' => TaskVmPromotedImage::baseSeed($host), 'snapshot' => $snapshot, 'recipe_identity' => null];
        $converge = $this->converge->for($host, $commit, $branch);
        $resources = $slot->size->resources();
        $target = $this->target($slot);

        return [
            'intent' => [
                'host' => $target['host'],
                'slot' => [...$target['slot'], 'base' => ['vm' => $current['vm'], 'seed' => $current['seed'], 'snapshot' => $current['snapshot']], 'cpus' => $resources->cpus, 'memory' => $resources->memory,
                    'pi_home' => '/home/orbit', 'identity' => $this->identity->identity($slot)],
                'model' => $model['intent'],
                'converge' => $converge['converge'],
                // The Pi server is fetched only when the base's recipe is older than the release's, so that the converge installs the new one.
                'with_pi' => $snapshot === null && $current['recipe_identity'] !== $converge['recipe_identity'],
            ],
            'execution_secrets' => [...$this->identity->secrets($slot), 'model' => $model['execution_secrets'],
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
     * The release intent: the VM and its seed clone to delete, and the claim's model key to revoke. The delete takes no secret.
     *
     * @return array{intent:array<string,mixed>,execution_secrets:array<string,mixed>}
     */
    public function release(TaskVm $slot): array
    {
        $model = is_string($slot->model_key) && $slot->ai_router_origin !== null ? $this->modelInputs->capture($slot, $this->gateway()->id, $this->shared->executionCopy(...)) : null;

        return [
            'intent' => [...$this->target($slot), ...$model === null ? [] : ['model' => $model['intent']]],
            'execution_secrets' => $model['execution_secrets'] ?? [],
        ];
    }

    /** The vault key of the claim's Pi token, which each turn of the group reads. */
    public static function piTokenKey(TaskVm $slot): string
    {
        return 'task-vm-pi-token-'.$slot->id;
    }

    /**
     * The Pi model file of a claim: the model router's provider with the claim's model key.
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

    /** @return array{host:array<string,mixed>,slot:array<string,mixed>} the host and the VM, as every slot step names them */
    private function target(TaskVm $slot): array
    {
        $host = $slot->hostNode ?? throw new ResourceOperationException('task_vm.slot_refused', 'A slot needs its host.', 409);
        $node = $slot->node ?? throw new ResourceOperationException('task_vm.slot_refused', 'A slot needs its Node.', 409);
        $location = $this->settings->host($host->id);
        $pi = Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->where('name', 'pi-server')->sole();

        return [
            'host' => ['node_id' => $host->id, 'ssh' => $this->ssh->forNode($host), 'project' => $location->project, 'pool' => $location->pool],
            'slot' => ['name' => $slot->name, 'seed_clone' => $slot->name.'-seed', 'checkout' => $this->workspace->checkoutPath((int) $slot->slot_project_id),
                'pi_unit' => $this->systemd->unitName($pi), 'hub_ip' => ValidatedData::string($this->gateway()->wireguard_ip)],
        ];
    }

    private function gateway(): Node
    {
        return Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn ($q) => $q
            ->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->sole();
    }
}
