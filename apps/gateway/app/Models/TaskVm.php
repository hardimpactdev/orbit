<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tasks\TaskTopology;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmPublishState;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\TaskVmTopologyState;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One disposable VM that serves one task group as a normal `app-dev` Node. An `incus` row runs on
 * its host Node. An `upcloud` row has no host Node: `provider_id` is its server's UUID, and `ssh_host_key` the host
 * key staged by its native provider workflow.
 *
 * @property int $id
 * @property int|null $group_id
 * @property int|null $slot_project_id the Project whose checkout the base VM carries, on a row that takes a slot
 * @property int|null $host_node_id
 * @property int|null $node_id
 * @property TaskVmProviderKind $provider
 * @property TaskVmSize $size
 * @property TaskTopology $topology
 * @property TaskVmTopologyState|null $topology_state
 * @property string|null $topology_run
 * @property Carbon|null $topology_started_at
 * @property string|null $topology_error
 * @property string|null $publish_commit
 * @property string|null $publish_branch
 * @property TaskVmPublishState|null $publish_state
 * @property string|null $publish_error
 * @property string|null $publish_error_code
 * @property Carbon|null $publish_queued_at
 * @property Carbon|null $publish_started_at
 * @property string|null $provider_id
 * @property array{private:string,public:string}|null $provider_bootstrap
 * @property string|null $ssh_host_key
 * @property string $name
 * @property TaskVmState $state
 * @property string|null $address
 * @property string $wireguard_ip
 * @property string|null $pi_token
 * @property string|null $model_key
 * @property int|null $slot_number the slot the task VM takes on its host, `<host>-tvm-<number>`
 * @property string|null $wireguard_private_key the WireGuard key a task start minted (docs/cli/tasks/provision.mmd)
 * @property string|null $ssh_host_private_key the SSH host key a task start minted, an OpenSSH Ed25519 file
 * @property string|null $agent_secret the agent secret a task start minted
 * @property bool $worker_stop_required
 * @property string|null $ai_router_origin
 * @property Carbon|null $claimed_at
 * @property Carbon|null $warmed_at
 * @property string|null $image_fingerprint
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $recipe_identity the recipe of the image the VM got, which is older than the current recipe on a fallback
 * @property int|null $image_build_id the promoted image build the VM got
 * @property bool $image_fallback whether the VM got the host's last promoted image instead of the current recipe's
 * @property Carbon|null $ready_at when the VM last became `ready`: its first preparation, or its last resume
 * @property Carbon|null $stop_confirmed_at
 * @property Carbon|null $destroyed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Task|null $group
 * @property-read Node|null $hostNode
 * @property-read Node|null $node
 */
