<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\NodeAgentSecretHash;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Proof\ProofCopyHosts;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Concerns\ValidatesPrivateDnsNamespace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int $id
 * @property int|null $cluster_id
 * @property string $name
 * @property LifecycleStatus $status
 * @property string $platform
 * @property string|null $agent_failed_pin
 * @property string|null $architecture
 * @property string $public_ssh_host
 * @property int $public_ssh_port
 * @property string $user
 * @property string|null $tld
 * @property string|null $wireguard_ip
 * @property bool $reboot_hold
 * @property bool $primary_agent
 * @property string|null $lan_ip
 * @property string|null $wireguard_public_key
 * @property string|null $dns_server_override
 * @property string|null $instance_bridge
 * @property string|null $ci_bridge
 * @property int|null $seed_sender_id
 * @property string|null $ci_hold_digest
 * @property array<string, string>|null $converge_digests
 * @property bool $converge_digests_full
 * @property array<string, mixed>|null $release_inputs
 * @property bool $ci_nested_hosting
 * @property int|null $t3_port_last
 * @property string|null $ssh_host_fingerprint
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $id_ranges
 * @property string|null $agent_secret_hash
 * @property int|null $ssh_jump_node_id
 * @property-read Collection<int, NodeRole> $roles
 * @property-read Collection<int, ToolManagerRecord> $toolManagers
 * @property-read Collection<int, Tool> $tools
 * @property-read Collection<int, Node> $accessibleNodes
 * @property-read Collection<int, Node> $accessingNodes
 * @property-read Cluster|null $cluster
 * @property-read Node|null $sshJumpNode
 * @property-read Collection<int, Process> $processes
 * @property-read Collection<int, Schedule> $schedules
 * @property-read Collection<int, Schedule> $hostedSchedules
 * @property-read Collection<int, DatabaseConnection> $databaseConnections
 */
final class Node extends Model
{
    use ValidatesPrivateDnsNamespace;

    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'public_ssh_port' => 22,
        'reboot_hold' => false,
        'primary_agent' => false,
    ];

    /** @var list<string> */
    #[\Override]
    protected $hidden = [
        'agent_secret_hash',
    ];

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'name',
        'cluster_id',
        'status',
        'platform',
        'architecture',
        'tld',
        'public_ssh_host',
        'public_ssh_port',
        'user',
        'wireguard_ip',
        'lan_ip',
        'reboot_hold',
        'primary_agent',
        'wireguard_public_key',
        'dns_server_override',
        'ssh_host_key_type',
        'ssh_host_key',
        'ssh_host_fingerprint',
        'failed_step',
        'error_code',
        'settings',
        'ssh_jump_node_id',
    ];

    /**
     * The Node that public SSH goes through until this Node has an active role.
     *
     * @return BelongsTo<Node, $this>
     */
    public function sshJumpNode(): BelongsTo
    {
        return $this->belongsTo(self::class, 'ssh_jump_node_id');
    }

    /** @return HasMany<NodeRole, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(NodeRole::class);
    }

    /**
     * The environment the Node's app role places its work in: `production` for app-prod, `development` for app-dev or a CI-only
     * Node, and null for a Node with both app roles or neither.
     */
    public function placementEnvironment(): ?string
    {
        $this->loadMissing('roles');

        // The Node's app role decides the environment in every lifecycle state, so an
        // app-dev role that is converging, failed, or being removed still places its Instances.
        $roles = $this->roles->filter(
            static fn (NodeRole $role): bool => in_array($role->role, [RoleName::AppDev, RoleName::AppProd], strict: true),
        );

        // A CI-only Node has no app role and hosts only proof copies, which are development Instances
        // (docs/reference/node-provisioning.md#proof-copies-on-a-ci-node).
        if ($roles->isEmpty() && ProofCopyHosts::ciOnly($this)) {
            return 'development';
        }

        if ($roles->count() !== 1) {
            return null;
        }

        return $roles->sole()->role === RoleName::AppProd ? 'production' : 'development';
    }

    /** @return HasMany<NodeRole, $this> The assignments that hold host work: every role except a record-only one such as `agent`. */
    public function hostRoles(): HasMany
    {
        return $this->roles()->whereNotIn('role', app(RoleRegistry::class)->recordOnly());
    }

    /** @return BelongsTo<Cluster, $this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    /** @return HasMany<Instance, $this> */
    public function instances(): HasMany
    {
        return $this->hasMany(Instance::class);
    }

    /** @return MorphMany<Schedule, $this> */
    public function schedules(): MorphMany
    {
        return $this->morphMany(Schedule::class, 'target');
    }

    /** @return HasMany<Schedule, $this> */
    public function hostedSchedules(): HasMany
    {
        return $this->hasMany(Schedule::class, 'host_node_id');
    }

    /** @return MorphMany<Process, $this> */
    public function processes(): MorphMany
    {
        return $this->morphMany(Process::class, 'owner');
    }

    /** @return HasMany<DatabaseConnection, $this> */
    public function databaseConnections(): HasMany
    {
        return $this->hasMany(DatabaseConnection::class);
    }

    /** @return HasMany<Route, $this> */
    public function scopedRoutes(): HasMany
    {
        return $this->hasMany(Route::class);
    }

    /** @return HasMany<Route, $this> */
    public function generatedRoutes(): HasMany
    {
        return $this->hasMany(Route::class, 'generation_basis_node_id');
    }

    /** @return HasMany<FirewallRule, $this> */
    public function firewallRules(): HasMany
    {
        return $this->hasMany(FirewallRule::class);
    }

    /** @return HasMany<ToolManagerRecord, $this> */
    public function toolManagers(): HasMany
    {
        return $this->hasMany(ToolManagerRecord::class);
    }

    /** @return HasMany<Tool, $this> */
    public function tools(): HasMany
    {
        return $this->hasMany(Tool::class);
    }

    /** @return BelongsToMany<Node, $this> */
    public function accessibleNodes(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'node_access',
            'consumer_node_id',
            'serving_node_id',
        )->withTimestamps();
    }

    /** @return BelongsToMany<Node, $this> */
    public function accessingNodes(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'node_access',
            'serving_node_id',
            'consumer_node_id',
        )->withTimestamps();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => LifecycleStatus::class,
            'public_ssh_port' => 'integer',
            'settings' => 'array',
            'converge_digests' => 'array',
            'converge_digests_full' => 'boolean',
            'release_inputs' => 'array',
            'id_ranges' => 'array',
            'reboot_hold' => 'boolean',
            'primary_agent' => 'boolean',
            'ci_nested_hosting' => 'boolean',
            'agent_secret_hash' => NodeAgentSecretHash::class,
        ];
    }
}
