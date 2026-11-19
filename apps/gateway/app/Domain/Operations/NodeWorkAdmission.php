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
     * A refused automatic role converge queues: new Instance and task work on the Node waits behind it, so it starts once the
     * current holders end. The holders' own work goes on: a child Operation, or work on a target that a holder held, such as
     * the completion of a create.
     *
     * @param  array<string,mixed>  $input
     * @param  string|null  $target  `type:id` of the Operation being admitted
     */
    public function check(string $kind, int $nodeId, array $input, ?string $target = null, bool $child = false): void
    {
        $patterns = [...WaitForGatewayReleaseIdleAction::BlockingKindPatterns, 'instance.transfer', 'tasks.%'];
        $roles = $kind === 'node.converge' && data_get($input, '_converge.intent.release_roles_only') === true;
        if (! $roles && ! Str::is(array_map(static fn (string $pattern): string => str_replace('%', '*', $pattern), $patterns), $kind)) {
            return;
        }

        $nodes = $roles ? [$nodeId] : array_values(array_unique([$nodeId, ...ValidatedData::integerList($input['participant_node_ids'] ?? [])]));
        Node::query()->whereKey($nodes)->orderBy('id')->lockForUpdate()->get();
        if (! $roles && ! $child && array_any($nodes, static fn (int $id): bool => ($holders = Cache::get(self::waitKey($id))) !== null
            && ! in_array($target, (array) $holders, true))) {
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
        $holders = $open->get()->filter(static fn (Operation $operation): bool => ! $operation->status->terminal()
            || $operation->lock_key !== null || $cancel->awaitsForwarding($operation));
        if ($roles) {
            $holders->isEmpty() ? Cache::forget(self::waitKey($nodeId)) : Cache::put(self::waitKey($nodeId), array_values(array_unique([
                ...ValidatedData::stringList(Cache::get(self::waitKey($nodeId), [])),
                ...$holders->map(static fn (Operation $operation): string => $operation->target_type.':'.$operation->target_id),
            ])), self::WaitSeconds);
        }
        if ($holders->isNotEmpty()) {
            throw new ResourceOperationException('operation.target_busy', 'Automatic role convergence and Instance or task work share a Node.', 409);
        }
    }

    private static function waitKey(int $nodeId): string
    {
        return 'node-work-admission:role-wait:'.$nodeId;
    }
}
