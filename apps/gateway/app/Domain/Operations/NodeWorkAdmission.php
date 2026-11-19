<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Actions\GatewayReleases\WaitForGatewayReleaseIdleAction;
use App\Domain\Instances\InstanceDestroyKind;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Serializes automatic convergence, the release pass's role converge and its full converge for changed host inputs, with Instance, Conn
 * and Process work on the Nodes they share. Task work never conflicts.
 */
final readonly class NodeWorkAdmission
{
    /** A refused automatic role converge holds its place this long; the release pass renews it on each retry. */
    public const int WaitSeconds = 60;

    /** The longest a refused automatic role converge waits for its holders. Then it is admitted beside them, as a hand converge is. */
    public const int WaitLimitSeconds = 300;

    /** How many steps back a continuation is traced to a holder. */
    private const int MaxLineage = 4;

    /**
     * A refused automatic role converge queues: new Instance, Conn and Process work on the Node waits behind it, so it starts once the
     * current holders end. A removal goes on, also beside a running automatic converge, and so does a holder's continuation: an Operation whose parent, or whose input
     * `*_operation_id` (such as the completion of a create), leads back to a holder, step by step. New work on a holder's target waits
     * like any other. The wait is bounded: after WaitLimitSeconds the converge is admitted beside the holders, and it records them in
     * its state as `admitted_beside`, so their continuations go on while it runs. Task work (`tasks.*`: copy turns, checks,
     * publishes, syncs, cancels) runs inside its copy and neither waits nor holds.
     *
     * @param  array<string,mixed>  $input
     * @return list<int> the holders an automatic role converge is admitted beside, for its `admitted_beside` state
     */
    public function check(string $kind, int $nodeId, array $input, ?int $parentId = null): array
    {
        $patterns = [...WaitForGatewayReleaseIdleAction::BlockingKindPatterns, 'instance.transfer'];
        $roles = $kind === 'node.converge' && data_get($input, '_converge.intent.automatic') === true;
        // A removal is accepted at once and never waits for a converge, waiting or running: its Caddy writes take the Node's publication
        // lock and check the live file before the rename, as a converge admitted beside Instance work relies on too.
        if (! $roles && InstanceDestroyKind::removes($kind)) {
            return [];
        }
        if (! $roles && ! Str::is(array_map(static fn (string $pattern): string => str_replace('%', '*', $pattern), $patterns), $kind)) {
            return [];
        }

        $nodes = $roles ? [$nodeId] : array_values(array_unique([$nodeId, ...ValidatedData::integerList($input['participant_node_ids'] ?? [])]));
        Node::query()->whereKey($nodes)->orderBy('id')->lockForUpdate()->get();
        $lineage = $roles ? [] : self::lineage($parentId, $input);
        if (! $roles && array_any($nodes, static fn (int $id): bool => ($wait = self::wait($id)) !== null
            && array_intersect($lineage, $wait['holders']) === [])) {
            throw new ResourceOperationException('operation.target_busy', 'An automatic role converge waits for this Node.', 409);
        }
        $open = Operation::query()->where(static fn (Builder $query) => $query
            ->whereNotIn('status', OperationStatus::terminalValues())
            ->orWhereNotNull('lock_key')
            ->orWhere(static fn (Builder $unsettled) => $unsettled->where('error_code', 'operation.timed_out')
                ->where('state->native_cancel_requested_at', '>', now()->subMinutes(NativeRunCancellation::ForwardMinutes)->toISOString())
                ->whereNull('state->native_cancel_confirmed_at')));
        if ($roles) {
            $open->where(static fn (Builder $query) => $query->where('node_id', $nodeId)->orWhereJsonContains('input->participant_node_ids', $nodeId))
                ->where(static function (Builder $query) use ($patterns): void {
                    foreach ($patterns as $pattern) {
                        $query->orWhere('kind', 'like', $pattern);
                    }
                });
        } else {
            $open->whereIn('node_id', $nodes)->where('kind', 'node.converge')->where('input->_converge->intent->automatic', true);
        }
        $cancel = app(NativeRunCancellation::class);
        // A running converge admitted beside a holder lets that holder's continuation finish, as the wait did.
        $holders = $open->get()->filter(static fn (Operation $operation): bool => (! $operation->status->terminal()
            || $operation->lock_key !== null || $cancel->awaitsForwarding($operation))
            && ($roles || array_intersect($lineage, ValidatedData::integerList($operation->state['admitted_beside'] ?? [])) === []));
        $ids = array_values($holders->map(static fn (Operation $operation): int => $operation->id)->all());
        if ($roles) {
            $wait = self::mark($nodeId);
            $since = $wait === null ? now() : Carbon::parse($wait['since']);
            if ($holders->isEmpty() || $since->lte(now()->subSeconds(self::WaitLimitSeconds))) {
                Cache::forget(self::waitKey($nodeId));

                return $ids;
            }
            Cache::put(self::waitKey($nodeId), ['since' => $since->toISOString(),
                'holders' => array_values(array_unique([...($wait['holders'] ?? []), ...$ids]))], self::WaitSeconds);
        }
        if ($holders->isNotEmpty()) {
            throw new ResourceOperationException('operation.target_busy', 'Automatic role convergence and Instance work share a Node.', 409,
                details: ['operation_ids' => $ids]);
        }

        return [];
    }

    /**
     * The Operations that new work continues: its parent and the Operations its input names (`*_operation_id`), then theirs, up to
     * MaxLineage steps, such as a deploy after the completion of a create.
     *
     * @param  array<string,mixed>  $input
     * @return list<int>
     */
    private static function lineage(?int $parentId, array $input): array
    {
        $lineage = [];
        $next = self::predecessors($parentId, $input);
        for ($step = 0; $next !== [] && $step < self::MaxLineage; $step++) {
            $lineage = [...$lineage, ...$next];
            $next = [];
            foreach (Operation::query()->whereKey(array_values(array_unique($lineage)))->get(['id', 'parent_operation_id', 'input']) as $operation) {
                $next = [...$next, ...self::predecessors($operation->parent_operation_id, (array) $operation->input)];
            }
            $next = array_values(array_diff(array_unique($next), $lineage));
        }

        return array_values(array_unique($lineage));
    }

    /**
     * @param  array<array-key,mixed>  $input
     * @return list<int>
     */
    private static function predecessors(?int $parentId, array $input): array
    {
        return array_values(array_filter([$parentId, ...array_values(array_filter($input,
            static fn (mixed $value, int|string $key): bool => str_ends_with((string) $key, '_operation_id') && is_int($value),
            ARRAY_FILTER_USE_BOTH))]));
    }

    /**
     * The wait of a refused automatic role converge on the Node, while it may still hold new work back.
     *
     * @return array{since: string, holders: list<int>}|null
     */
    private static function wait(int $nodeId): ?array
    {
        $wait = self::mark($nodeId);

        return $wait === null || Carbon::parse($wait['since'])->lte(now()->subSeconds(self::WaitLimitSeconds)) ? null : $wait;
    }

    /** @return array{since: string, holders: list<int>}|null */
    private static function mark(int $nodeId): ?array
    {
        $wait = Cache::get(self::waitKey($nodeId));

        return is_array($wait) && is_string($wait['since'] ?? null)
            ? ['since' => $wait['since'], 'holders' => ValidatedData::integerList($wait['holders'] ?? [])] : null;
    }

    private static function waitKey(int $nodeId): string
    {
        return 'node-work-admission:role-wait:'.$nodeId;
    }
}
