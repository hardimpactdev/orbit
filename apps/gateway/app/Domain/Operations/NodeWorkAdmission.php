<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Actions\GatewayReleases\WaitForGatewayReleaseIdleAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Serializes automatic role convergence with Instance and task work on the Nodes they share. */
final readonly class NodeWorkAdmission
{
    /** A refused automatic role converge holds its place this long; the release pass renews it on each retry. */
    public const int WaitSeconds = 60;

    /**
     * Queues an automatic role converge that open work refused: new Instance and task work on the Node waits behind it,
     * so it starts once the current holders end instead of losing the Node to each new Operation.
     */
    public function wait(int $nodeId): void
    {
        Cache::put(self::waitKey($nodeId), true, self::WaitSeconds);
    }

    public function stopWaiting(int $nodeId): void
    {
        Cache::forget(self::waitKey($nodeId));
    }

    /** @param array<string,mixed> $input */
    public function check(string $kind, int $nodeId, array $input): void
    {
        $patterns = [...WaitForGatewayReleaseIdleAction::BlockingKindPatterns, 'instance.transfer', 'tasks.%'];
        $roles = $kind === 'node.converge' && data_get($input, '_converge.intent.release_roles_only') === true;
        if (! $roles && ! Str::is(array_map(static fn (string $pattern): string => str_replace('%', '*', $pattern), $patterns), $kind)) {
            return;
        }

        $nodes = $roles ? [$nodeId] : array_values(array_unique([$nodeId, ...ValidatedData::integerList($input['participant_node_ids'] ?? [])]));
        Node::query()->whereKey($nodes)->orderBy('id')->lockForUpdate()->get();
        if (! $roles && array_any($nodes, static fn (int $id): bool => Cache::has(self::waitKey($id)))) {
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
            $open->whereIn('node_id', $nodes)->where('kind', 'node.converge')->where('input->_converge->intent->release_roles_only', true);
        }
        $cancel = app(NativeRunCancellation::class);
        if ($open->cursor()->contains(static fn (Operation $operation): bool => ! $operation->status->terminal()
            || $operation->lock_key !== null || $cancel->awaitsForwarding($operation))) {
            throw new ResourceOperationException('operation.target_busy', 'Automatic role convergence and Instance or task work share a Node.', 409);
        }
    }

    private static function waitKey(int $nodeId): string
    {
        return 'node-work-admission:role-wait:'.$nodeId;
    }
}
