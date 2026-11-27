<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Actions\Instances\StartInstanceRestoreAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Instances\ClonePublication;
use App\Domain\Instances\InstanceDestroyKind;
use App\Domain\Routes\NativeRouteCreatePlan;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Jobs\Operations\PromoteRouteWrites;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The hold of the Nodes that serve a Route write (docs/reference/routes.md#coordinate-publication). Admission takes every Node
 * of the write at once or none; a write that finds one held waits with status `waiting` and starts, oldest first, when the
 * Nodes are free. The terminal state releases them, except for a timed-out write whose run Swamp has not confirmed stopped.
 * The DNS Node is not held: its records are keyed. An update on the Gateway engine also holds, at its start, the Nodes its phases
 * write and its VPN Nodes (`holdEngine`).
 *
 * A removal, and a clone's create whose publication is Caddy only, share their Nodes (`route_withdrawal_holds`): each merges or
 * withdraws its own sections in one Node-local transaction under the Node lock, so several of them on one Node all land. Any other
 * publisher writes whole files that the Gateway rendered, so it holds its Nodes alone (`route_publication_holds`), against every
 * writer. Each take checks the other table in the same IMMEDIATE transaction.
 */
final class RoutePublicationHolds
{
    /** @var list<string> */
    private const array Kinds = ['instance.create', 'instance.complete', 'instance.destroy', 'instance.unregister', 'instance.clone', 'instance.transfer', 'instance.migrate',
        'instance.restore', 'route.create', 'route.update', 'route.destroy', 'route.target_set'];

    /** Kinds that render their whole publication again from the current catalog when a promotion starts them. */
    private const array RefreshedKinds = ['instance.create', 'instance.complete', 'instance.clone', 'instance.restore', 'route.create'];

    /**
     * Kinds that are no Route write but publish Caddyfiles the Gateway rendered at their admission: an Ingress move. Admission takes
     * the Nodes through `holdRun` or refuses; the terminal state frees them, as for a Route write.
     *
     * @var list<string>
     */
    private const array RunKinds = ['node.role_relocate'];

    /** A timed-out write that Swamp never confirmed stopped gives its Nodes back after this long. */
    private const int UnconfirmedMinutes = 15;

    /**
     * Kinds that publish Route sites inside a longer run. They hold the Nodes of their Route-publish step only: the run asks for
     * the hold before that step and gives it back after it, and the hold has a lease.
     *
     * @var list<string>
     */
    private const array StepKinds = ['instance.register', 'cluster.router_set', 'cluster.router_unset'];

    /**
     * How many shared writers (removals and Caddy-only clone creates) hold one Node at most. Their Node transactions queue on the Node
     * lock, which is bounded at 30 s. A publication holds it about 0.25 s; a withdrawal holds it a few seconds, so at most
     * `SharedRemovals` of the eight are removals, as measured when removals first shared a Node. A removal over either cap waits here,
     * in the Gateway, without a Swamp slot; a create over the cap leaves its address to its completion.
     */
    private const int SharedWriters = 8;

    private const int SharedRemovals = 4;

    /**
     * The withdrawal's Node steps take one of this many names per Node, by the withdrawn Route's ID (workflow-route-withdraw-node.yaml).
     * Swamp fails a step that waits 60 s for a name another run holds, so two removals of one slot never share a Node: the second waits
     * here, in the Gateway, until the first has withdrawn.
     */
    public const int WithdrawalSlots = 16;

    /**
     * A removal's container steps take one of this many names per Node, by the removed Instance's ID (workflow-operation-instance-destroy.yaml).
     * They run after the removal gave its Route Nodes back, and a removal without a Route holds none, so the Route holds do not keep two
     * removals of one slot apart: a removal starts only when no older removal of its slot on its Node is still open.
     */
    public const int DestroySlots = 64;

    /**
     * A removal's own refusals: the removal cap, another removal of the same withdrawal slot on a Node, and an older open removal of the
     * same Instance slot on its Node. It waits in the Gateway.
     */
    private const string RemovalCap = 'removal_cap';

    private const string RemovalSlot = 'removal_slot';

    private const string InstanceSlot = 'instance_slot';

    /** A write admitted to start after another Operation (`after_operation_id`) waits for that Operation to end. */
    private const string AfterOperation = 'after_operation';

    /** How long one step-scoped hold lasts without a release; the Operation's end frees it sooner. */
    private const int LeaseSeconds = 300;

    /** The Operation holds its Nodes from admission to its end. */
    public static function applies(string $kind): bool
    {
        return in_array($kind, self::Kinds, true);
    }

    /** The Operation holds its Nodes for one step, through the hold call. */
    public static function holdsPerStep(string $kind): bool
    {
        return in_array($kind, self::StepKinds, true);
    }

    /**
     * A removal holds its Nodes from admission until its Route is withdrawn, not until its end: the run calls the release right
     * after its withdraw step succeeded, and the later steps write only files of the removed Instance.
     */
    public static function releasesAfterWithdraw(string $kind): bool
    {
        return InstanceDestroyKind::removes($kind);
    }

    /** A removal withdraws, and a Caddy-only clone create merges, only its own sections under the Node lock, so they share their Nodes. */
    private static function sharesNodes(Operation $operation): bool
    {
        return InstanceDestroyKind::removes($operation->kind) || ClonePublication::sharesNodes($operation);
    }

    /** Whether the terminal state of the kind has holds to free. */
    public static function tracks(string $kind): bool
    {
        return self::applies($kind) || self::holdsPerStep($kind) || in_array($kind, self::RunKinds, true);
    }

    /**
     * Takes the Nodes for the whole run of an admitted Operation of a `RunKinds` kind, in the caller's transaction. False when a Route
     * write holds or waits for one of them: the caller refuses, and nothing waits.
     *
     * @param  list<int>  $nodes
     */
    public function holdRun(Operation $operation, array $nodes): bool
    {
        if (! in_array($operation->kind, self::RunKinds, true)) {
            throw new \LogicException("Operation kind [{$operation->kind}] holds no Nodes for its run.");
        }

        return $nodes === [] || (! $this->waitingOverlaps($nodes) && $this->acquire($operation->id, $nodes));
    }

    /**
     * Takes, in the caller's transaction, every Node an engine-run write acts on that its admission did not take: the Nodes of its
     * site phases and the VPN Nodes whose catalog it publishes. False when another write holds or waits for one of them. The
     * terminal state frees them with the rest.
     *
     * @param  list<int>  $nodes
     */
    public function holdEngine(Operation $operation, array $nodes): bool
    {
        $held = DB::table('route_publication_holds')->where('operation_id', $operation->id)->pluck('node_id')->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all();
        $missing = array_values(array_diff(array_values(array_unique($nodes)), $held));
        sort($missing);

        return $missing === [] || (! $this->waitingOverlaps($missing) && $this->acquire($operation->id, $missing));
    }

    /** A Route write that waits for a held Node: native workflows report `waiting` too, and those are not ours. */
    public static function waitsForNodes(Operation $operation): bool
    {
        return $operation->status === OperationStatus::Waiting && self::applies($operation->kind)
            && isset($operation->state['hold_nodes']) && ! isset($operation->state['swamp_run_id']);
    }

    /**
     * Whether a finished write keeps its Nodes. Its run may still be going while the Gateway waits for proof: a timed-out write
     * keeps them until Swamp confirms the cancel, or for `UnconfirmedMinutes`.
     */
    public static function keepsNodes(Operation $operation): bool
    {
        if (ExpireOperationAction::awaitsSettlement($operation)) {
            return true;
        }

        return ExpireOperationAction::expiredUnsettled($operation) && ! isset($operation->state['native_cancel_confirmed_at'])
            && $operation->finished_at !== null && $operation->finished_at->isAfter(now()->subMinutes(self::UnconfirmedMinutes));
    }

    /**
     * The Nodes that serve the sites of the write: every `publications` entry of its Route mutations with `serves_routes`.
     * The entries sit at another path in each kind, so the input is walked.
     *
     * @return list<int>
     */
    public static function nodes(Operation $operation): array
    {
        if (! self::tracks($operation->kind)) {
            return [];
        }
        // A retry of a removal whose earlier run withdrew the Route has no Route work left: it waits for no other Route write.
        if (self::releasesAfterWithdraw($operation->kind) && ($operation->input['route_withdrawn'] ?? false) === true) {
            return [];
        }
        $nodes = [];
        self::collect($operation->input, $nodes);
        $nodes = array_values(array_unique($nodes));
        sort($nodes);

        return $nodes;
    }

    /** @param array<int,int> $nodes */
    private static function collect(mixed $value, array &$nodes): void
    {
        if (! is_array($value)) {
            return;
        }
        if (isset($value['publications']) && is_array($value['publications']) && array_is_list($value['publications'])) {
            foreach ($value['publications'] as $publication) {
                if (is_array($publication) && ($publication['serves_routes'] ?? false) === true && is_int($publication['node_id'] ?? null)) {
                    $nodes[] = $publication['node_id'];
                }
            }
        }
        foreach ($value as $child) {
            self::collect($child, $nodes);
        }
    }

    /**
     * The Operation an admitted write starts after, from its input `after_operation_id`: a Conn removal admitted while the clone's
     * completion runs waits until that completion has ended (docs/reference/conn.md#remove-an-instance).
     */
    public static function after(Operation $operation): ?int
    {
        $after = $operation->input['after_operation_id'] ?? null;

        return is_int($after) ? $after : null;
    }

    /** Whether the Operation the write starts after is still open. */
    private static function afterOpen(Operation $operation): bool
    {
        $after = self::after($operation);

        return $after !== null && Operation::query()->whereKey($after)->whereNotIn('status', OperationStatus::terminalValues())->exists();
    }

    /** True when the Operation may start now. Otherwise it is set to `waiting` and starts when `promote` finds its Nodes free. */
    public function admit(Operation $operation): bool
    {
        // A kind that holds per step takes no Node at admission: its run calls for the hold around its Route-publish step.
        $nodes = self::applies($operation->kind) ? self::nodes($operation) : [];
        $after = self::applies($operation->kind) && self::afterOpen($operation);
        $slot = self::slotOpen($operation);
        if ($nodes === [] && ! $after && ! $slot) {
            return true;
        }
        // Null when it took the Nodes; else why not. An older write first: the Operation it starts after, an older removal of its Instance
        // slot, or an older waiter on a Node.
        $refused = match (true) {
            $after || $this->olderWaiterOverlaps($operation->id, $nodes) => ClonePublication::WaiterFirst,
            $slot => self::InstanceSlot,
            default => $this->take($operation, $nodes),
        };
        if ($refused === null) {
            return true;
        }
        // A clone's create never waits for a Route Node: it leaves its publication to its completion and starts at once. The insert above
        // is the decision: Caddy-only creates share the Nodes, up to the shared cap; any other create takes them alone or defers.
        if (ClonePublication::takesFreeNodesOnly($operation)) {
            app(ClonePublication::class)->defer($operation, $refused, self::sharers($nodes));

            return true;
        }
        // A write that publishes a render made now records the sites its Nodes serve, so its start can tell when they changed.
        // The reason is kept with the wait, so a read tells a held Node, a cap and a slot apart (`hold_wait_reason`).
        app(OperationStore::class)->change($operation, ['status' => OperationStatus::Waiting,
            'state' => [...($operation->state ?? []), 'hold_nodes' => $nodes, 'hold_wait_reason' => $after ? self::AfterOperation : $refused,
                ...(self::rendersAtStart($operation) ? [] : ['hold_sites' => self::served($nodes)])]]);

        return false;
    }

    /**
     * The site domains each Node serves now, one digest per Node. A site published or withdrawn on a Node changes its digest.
     *
     * @param  list<int>  $nodes
     * @return array<int, string>
     */
    private static function served(array $nodes): array
    {
        $served = [];
        foreach ($nodes as $nodeId) {
            // A Node that is gone serves nothing.
            $node = Node::query()->find($nodeId);
            $domains = $node === null ? [] : app(DevelopmentSiteRepository::class)->forNode($node)
                ->map(static fn (DevelopmentSite $site): string => $site->domain)->unique()->sort()->values()->all();
            $served[$nodeId] = hash('sha256', implode("\n", $domains));
        }

        return $served;
    }

    /**
     * A waiting write that publishes the whole Caddyfile it rendered at its admission, when a site was published or withdrawn on its
     * Nodes meanwhile: its render would drop or bring back that site. It is refused at its start with `route.publication_stale`, and
     * the same command run again renders from the current catalog (docs/reference/routes.md#coordinate-publication).
     */
    private static function stale(Operation $operation): bool
    {
        $recorded = $operation->state['hold_sites'] ?? null;

        return is_array($recorded) && $recorded !== self::served(self::waitingNodes($operation));
    }

    /** Frees the Nodes of a finished Operation, then asks for the waiting ones to start, after the caller's transaction. */
    public function release(int $operationId, bool $wasWaiting = false): void
    {
        $freed = DB::table('route_publication_holds')->where('operation_id', $operationId)->delete()
            + DB::table('route_withdrawal_holds')->where('operation_id', $operationId)->delete();
        // A write that starts after this Operation may start now, even when this one held no Node; so may a removal of its slot.
        $follower = Operation::query()->whereIn('kind', self::Kinds)->where('status', OperationStatus::Waiting)
            ->where(static fn ($query) => $query->where('input->after_operation_id', $operationId)->orWhereIn('kind', InstanceDestroyKind::Removals))->exists();
        if ($freed > 0 || $wasWaiting || $follower) {
            PromoteRouteWrites::dispatch()->afterCommit();
        }
    }

    /**
     * The safety net of the minute pass: frees the Nodes of an Operation that is gone or finished without a release, then
     * starts the waiting ones that fit. Returns how many holds it freed.
     */
    public function sweep(): int
    {
        $freed = DB::table('route_publication_holds')->whereNotNull('lease_until')->where('lease_until', '<', now())->delete();
        $ids = DB::table('route_publication_holds')->distinct()->pluck('operation_id')
            ->merge(DB::table('route_withdrawal_holds')->distinct()->pluck('operation_id'))->unique();
        foreach ($ids as $id) {
            $operation = Operation::query()->whereKey($id)->first();
            if ($operation === null || ($operation->status->terminal() && ! self::keepsNodes($operation))) {
                $freed += DB::table('route_publication_holds')->where('operation_id', $id)->delete()
                    + DB::table('route_withdrawal_holds')->where('operation_id', $id)->delete();
            }
        }
        $this->promoteAndStart();

        return $freed;
    }

    public function promoteAndStart(): void
    {
        $this->start($this->promote());
    }

    /**
     * Moves the waiting Operations whose Nodes are free to `queued`, oldest first. A waiting one that overlaps an older one that
     * stays waiting stays too, so a stream of newer writes cannot starve it.
     *
     * @return list<Operation>
     */
    public function promote(): array
    {
        $promoted = [];
        $blocked = [];
        foreach ($this->waiters() as $waiter) {
            // A write still waiting for its Operation, or a removal for an older one of its slot, claims nothing yet, so it holds back no
            // younger write.
            if (self::afterOpen($waiter)) {
                $this->waitFor($waiter, self::AfterOperation);

                continue;
            }
            if (self::slotOpen($waiter)) {
                $this->waitFor($waiter, self::InstanceSlot);

                continue;
            }
            $nodes = self::waitingNodes($waiter);
            $refused = array_intersect($nodes, $blocked) !== [] ? ClonePublication::WaiterFirst : $this->take($waiter, $nodes);
            if ($refused !== null) {
                $blocked = [...$blocked, ...$nodes];
                $this->waitFor($waiter, $refused);

                continue;
            }
            if ($this->queue($waiter)) {
                $promoted[] = $waiter;
            }
        }

        return $promoted;
    }

    /** @return Collection<int, Operation> */
    private function waiters(?int $before = null): Collection
    {
        return Operation::query()->whereIn('kind', self::Kinds)->where('status', OperationStatus::Waiting)->whereNull('state->swamp_run_id')
            ->when($before !== null, static fn ($query) => $query->where('id', '<', $before))->orderBy('id')->get()
            ->filter(static fn (Operation $waiter): bool => self::waitingNodes($waiter) !== [] || self::after($waiter) !== null || self::destroySlot($waiter) !== null)->values();
    }

    /** Records why a waiting write still waits, when that changed; a write that left `waiting` meanwhile is left alone. */
    private function waitFor(Operation $waiter, string $reason): void
    {
        if (($waiter->state['hold_wait_reason'] ?? null) === $reason) {
            return;
        }
        DB::transaction(static function () use ($waiter, $reason): void {
            $current = Operation::query()->lockForUpdate()->findOrFail($waiter->id);
            if ($current->status === OperationStatus::Waiting && ! isset($current->state['swamp_run_id'])) {
                app(OperationStore::class)->change($current, ['state' => [...($current->state ?? []), 'hold_wait_reason' => $reason]]);
            }
        });
    }

    /** The slot of a removal's step names: its Instance's ID modulo `DestroySlots`, for destroy and unregister alike. Null for any other kind. */
    public static function destroySlot(Operation $operation): ?int
    {
        $instance = InstanceDestroyKind::removes($operation->kind) ? ($operation->input['instance_id'] ?? null) : null;

        return is_int($instance) ? $instance % self::DestroySlots : null;
    }

    /**
     * An older removal of the same Instance slot on the same Node is still open, or has ended while Swamp may still run it (`keepsNodes`:
     * a timed-out run that still holds its target lock, or one that ended unconfirmed in the last minutes): this one starts after it, so
     * the two never wait on one step name. Oldest first, so the oldest of a slot always goes.
     */
    private static function slotOpen(Operation $operation): bool
    {
        $slot = self::destroySlot($operation);
        if ($slot === null || $operation->node_id === null) {
            return false;
        }

        return Operation::query()->whereIn('kind', InstanceDestroyKind::Removals)->where('node_id', $operation->node_id)->where('id', '<', $operation->id)
            ->where(static fn ($query) => $query->whereNotIn('status', OperationStatus::terminalValues())->orWhereNotNull('lock_key')
                ->orWhere('finished_at', '>', now()->subMinutes(self::UnconfirmedMinutes)))
            ->get()->contains(static fn (Operation $other): bool => self::destroySlot($other) === $slot
                && (! $other->status->terminal() || self::keepsNodes($other)));
    }

    /** @return list<int> */
    private static function waitingNodes(Operation $waiter): array
    {
        $nodes = $waiter->state['hold_nodes'] ?? [];

        return is_array($nodes) ? array_values(array_filter($nodes, is_int(...))) : [];
    }

    /** @param list<int> $nodes */
    private function olderWaiterOverlaps(int $operationId, array $nodes): bool
    {
        foreach ($this->waiters($operationId) as $waiter) {
            if (! self::afterOpen($waiter) && ! self::slotOpen($waiter) && array_intersect($nodes, self::waitingNodes($waiter)) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Takes the Nodes of an Operation's Route-publish step, waiting up to `$waitSeconds` for the Nodes of a write that holds them. Returns
     * the Nodes it holds, or null when they stayed busy. A Route write that waits for the same Nodes goes first.
     *
     * @return list<int>|null
     */
    public function holdStep(Operation $operation, int $waitSeconds): ?array
    {
        if (! self::holdsPerStep($operation->kind)) {
            return null;
        }
        $nodes = self::nodes($operation);
        $deadline = microtime(true) + $waitSeconds;
        while ($nodes !== []) {
            // A call repeated after a lost answer finds its own hold: renew it, whoever waits.
            if ($this->holdsAll($operation->id, $nodes)) {
                DB::table('route_publication_holds')->where('operation_id', $operation->id)->whereIn('node_id', $nodes)->update(['lease_until' => now()->addSeconds(self::LeaseSeconds)]);

                return $nodes;
            }
            if (! $this->waitingOverlaps($nodes) && $this->acquire($operation->id, $nodes, now()->addSeconds(self::LeaseSeconds))) {
                return $nodes;
            }
            if (microtime(true) >= $deadline) {
                return null;
            }
            usleep(250_000);
        }

        return [];
    }

    /** Gives back the Nodes of the Operation's Route-publish step, then asks for the waiting writes to start. */
    public function releaseStep(Operation $operation): void
    {
        if (DB::table('route_publication_holds')->where('operation_id', $operation->id)->whereNotNull('lease_until')->delete() > 0) {
            PromoteRouteWrites::dispatch()->afterCommit();
        }
    }

    /**
     * Gives back the Nodes a clone's create took at admission once its `clone-route` step has succeeded, in the caller's transaction
     * that activates the Route, then asks for the waiting writes to start (ClonePublication).
     */
    public function releasePublished(Operation $operation): void
    {
        // A waiting write that renders at its admission is refused at its start, because the new site changed what its Nodes serve.
        $freed = DB::table('route_publication_holds')->where('operation_id', $operation->id)->whereNull('lease_until')->delete()
            + DB::table('route_withdrawal_holds')->where('operation_id', $operation->id)->delete();
        if ($freed > 0) {
            PromoteRouteWrites::dispatch()->afterCommit();
        }
    }

    /** A write whose Caddy input is fresh when it starts: a create that renders again at promotion, or a removal that composes from the live file. */
    private static function rendersAtStart(Operation $operation): bool
    {
        return InstanceDestroyKind::removes($operation->kind)
            || (in_array($operation->kind, self::RefreshedKinds, true) && isset($operation->input['route_create']));
    }

    /** Gives back the Nodes a removal took at admission, once its Route is withdrawn, then asks for the waiting writes to start. */
    public function releaseWithdrawn(Operation $operation): void
    {
        if (self::releasesAfterWithdraw($operation->kind)
            && DB::table('route_withdrawal_holds')->where('operation_id', $operation->id)->delete() > 0) {
            PromoteRouteWrites::dispatch()->afterCommit();
        }
    }

    /** @param list<int> $nodes */
    private function holdsAll(int $operationId, array $nodes): bool
    {
        return DB::table('route_publication_holds')->where('operation_id', $operationId)->whereIn('node_id', $nodes)->whereNotNull('lease_until')->count() === count($nodes);
    }

    /** @param list<int> $nodes */
    private function waitingOverlaps(array $nodes): bool
    {
        foreach ($this->waiters() as $waiter) {
            if (! self::afterOpen($waiter) && ! self::slotOpen($waiter) && array_intersect($nodes, self::waitingNodes($waiter)) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Takes the Nodes of an admitted write: shared among removals and Caddy-only clone creates, alone for every other Route write.
     * Null when it holds them, else why not (ClonePublication's reason codes).
     *
     * @param  list<int>  $nodes
     */
    private function take(Operation $operation, array $nodes): ?string
    {
        if (self::sharesNodes($operation)) {
            return $this->share($operation->id, $nodes, InstanceDestroyKind::removes($operation->kind));
        }

        return $this->acquire($operation->id, $nodes) ? null : ClonePublication::NodesHeld;
    }

    /**
     * The shared writers that hold each of the Nodes now.
     *
     * @param  list<int>  $nodes
     * @return array<int, int>
     */
    private static function sharers(array $nodes): array
    {
        $sharers = [];
        foreach ($nodes as $node) {
            $sharers[$node] = DB::table('route_withdrawal_holds')->where('node_id', $node)->count();
        }

        return $sharers;
    }

    /**
     * All the Nodes or none, beside at most `SharedWriters - 1` other shared writers, and a removal beside at most `SharedRemovals - 1`
     * other removals: refused while a publisher holds one of them. The
     * IMMEDIATE transaction makes the check and the insert one step against a publisher's `acquire`. Null when it holds them, else
     * why not: `nodes_held`, `share_cap` or, for a removal, `removal_cap` or `removal_slot`.
     *
     * @param  list<int>  $nodes
     */
    private function share(int $operationId, array $nodes, bool $removal): ?string
    {
        DB::table('route_publication_holds')->whereNotNull('lease_until')->where('lease_until', '<', now())->whereIn('node_id', $nodes)->delete();

        return DB::transaction(static function () use ($operationId, $nodes, $removal): ?string {
            if (DB::table('route_publication_holds')->whereIn('node_id', $nodes)->where('operation_id', '!=', $operationId)->exists()) {
                return ClonePublication::NodesHeld;
            }
            $full = DB::table('route_withdrawal_holds')->whereIn('node_id', $nodes)->where('operation_id', '!=', $operationId)
                ->groupBy('node_id')->havingRaw('count(*) >= ?', [self::SharedWriters])->exists();
            if ($full) {
                return ClonePublication::ShareCap;
            }
            $removals = $removal && DB::table('route_withdrawal_holds')->join('operations', 'operations.id', '=', 'route_withdrawal_holds.operation_id')
                ->whereIn('route_withdrawal_holds.node_id', $nodes)->where('route_withdrawal_holds.operation_id', '!=', $operationId)
                ->whereIn('operations.kind', InstanceDestroyKind::Removals)->groupBy('route_withdrawal_holds.node_id')->havingRaw('count(*) >= ?', [self::SharedRemovals])->exists();
            if ($removals) {
                return self::RemovalCap;
            }
            if ($removal && self::slotTaken($operationId, $nodes)) {
                return self::RemovalSlot;
            }
            foreach ($nodes as $node) {
                DB::table('route_withdrawal_holds')->insertOrIgnore(['node_id' => $node, 'operation_id' => $operationId, 'created_at' => now()]);
            }

            return null;
        });
    }

    /**
     * Another removal on one of the Nodes withdraws a Route of the same slot as this removal's.
     *
     * @param  list<int>  $nodes
     */
    private static function slotTaken(int $operationId, array $nodes): bool
    {
        $slot = self::withdrawalSlot(Operation::query()->findOrFail($operationId)->input);
        if ($slot === null) {
            return false;
        }
        $others = DB::table('route_withdrawal_holds')->join('operations', 'operations.id', '=', 'route_withdrawal_holds.operation_id')
            ->whereIn('route_withdrawal_holds.node_id', $nodes)->where('route_withdrawal_holds.operation_id', '!=', $operationId)
            ->whereIn('operations.kind', InstanceDestroyKind::Removals)->distinct()->pluck('route_withdrawal_holds.operation_id')->all();

        return Operation::query()->whereKey($others)->get()->contains(static fn (Operation $other): bool => self::withdrawalSlot($other->input) === $slot);
    }

    /**
     * The slot of a removal's withdrawal steps: the first Route its restore stage lists, as route-withdraw names them. Null without one.
     *
     * @param  array<mixed>|null  $value
     */
    public static function withdrawalSlot(?array $value): ?int
    {
        foreach ($value ?? [] as $key => $child) {
            if ($key === 'route_mutations' && is_array($child)) {
                $route = data_get($child, 'restore.routes.0.route_id');

                return is_int($route) ? $route % self::WithdrawalSlots : null;
            }
            if (is_array($child) && ($slot = self::withdrawalSlot($child)) !== null) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * All the Nodes or none: the primary key refuses a Node that another Operation holds, and a removal's shared hold refuses it too.
     * A hold with a lease that has run out is free.
     *
     * @param  list<int>  $nodes
     */
    private function acquire(int $operationId, array $nodes, ?CarbonInterface $lease = null): bool
    {
        DB::table('route_publication_holds')->whereNotNull('lease_until')->where('lease_until', '<', now())->whereIn('node_id', $nodes)->delete();
        try {
            return DB::transaction(static function () use ($operationId, $nodes, $lease): bool {
                if (DB::table('route_withdrawal_holds')->whereIn('node_id', $nodes)->where('operation_id', '!=', $operationId)->exists()) {
                    return false;
                }
                foreach ($nodes as $node) {
                    DB::table('route_publication_holds')->insert(['node_id' => $node, 'operation_id' => $operationId, 'created_at' => now(), 'lease_until' => $lease]);
                }

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // A promotion, or a repeated call of the same step, that already took them is not a conflict.
            $held = DB::table('route_publication_holds')->where('operation_id', $operationId)->whereIn('node_id', $nodes);
            if ($lease !== null && (clone $held)->count() === count($nodes)) {
                $held->update(['lease_until' => $lease]);
            }

            return (clone $held)->count() === count($nodes);
        }
    }

    /**
     * Waiting to queued under the row lock. Another promotion may have started the waiter meanwhile, and it keeps its Nodes; one that
     * was cancelled meanwhile gives them back.
     */
    private function queue(Operation $waiter): bool
    {
        return DB::transaction(function () use ($waiter): bool {
            $current = Operation::query()->lockForUpdate()->findOrFail($waiter->id);
            if ($current->status !== OperationStatus::Waiting) {
                if ($current->status->terminal() && ! self::keepsNodes($current)) {
                    DB::table('route_publication_holds')->where('operation_id', $current->id)->delete();
                    DB::table('route_withdrawal_holds')->where('operation_id', $current->id)->delete();
                }

                return false;
            }
            // The budget of the write counts from its start, not from its admission.
            app(OperationStore::class)->change($current, ['status' => OperationStatus::Queued, 'started_at' => now()]);
            $waiter->setRawAttributes($current->getAttributes(), true);

            return true;
        });
    }

    /** @param list<Operation> $operations */
    private function start(array $operations): void
    {
        foreach ($operations as $operation) {
            if (self::stale($operation)) {
                app(StartSwampRun::class)->refuse($operation, new ResourceOperationException('route.publication_stale',
                    'A Route site on a Node of this write changed while it waited; run the command again.', 409));

                continue;
            }
            try {
                $this->refreshPublication($operation);
            } catch (Throwable $exception) {
                // A failed render never dispatches a run and gives its Nodes back to the next waiter.
                app(StartSwampRun::class)->refuse($operation, $exception instanceof ResourceOperationException ? $exception
                    : new ResourceOperationException('caddy.render_failed', 'The waiting Route publication could not be rendered.', 409));

                continue;
            }
            try {
                app(SwampDispatch::class)->start($operation);
            } catch (Throwable $exception) {
                // The start records its own failure on the Operation, which releases the Nodes; the others still start.
                Log::warning('A waiting Route write could not be started.', ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
            }
        }
    }

    /** Only unstarted creates can replace their whole publication from the current catalog; their resource intent stays fixed. */
    private function refreshPublication(Operation $operation): void
    {
        if (! in_array($operation->kind, self::RefreshedKinds, true) || ! isset($operation->input['route_create'])) {
            return;
        }
        DB::transaction(function () use ($operation): void {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($current->status !== OperationStatus::Queued || isset($current->state['swamp_run_id'])) {
                return;
            }
            $input = $current->input;
            $plan = ValidatedData::object($input['route_create']);
            $route = Route::query()->findOrFail(ValidatedData::integer($plan['route_id']));
            // A restore publishes its retiring Route with its removing target active, as at its admission.
            $route = $current->kind === 'instance.restore' ? StartInstanceRestoreAction::candidate($route) : $route;
            $lifecycle = ValidatedData::object($input['route_lifecycle']);
            $input['route_create'] = app(NativeRouteCreatePlan::class)->refreshCaddy($route, $plan,
                $current->kind === 'instance.create', ($lifecycle['public_activated'] ?? false) === true);
            if (is_array($input['direct_provisioning'] ?? null)) {
                $input['direct_provisioning']['route'] = $input['route_create'];
            }
            app(OperationStore::class)->change($current, ['input' => $input]);
            $operation->setRawAttributes($current->getAttributes(), true);
        });
    }
}
