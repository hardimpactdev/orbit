<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property string $provider
 * @property TaskVmSize $size
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
 * @property Carbon|null $ready_at
 * @property Carbon|null $destroyed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Task|null $group
 * @property-read Node|null $hostNode
 * @property-read Node|null $node
 */
final class TaskVm extends Model
{
    public const string Incus = 'incus';

    public const string UpCloud = 'upcloud';

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'group_id', 'host_node_id', 'node_id', 'provider', 'size', 'provider_id', 'ssh_host_key', 'name', 'state', 'address', 'wireguard_ip',
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
            'state' => TaskVmState::class,
            'size' => TaskVmSize::class,
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

    /** @param Builder<self> $query */
    #[Scope]
    protected function pool(Builder $query): void
    {
        $query->whereNull('group_id');
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
        return $this->hostNode ?? throw new TaskVmException('task_vm.unknown_host', "Task VM [{$this->name}] has no host Node.", 500);
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'node_id');
    }
}
