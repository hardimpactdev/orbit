<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Clusters\ClusterState;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Concerns\ValidatesPrivateDnsNamespace;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $name
 * @property string|null $tld
 * @property ClusterState $state
 * @property array<string, array{at: int, values: array{tld?: ?string, state?: string, router_node_id?: ?int}}>|null $dns_pending
 * @property-read Collection<int, Node> $nodes
 * @property-read NodeRole|null $routerAssignment
 * @property-read NodeRole|null $ingressAssignment
 */
final class Cluster extends Model
{
    use ValidatesPrivateDnsNamespace;

    /** The hold of a TLD or state change (`cluster:update`). */
    public const string DnsHoldChange = 'change';

    /** The hold of a Router selection while its render reads the rows. */
    public const string DnsHoldRouter = 'router';

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
        return ['state' => ClusterState::class, 'dns_pending' => 'array'];
    }

    /**
     * The TLD, state or Router that changes in flight publish before they commit, merged across their holds. A hold that nothing
     * cleared (a crash) expires after an hour, so a stale value never answers for long. A newer hold wins a key that two holds name.
     *
     * @return array{tld?: ?string, state?: string, router_node_id?: ?int}
     */
    public function pendingDns(): array
    {
        $holds = array_filter(is_array($this->dns_pending) ? $this->dns_pending : [],
            static fn (mixed $hold): bool => is_array($hold) && is_int($hold['at'] ?? null) && is_array($hold['values'] ?? null)
                && $hold['at'] >= now()->subHour()->getTimestamp());
        uasort($holds, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        $pending = [];

        foreach ($holds as $hold) {
            $pending = [...$pending, ...$hold['values']];
        }

        return $pending;
    }

    /**
     * Holds the keys one source of change publishes. Each source has its own hold: a TLD or state change (`DnsHoldChange`) and
     * a Router selection (`DnsHoldRouter`) never overwrite or release each other.
     *
     * @param  array{tld?: ?string, state?: ClusterState|string, router_node_id?: ?int}  $pending
     */
    public function holdDns(string $source, array $pending): void
    {
        if (($pending['state'] ?? null) instanceof ClusterState) {
            $pending['state'] = $pending['state']->value;
        }

        $this->changeDnsHolds(static function (array $holds) use ($source, $pending): array {
            $holds[$source] = ['at' => now()->getTimestamp(), 'values' => $pending];

            return $holds;
        });
    }

    /** Releases the hold of one source and leaves the holds of the others. */
    public function releaseDns(string $source): void
    {
        $this->changeDnsHolds(static function (array $holds) use ($source): array {
            unset($holds[$source]);

            return $holds;
        });
    }

    /** @param Closure(array<string, mixed>): array<string, mixed> $change */
    private function changeDnsHolds(Closure $change): void
    {
        DB::transaction(function () use ($change): void {
            $locked = self::query()->lockForUpdate()->findOrFail($this->id);
            $holds = $change(is_array($locked->dns_pending) ? $locked->dns_pending : []);
            $locked->forceFill(['dns_pending' => $holds === [] ? null : $holds])->save();
            $this->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
