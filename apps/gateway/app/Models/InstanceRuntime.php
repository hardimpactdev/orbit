<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Instances\ContainerServer;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\WireGuard\Ipv4Subnet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The container of a container Instance (docs/reference/development-containers.md#records). A row exists only for an
 * Instance that runs in a container; a checkout Instance has none. The database keeps the container, its bridge
 * address, its T3 port and its clone source fixed, and accepts a clone source only when it is the Project's default
 * Instance on the same Node, itself in a container, and that default keeps its runtime and name while clones of it exist. A T3 port is one of 3800 to 3999 of the Node's WireGuard address;
 * the default Instance never has one.
 *
 * @property int $id
 * @property int|null $operation_id
 * @property int $instance_id
 * @property int $node_id
 * @property string $incus_project
 * @property string $container_name
 * @property string $bridge_address
 * @property int|null $t3_port
 * @property ContainerServer|null $container_server
 * @property string $runtime_version
 * @property string $agent_version
 * @property int|null $clone_source_instance_id
 * @property InstanceRuntimePowerState $power_state
 * @property string|null $seed_commit
 * @property Carbon|null $seed_taken_at
 * @property array{heads: list<string>, stashes: list<string>, untracked: list<string>|null}|null $workspace_baseline
 * @property Carbon|null $workspace_baseline_at
 * @property Carbon|null $idle_watch_started_at
 * @property string|null $inner_volume
 * @property int|null $idmap_size
 * @property list<string>|null $copy_providers the providers whose instances a task copy's T3 settings got at its create; null for every other container
 * @property string|null $agent_key_hash the SHA-256 hash of a task copy's key for Orbit (TaskKey); null for every other container
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Instance $instance
 * @property-read Node $node
 */
final class InstanceRuntime extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'operation_id',
        'instance_id',
        'node_id',
        'incus_project',
        'container_name',
        'bridge_address',
        't3_port',
        'container_server',
        'runtime_version',
        'agent_version',
        'clone_source_instance_id',
        'power_state',
        'seed_commit',
        'seed_taken_at',
        'workspace_baseline',
        'workspace_baseline_at',
        'idle_watch_started_at',
        'inner_volume',
        'idmap_size',
        'copy_providers',
    ];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['agent_key_hash'];

    /** A container gets a guest address of its Node's bridge; the bridge keeps the first usable address. */
    protected static function booted(): void
    {
        self::creating(static function (self $runtime): void {
            $bridge = Node::query()->whereKey($runtime->node_id)->value('instance_bridge');
            if (! is_string($bridge)) {
                throw new LogicException("Node [{$runtime->node_id}] has no Instance bridge.");
            }
            $subnet = Ipv4Subnet::from($bridge);
            if (! $subnet->containsUsableAddress($runtime->bridge_address) || $runtime->bridge_address === $subnet->usableRange()[0]) {
                throw new LogicException("[{$runtime->bridge_address}] is not a guest address of the Instance bridge [{$bridge}].");
            }
        });
    }

    /** The server this container runs; only a runtime with a T3 port has one (docs/reference/development-containers.md#container-server). */
    public function server(): ContainerServer
    {
        return $this->container_server ?? throw new LogicException("Instance runtime [{$this->id}] runs no container server.");
    }

    /** @return BelongsTo<Instance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'operation_id' => 'integer',
            'instance_id' => 'integer',
            'node_id' => 'integer',
            't3_port' => 'integer',
            'container_server' => ContainerServer::class,
            'clone_source_instance_id' => 'integer',
            'idmap_size' => 'integer',
            'power_state' => InstanceRuntimePowerState::class,
            'workspace_baseline' => 'array',
            'copy_providers' => 'array',
            'workspace_baseline_at' => 'datetime',
            'idle_watch_started_at' => 'datetime',
            'seed_taken_at' => 'datetime',
        ];
    }
}
