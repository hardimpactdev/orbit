<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Models\TaskVm;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The wait of a host's pool after a failed pool VM: 1 minute after the first failure, doubling up to 1 hour. Each
 * failure logs an error. A pool VM that becomes `warm` clears the wait.
 */
final readonly class TaskVmPoolBackoff
{
    private const int BackoffSeconds = 60;

    private const int MaxBackoffSeconds = 3600;

    /** A pool VM failed: the host waits before its next pool VM, and the log says why and until when. */
    public function failed(TaskVm $vm, string $code, string $message): void
    {
        $failures = self::read((int) $vm->host_node_id)['failures'] + 1;
        $wait = min(self::MaxBackoffSeconds, self::BackoffSeconds * 2 ** min($failures - 1, 6));
        $until = now()->getTimestamp() + $wait;
        Cache::put(self::key((int) $vm->host_node_id), ['failures' => $failures, 'until' => $until], self::MaxBackoffSeconds * 2);
        Log::error('A warm task VM failed; its host creates no pool VM until the wait ends.', [
            'task_vm' => $vm->name, 'node_id' => $vm->host_node_id, 'error_code' => $code, 'error' => $message,
            'failures' => $failures, 'retry_at' => date(DATE_ATOM, $until),
        ]);
    }

    /** A pool VM became warm, so its host's failures are forgotten. */
    public function warmed(TaskVm $vm): void
    {
        Cache::forget(self::key((int) $vm->host_node_id));
    }

    /** The Unix time until which the host creates no pool VM, or 0. */
    public function until(int $hostNodeId): int
    {
        return self::read($hostNodeId)['until'];
    }

    /** @return array{failures: int, until: int} */
    private static function read(int $hostNodeId): array
    {
        $backoff = Cache::get(self::key($hostNodeId));

        return [
            'failures' => is_array($backoff) && is_int($backoff['failures'] ?? null) ? $backoff['failures'] : 0,
            'until' => is_array($backoff) && is_int($backoff['until'] ?? null) ? $backoff['until'] : 0,
        ];
    }

    private static function key(int $hostNodeId): string
    {
        return 'task-vms:pool-backoff:'.$hostNodeId;
    }
}
