<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Clusters\ClusterState;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Concerns\ValidatesPrivateDnsNamespace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $tld
 * @property ClusterState $state
 * @property array{tld?: ?string, state?: string, router_node_id?: ?int}|null $dns_pending
 * @property Carbon|null $dns_pending_at
 * @property-read Collection<int, Node> $nodes
 * @property-read NodeRole|null $routerAssignment
 * @property-read NodeRole|null $ingressAssignment
 */
final class Cluster extends Model
{
    use ValidatesPrivateDnsNamespace;

    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'state' => 'inactive',
    ];

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'name',
        'tld',
        'state',
    ];

    /** @return HasMany<Node, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class);
    }

    /** @return HasOne<NodeRole, $this> */
    public function routerAssignment(): HasOne
    {
        return $this
            ->hasOne(NodeRole::class)
            ->where('role', 'router')
            ->where('status', LifecycleStatus::Active);
    }

    /**
     * The Ingress assignment while it serves public sites: active, converging, or after a failed convergence.
     * PublicRouteEligibility::servingIngress applies the same rule.
     *
     * @return HasOne<NodeRole, $this>
     */
    public function ingressAssignment(): HasOne
    {
        return $this
            ->hasOne(NodeRole::class)
            ->where('role', 'ingress')
            ->where(static fn (Builder $query): Builder => $query
                ->whereIn('status', [LifecycleStatus::Active, LifecycleStatus::Provisioning])
                ->orWhere(static fn (Builder $failed): Builder => $failed
                    ->where('status', LifecycleStatus::Failed)
                    ->where('failed_step', 'like', 'converge:%')));
    }

    /** @return HasMany<Route, $this> */
    public function routes(): HasMany
    {
        return $this->hasMany(Route::class);
    }

    /** @return array<string, class-string|string> */
    protected function casts(): array
    {
        return ['state' => ClusterState::class, 'dns_pending' => 'array', 'dns_pending_at' => 'datetime'];
    }

    /**
     * The TLD, state or Router that a change in flight publishes before it commits. A hold that nothing cleared
     * (a crash) expires after an hour, so a stale value never answers for long.
     *
     * @return array{tld?: ?string, state?: string, router_node_id?: ?int}
     */
    public function pendingDns(): array
    {
        $at = $this->dns_pending_at;

        if (! is_array($this->dns_pending) || ! $at instanceof Carbon || $at->lt(now()->subHour())) {
            return [];
        }

        return $this->dns_pending;
    }

    /** @param array{tld?: ?string, state?: ClusterState|string, router_node_id?: ?int} $pending */
    public function holdDns(array $pending): void
    {
        if (($pending['state'] ?? null) instanceof ClusterState) {
            $pending['state'] = $pending['state']->value;
        }

        $this->forceFill(['dns_pending' => $pending, 'dns_pending_at' => now()])->save();
    }

    public function releaseDns(): void
    {
        if ($this->dns_pending !== null) {
            $this->forceFill(['dns_pending' => null, 'dns_pending_at' => null])->save();
        }
    }
}
