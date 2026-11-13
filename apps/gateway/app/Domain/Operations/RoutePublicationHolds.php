<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Actions\Instances\StartInstanceRestoreAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Routes\NativeRouteCreatePlan;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Jobs\Operations\PromoteRouteWrites;
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
 * The DNS Node is not held: its records are keyed.
 */
final class RoutePublicationHolds
{
    /** @var list<string> */
    private const array Kinds = ['instance.create', 'instance.destroy', 'instance.clone', 'instance.transfer', 'instance.migrate',
        'instance.restore', 'route.create', 'route.update', 'route.destroy', 'route.target_set'];

    /** A timed-out write that Swamp never confirmed stopped gives its Nodes back after this long. */
    private const int UnconfirmedMinutes = 15;

    /**
     * Kinds that publish Route sites inside a longer run. They hold the Nodes of their Route-publish step only: the run asks for
     * the hold before that step and gives it back after it, and the hold has a lease.
     *
     * @var list<string>
     */
    private const array StepKinds = ['tasks.provision', 'tasks.cancel', 'tasks.maintenance_finish', 'instance.register', 'cluster.router_set', 'cluster.router_unset'];

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
        return $kind === 'instance.destroy';
    }

    /** Whether the terminal state of the kind has holds to free. */
    public static function tracks(string $kind): bool
    {
        return self::applies($kind) || self::holdsPerStep($kind);
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

    /** True when the Operation may start now. Otherwise it is set to `waiting` and starts when `promote` finds its Nodes free. */
    public function admit(Operation $operation): bool
    {
        // A kind that holds per step takes no Node at admission: its run calls for the hold around its Route-publish step.
        $nodes = self::applies($operation->kind) ? self::nodes($operation) : [];
        if ($nodes === []) {
            return true;
        }
        if (! $this->olderWaiterOverlaps($operation->id, $nodes) && $this->acquire($operation->id, $nodes)) {
            return true;
        }
        app(OperationStore::class)->change($operation, ['status' => OperationStatus::Waiting,
            'state' => [...($operation->state ?? []), 'hold_nodes' => $nodes]]);

        return false;
    }

    /** Frees the Nodes of a finished Operation, then asks for the waiting ones to start, after the caller's transaction. */
    public function release(int $operationId, bool $wasWaiting = false): void
    {
        $freed = DB::table('route_publication_holds')->where('operation_id', $operationId)->delete();
        if ($freed > 0 || $wasWaiting) {
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
        foreach (DB::table('route_publication_holds')->distinct()->pluck('operation_id') as $id) {
            $operation = Operation::query()->whereKey($id)->first();
            if ($operation === null || ($operation->status->terminal() && ! self::keepsNodes($operation))) {
                $freed += DB::table('route_publication_holds')->where('operation_id', $id)->delete();
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
            $nodes = self::waitingNodes($waiter);
            if (array_intersect($nodes, $blocked) !== [] || ! $this->acquire($waiter->id, $nodes)) {
                $blocked = [...$blocked, ...$nodes];

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
            ->filter(static fn (Operation $waiter): bool => self::waitingNodes($waiter) !== [])->values();
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
            if (array_intersect($nodes, self::waitingNodes($waiter)) !== []) {
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

    /** Gives back the Nodes a removal took at admission, once its Route is withdrawn, then asks for the waiting writes to start. */
    public function releaseWithdrawn(Operation $operation): void
    {
        if (self::releasesAfterWithdraw($operation->kind)
            && DB::table('route_publication_holds')->where('operation_id', $operation->id)->whereNull('lease_until')->delete() > 0) {
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
            if (array_intersect($nodes, self::waitingNodes($waiter)) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * All the Nodes or none: the primary key refuses a Node that another Operation holds. A hold with a lease that has run out is free.
     *
     * @param  list<int>  $nodes
     */
    private function acquire(int $operationId, array $nodes, ?CarbonInterface $lease = null): bool
    {
        DB::table('route_publication_holds')->whereNotNull('lease_until')->where('lease_until', '<', now())->whereIn('node_id', $nodes)->delete();
        try {
            DB::transaction(static function () use ($operationId, $nodes, $lease): void {
                foreach ($nodes as $node) {
                    DB::table('route_publication_holds')->insert(['node_id' => $node, 'operation_id' => $operationId, 'created_at' => now(), 'lease_until' => $lease]);
                }
            });

            return true;
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
        if (! in_array($operation->kind, ['instance.create', 'instance.clone', 'instance.restore', 'route.create'], true) || ! isset($operation->input['route_create'])) {
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
