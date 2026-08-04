<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\ProcessEvent;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Durable process_events cursor reader for cross-worker process SSE.
 *
 * Follow always starts after a connect-time high-water mark (snapshot cursor).
 * It never replays rows at or below that mark, regardless of Last-Event-ID.
 *
 * Tail scope is app_instance_id + workspace_id|null + node_id (not a frozen
 * process-id list), so processes configured after connect still stream.
 */
final readonly class ProcessEventStreamer
{
    public function __construct(
        private ProcessStreamRuntimeConfig $config,
        private ProcessStreamSleeper $sleeper,
    ) {}

    /**
     * @return Collection<int, ProcessEvent>
     */
    public function eventsAfter(ProcessStreamScope $scope, int $afterId): Collection
    {
        return $this->scopedQuery($scope)
            ->with(['process', 'node', 'project', 'appInstance', 'workspace'])
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->get();
    }

    /**
     * Highest durable process_events.id currently in scope, or 0 when none exist.
     *
     * Zero is a defined SSE snapshot id so native EventSource stores a cursor even
     * when the scope has no lifecycle rows yet.
     */
    public function highWaterMark(ProcessStreamScope $scope): int
    {
        $id = $this->scopedQuery($scope)->max('id');

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * Follow durable process_events after {@see $afterId} (exclusive).
     *
     * Each poll re-queries the scope filters so newly configured processes in
     * the same app instance/workspace/node are included as soon as they write
     * process_events rows.
     *
     * Yields {@see ProcessEvent} for ordered updates. Yields the string
     * {@code heartbeat} only when the heartbeat interval elapses without a new
     * row (independent of the faster DB poll cadence).
     *
     * @return Generator<int, ProcessEvent|'heartbeat'>
     */
    public function follow(
        ProcessStreamScope $scope,
        int $afterId,
        ?ProcessStreamRuntimeConfig $config = null,
    ): Generator {
        $config ??= $this->config;
        $idlePolls = 0;
        $lastHeartbeatAt = microtime(true);

        while (true) {
            if (connection_aborted() === 1) {
                return;
            }

            $events = $this->eventsAfter($scope, $afterId);

            if ($events->isNotEmpty()) {
                foreach ($events as $event) {
                    $afterId = $event->id;
                    $lastHeartbeatAt = microtime(true);

                    yield $event;
                }

                $idlePolls = 0;

                continue;
            }

            if ($config->maxIdlePolls !== null && $idlePolls >= $config->maxIdlePolls) {
                return;
            }

            $now = microtime(true);
            $elapsedMicros = (int) round(($now - $lastHeartbeatAt) * 1_000_000);

            if ($elapsedMicros >= $config->heartbeatMicroseconds) {
                $lastHeartbeatAt = $now;

                yield 'heartbeat';
            }

            $idlePolls++;
            $this->sleeper->sleep($config->pollMicroseconds);
        }
    }

    /**
     * @return Builder<ProcessEvent>
     */
    private function scopedQuery(ProcessStreamScope $scope): Builder
    {
        return ProcessEvent::query()
            ->where('app_instance_id', $scope->appInstanceId)
            ->where('node_id', $scope->nodeId)
            ->when(
                $scope->workspaceId !== null,
                static fn (Builder $query): Builder => $query->where('workspace_id', $scope->workspaceId),
                static fn (Builder $query): Builder => $query->whereNull('workspace_id'),
            );
    }
}