final class TaskVm extends Model
{
    /**
     * The columns that forget the VM's nested topology. Its test machines stop when the VM stops, so a park and a
     * resume both clear it, and the next subtask with a topology prepares it again.
     */
    public const array ForgetTopology = ['topology' => null, 'topology_state' => null, 'topology_run' => null, 'topology_started_at' => null, 'topology_error' => null];

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'group_id', 'slot_project_id', 'host_node_id', 'node_id', 'provider', 'size', 'topology', 'topology_state', 'topology_run', 'topology_started_at', 'topology_error',
        'publish_commit', 'publish_branch', 'publish_state', 'publish_error', 'publish_error_code', 'publish_queued_at', 'publish_started_at', 'provider_id', 'provider_bootstrap', 'ssh_host_key', 'name', 'state', 'address', 'wireguard_ip',
        'pi_token', 'model_key', 'worker_stop_required', 'ai_router_origin', 'claimed_at', 'warmed_at', 'image_fingerprint', 'recipe_identity', 'image_build_id', 'image_fallback',
        'error_code', 'error_message', 'ready_at', 'destroyed_at', 'stop_confirmed_at', 'slot_number', 'wireguard_private_key', 'ssh_host_private_key', 'agent_secret',
    ];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['pi_token', 'model_key', 'provider_bootstrap', 'wireguard_private_key', 'ssh_host_private_key', 'agent_secret'];

    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = ['size' => 'normal', 'image_fallback' => false];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'provider' => TaskVmProviderKind::class,
            'state' => TaskVmState::class,
            'size' => TaskVmSize::class,
            'topology' => TaskTopology::class,
            'topology_state' => TaskVmTopologyState::class,
            'topology_started_at' => 'datetime',
            'publish_state' => TaskVmPublishState::class,
            'publish_queued_at' => 'datetime',
            'publish_started_at' => 'datetime',
            'pi_token' => 'encrypted',
            'model_key' => 'encrypted',
            'wireguard_private_key' => 'encrypted',
            'ssh_host_private_key' => 'encrypted',
            'agent_secret' => 'encrypted',
            'slot_number' => 'integer',
            'provider_bootstrap' => 'encrypted:array',
            'worker_stop_required' => 'boolean',
            'slot_project_id' => 'integer',
            'image_build_id' => 'integer',
            'image_fallback' => 'boolean',
            'claimed_at' => 'datetime',
            'warmed_at' => 'datetime',
            'ready_at' => 'datetime',
            'destroyed_at' => 'datetime',
            'stop_confirmed_at' => 'datetime',
        ];
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('state', '!=', TaskVmState::Destroyed);
    }

    /**
     * The live VMs of the provider that count against its `max_vms`: every one but a failed VM, which counts only where
     * {@see TaskVmProviderKind::countsFailedVms()} or when a failed proof run kept it (`proof:run --keep-failed`): a kept VM holds the CPU and
     * memory of its host whatever its state says, until its group is cancelled and it is destroyed.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function countedAgainstCap(Builder $query, TaskVmProviderKind $provider): void
    {
        $query->where('state', '!=', TaskVmState::Destroyed)->where('provider', $provider)
            ->when(! $provider->countsFailedVms(), static fn (Builder $query) => $query->where(static fn (Builder $query) => $query->where('state', '!=', TaskVmState::Failed)
                ->orWhereIn('group_id', Operation::query()->where('kind', 'proof.command')->whereNotNull('result->kept')->select('target_id'))));
    }

    /**
     * The error columns of a failure: its stable code and its message, cut to {@see TaskVmException::MessageLimit}.
     *
     * @return array{error_code: string, error_message: string}
     */
    public static function errorFrom(Throwable $exception): array
    {
        return ['error_code' => TaskVmException::codeOf($exception), 'error_message' => TaskVmException::messageOf($exception)];
    }

    /** Keeps a failure on the row as its error, without changing its state. */
    public function recordError(Throwable $exception): void
    {
        $this->update(self::errorFrom($exception));
    }

    /**
     * The image the VM got, as a provision result reports it. Allocation fixes these columns, so a replay reads the same.
     *
     * @return array{recipe_identity:string|null,fingerprint:string|null,build_id:int|null,fallback:bool}
     */
    public function imageReceipt(): array
    {
        return ['recipe_identity' => $this->recipe_identity, 'fingerprint' => $this->image_fingerprint,
            'build_id' => $this->image_build_id, 'fallback' => $this->image_fallback];
    }

    /** The recorded absence waits for the host sweep's verdict; only that verdict may fail a missing VM. */
    public function hostSweepWaitReason(): ?string
    {
        return $this->state === TaskVmState::Ready && $this->error_code === TaskVmError::AwaitingHostSweep->value
            ? $this->error_message
            : null;
    }

    /** The group's live task VM, or null when it has none. */
    public static function forGroup(Task $group): ?self
    {
        return self::query()->live()->where('group_id', $group->id)->first();
    }

    /** The live task VM that this Node is, or null for every other Node. */
    public static function forNode(Node|int $node): ?self
    {
        return self::query()->live()->where('node_id', $node instanceof Node ? $node->id : $node)->first();
    }

    /** @return BelongsTo<Task, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'group_id')->withoutGlobalScope('subtask');
    }

    /** @return BelongsTo<Node, $this> */
    public function hostNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'host_node_id');
    }

    /** The Incus host Node of an `incus` row. */
    public function incusHostNode(): Node
    {
        return $this->hostNode ?? throw new TaskVmException(TaskVmError::UnknownHost, "Task VM [{$this->name}] has no host Node.", 500);
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'node_id');
    }
}
