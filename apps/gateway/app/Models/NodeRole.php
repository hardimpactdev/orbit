<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Nodes\NodeLocks;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $node_id
 * @property int|null $cluster_id
 * @property RoleName $role
 * @property LifecycleStatus $status
 * @property string|null $failed_step
 * @property string|null $error_code
 * @property bool $node_untouched No run for this assignment is known to have changed the Node. Only startFirstAdd() sets it; every other write keeps or clears it.
 * @property Carbon|null $updated_at
 * @property-read Node $node
 * @property-read Cluster|null $cluster
 */
final class NodeRole extends Model
{
    /**
     * How long a `provisioning` or `removing` claim may last before the Gateway treats it as stale: the
     * role lock's term plus StaleClaimMarginSeconds. Adding and removing a role, and each role step of
     * `node:add`, take the Node's role lock before they claim and keep it until the claim ends, so an
     * operation that finds a claim while it holds the lock knows no add or remove still works on it.
     *
     * These claimers hold a claim outside the lock:
     *
     * - Relocation and Cluster Router changes, only inside one Gateway request, which PHP-FPM ends
     *   within the lock's term.
     * - `orbit:gateway-bootstrap`, which claims the Gateway's roles in an Artisan command without a time
     *   limit. It first sets the Node itself to `provisioning`, and role add and remove refuse a Node
     *   that is not `active`, so no operation can take over its claims while it runs.
     *
     * A claim older than the window therefore belongs to an operation that died.
     */
    public const int StaleClaimSeconds = NodeLocks::RequestSeconds + self::StaleClaimMarginSeconds;

    /**
     * The margin over the lock's term: `updated_at` holds whole seconds, and PHP-FPM ends a request
     * slightly after `request_terminate_timeout`.
     */
    public const int StaleClaimMarginSeconds = 60;

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'node_id',
        'cluster_id',
        'role',
        'status',
        'failed_step',
        'error_code',
        'node_untouched',
    ];

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return BelongsTo<Cluster, $this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    public function canClaimConvergence(): bool
    {
        if ($this->status === LifecycleStatus::Active) {
            return true;
        }

        if ($this->status === LifecycleStatus::Provisioning) {
            return $this->isStaleClaim();
        }

        return
            $this->status === LifecycleStatus::Failed
            && is_string($this->failed_step)
            && str_starts_with($this->failed_step, 'converge:');
    }

    /**
     * A `provisioning` or `removing` claim that no operation has touched for StaleClaimSeconds.
     */
    public function isStaleClaim(?CarbonInterface $now = null): bool
    {
        if ($this->status !== LifecycleStatus::Provisioning && $this->status !== LifecycleStatus::Removing) {
            return false;
        }

        $claimedAt = $this->updated_at;

        return $claimedAt === null || $claimedAt->lte(($now ?? Carbon::now())->subSeconds(self::StaleClaimSeconds));
    }

    /**
     * Claims the assignment for convergence. The update applies only while the row still has the
     * status and time this model read, so two operations that both saw a stale claim cannot both take it.
     */
    public function claimConvergence(): void
    {
        // An active role stays active while it converges. The Node's Operation lock is the claim, and an
        // active Gateway role is what the Gateway resolves its own serving Node from.
        if ($this->status === LifecycleStatus::Active) {
            return;
        }

        $this->claimAs(LifecycleStatus::Provisioning);
    }

    /**
     * Claims the assignment for removal with the same compare-and-set as claimConvergence. The claim
     * always writes a fresh time, also when it takes over a stale `removing` claim.
     */
    public function claimRemoval(): void
    {
        $this->claimAs(LifecycleStatus::Removing);
    }

    private function claimAs(LifecycleStatus $status): void
    {
        $query = self::query()->whereKey($this->getKey())->where('status', $this->status->value);
        $claimedAt = $this->getRawOriginal('updated_at');
        $claimedAt === null ? $query->whereNull('updated_at') : $query->where('updated_at', $claimedAt);

        $claimed = $query->update([
            'status' => $status->value,
            'failed_step' => null,
            'error_code' => null,
            'node_untouched' => $status === LifecycleStatus::Provisioning && $this->neverTouchedNode(),
            'updated_at' => $this->freshTimestampString(),
        ]);

        if ($claimed !== 1) {
            throw new RoleAssignmentException("Role [{$this->role->value}] changed while it was being claimed.");
        }

        $this->refresh();
    }

    public function markConvergenceActive(): void
    {
        $this->update([
            'status' => LifecycleStatus::Active,
            'failed_step' => null,
            'error_code' => null,
            'node_untouched' => false,
        ]);
    }

    /**
     * Opens the fact for a first add: no run has worked on this new assignment yet. This is the only
     * write that sets `node_untouched`. A convergence claim carries it forward only from a role that
     * neverTouchedNode(), and a run that ends any way other than `failed` at a read-only step clears it
     * for the life of the row. A claim of a stale `provisioning` row clears it, because that run's result never arrived.
     */
    public function startFirstAdd(): void
    {
        $this->update(['node_untouched' => true]);
    }

    /** The recorded fact that removal needs no run: every run for this assignment failed before it changed the Node. */
    public function neverTouchedNode(): bool
    {
        return $this->status === LifecycleStatus::Failed && $this->node_untouched;
    }

    /**
     * Records a failed first add, or a failed convergence of a role that was not active. A failed
     * convergence never demotes an active role: the failure stays on its Operation and `doctor` shows
     * the drift. A failed `gateway` convergence that demoted the role locked the Gateway out of its
     * own access checks.
     *
     * `$nodeUntouched` says this run failed before its first step that changes the Node. It can only
     * keep the fact or clear it, never set it.
     */
    public function markConvergenceFailed(string $step, string $errorCode, bool $nodeUntouched = false): void
    {
        if ($this->status === LifecycleStatus::Active) {
            return;
        }

        $this->update([
            'status' => LifecycleStatus::Failed,
            'failed_step' => "converge:{$step}",
            'error_code' => $errorCode,
            'node_untouched' => $nodeUntouched && $this->node_untouched,
        ]);
    }

    public function neverActivated(): bool
    {
        if ($this->status === LifecycleStatus::Active || $this->status === LifecycleStatus::Removing) {
            return false;
        }

        return ! (is_string($this->failed_step) && str_starts_with($this->failed_step, 'remove:'));
    }

    /** @return array<string, class-string|literal-string> */
    protected function casts(): array
    {
        return [
            'role' => RoleName::class,
            'status' => LifecycleStatus::class,
            'node_untouched' => 'boolean',
        ];
    }
}
