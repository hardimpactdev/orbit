<?php

declare(strict_types=1);

namespace App\Models;

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
 * One disposable VM that serves one task group as a normal `app-dev` Node. A pool row has no group: it is
 * a warm VM of its host, or one that Orbit is creating, failing, or destroying for the pool. An `incus` row runs on
 * its host Node. An `upcloud` row has no host Node: `provider_id` is its server's UUID, and `ssh_host_key` the host
 * key that the Gateway generated for it.
 *
 * @property int $id
 * @property int|null $group_id
 * @property int|null $host_node_id
 * @property int|null $node_id
 * @property TaskVmProviderKind $provider
 * @property TaskVmSize $size
 * @property list<string>|null $topology
 * @property TaskVmTopologyState|null $topology_state
 * @property string|null $topology_run
 * @property Carbon|null $topology_started_at
 * @property string|null $topology_error
 * @property string|null $publish_commit
 * @property string|null $publish_branch
 * @property TaskVmPublishState|null $publish_state
 * @property string|null $publish_error
 * @property Carbon|null $publish_queued_at
 * @property string|null $provider_id
 * @property string|null $ssh_host_key
 * @property string $name
 * @property TaskVmState $state
 * @property string|null $address
 * @property string $wireguard_ip
 * @property string|null $pi_token
 * @property string|null $model_key
 * @property string|null $model_proxy_origin
 * @property Carbon|null $claimed_at
 * @property Carbon|null $warmed_at
 * @property string|null $image_fingerprint
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $ready_at when the VM last became `ready`: its first preparation, or its last resume
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
        'group_id', 'host_node_id', 'node_id', 'provider', 'size', 'topology', 'topology_state', 'topology_run', 'topology_started_at', 'topology_error',
        'publish_commit', 'publish_branch', 'publish_state', 'publish_error', 'publish_queued_at', 'provider_id', 'ssh_host_key', 'name', 'state', 'address', 'wireguard_ip',
        'pi_token', 'model_key', 'model_proxy_origin', 'claimed_at', 'warmed_at', 'image_fingerprint',
        'error_code', 'error_message', 'ready_at', 'destroyed_at',
    ];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['pi_token', 'model_key'];

    /** @var array<string, string> */
    #[\Override]
    protected $attributes = ['size' => 'normal'];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'provider' => TaskVmProviderKind::class,
            'state' => TaskVmState::class,
            'size' => TaskVmSize::class,
            'topology' => 'array',
            'topology_state' => TaskVmTopologyState::class,
            'topology_started_at' => 'datetime',
            'publish_state' => TaskVmPublishState::class,
            'publish_queued_at' => 'datetime',
            'pi_token' => 'encrypted',
            'model_key' => 'encrypted',
            'claimed_at' => 'datetime',
            'warmed_at' => 'datetime',
            'ready_at' => 'datetime',
            'destroyed_at' => 'datetime',
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
     * {@see TaskVmProviderKind::countsFailedVms()}.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function countedAgainstCap(Builder $query, TaskVmProviderKind $provider): void
    {
        $query->where('state', '!=', TaskVmState::Destroyed)->where('provider', $provider)
            ->when(! $provider->countsFailedVms(), static fn (Builder $query) => $query->where('state', '!=', TaskVmState::Failed));
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function pool(Builder $query): void
    {
        $query->whereNull('group_id');
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
