<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Actions\Operations\ExpireOperationAction;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Operation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The hold of the Nodes that serve a Route write (docs/reference/routes.md#coordinate-publication). Admission takes every Node
 * of the write at once or none; a write that finds one held waits with status `waiting` and starts, oldest first, when the
 * Nodes are free. The terminal state releases them, except for a timed-out write that still awaits proof that its run ended.
 * The DNS Node is not held: its records are keyed.
 */
final class RoutePublicationHolds
{
    /** @var list<string> */
    private const array Kinds = ['instance.create', 'instance.destroy', 'instance.clone', 'instance.transfer',
        'route.create', 'route.update', 'route.destroy', 'route.target_set'];

    public static function applies(string $kind): bool
    {
        return in_array($kind, self::Kinds, true);
    }

    /**
     * The Nodes that serve the sites of the write: every `publications` entry of its Route mutations with `serves_routes`.
     * The entries sit at another path in each kind, so the input is walked.
     *
     * @return list<int>
     */
    public static function nodes(Operation $operation): array
    {
        if (! self::applies($operation->kind)) {
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
        $nodes = self::nodes($operation);
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

    /** Frees the Nodes of a finished Operation, then starts the waiting ones that fit. */
    public function release(int $operationId, bool $wasWaiting = false): void
    {
        $freed = DB::table('route_publication_holds')->where('operation_id', $operationId)->delete();
        if ($freed > 0 || $wasWaiting) {
            $this->start($this->promote());
        }
    }

    /**
     * The safety net of the minute pass: frees the Nodes of an Operation that is gone or finished without a release, then
     * starts the waiting ones that fit. Returns how many holds it freed.
     */
    public function sweep(): int
    {
        $freed = 0;
        foreach (DB::table('route_publication_holds')->distinct()->pluck('operation_id') as $id) {
            $operation = Operation::query()->whereKey($id)->first();
            if ($operation === null || ($operation->status->terminal() && ! ExpireOperationAction::awaitsSettlement($operation))) {
                $freed += DB::table('route_publication_holds')->where('operation_id', $id)->delete();
            }
        }
        $this->start($this->promote());

        return $freed;
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
        foreach (Operation::query()->where('status', OperationStatus::Waiting)->orderBy('id')->get() as $waiter) {
            $nodes = self::waitingNodes($waiter);
            if (array_intersect($nodes, $blocked) !== [] || ($nodes !== [] && ! $this->acquire($waiter->id, $nodes))) {
                $blocked = [...$blocked, ...$nodes];

                continue;
            }
            if ($this->queue($waiter)) {
                $promoted[] = $waiter;
            }
        }

        return $promoted;
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
        foreach (Operation::query()->where('status', OperationStatus::Waiting)->where('id', '<', $operationId)->get() as $waiter) {
            if (array_intersect($nodes, self::waitingNodes($waiter)) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * All the Nodes or none: the primary key refuses a Node that another Operation holds.
     *
     * @param  list<int>  $nodes
     */
    private function acquire(int $operationId, array $nodes): bool
    {
        try {
            DB::transaction(static function () use ($operationId, $nodes): void {
                foreach ($nodes as $node) {
                    DB::table('route_publication_holds')->insert(['node_id' => $node, 'operation_id' => $operationId, 'created_at' => now()]);
                }
            });

            return true;
        } catch (UniqueConstraintViolationException) {
            // A retry of a promotion that already took them is not a conflict.
            return DB::table('route_publication_holds')->where('operation_id', $operationId)->whereIn('node_id', $nodes)->count() === count($nodes);
        }
    }

    /** Waiting to queued under the row lock. A waiter that was cancelled meanwhile gives its Nodes back. */
    private function queue(Operation $waiter): bool
    {
        return DB::transaction(function () use ($waiter): bool {
            $current = Operation::query()->lockForUpdate()->findOrFail($waiter->id);
            if ($current->status !== OperationStatus::Waiting) {
                DB::table('route_publication_holds')->where('operation_id', $current->id)->delete();

                return false;
            }
            app(OperationStore::class)->change($current, ['status' => OperationStatus::Queued]);
            $waiter->setRawAttributes($current->getAttributes(), true);

            return true;
        });
    }

    /** @param list<Operation> $operations */
    private function start(array $operations): void
    {
        foreach ($operations as $operation) {
            try {
                app(SwampDispatch::class)->start($operation);
            } catch (Throwable $exception) {
                // The start records its own failure on the Operation, which releases the Nodes; the others still start.
                Log::warning('A waiting Route write could not be started.', ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
            }
        }
    }
}
