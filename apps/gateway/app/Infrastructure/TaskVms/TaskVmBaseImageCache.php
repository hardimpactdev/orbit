<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmHost;
use App\Models\Node;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The `orbit-task-base` of each task VM host as a claim reads it, over SSH at most every 10 seconds. After a failed
 * read, the host's warm VMs wait for 60 seconds, so a host that hangs costs one 15-second read a minute, not one per
 * claim. A promote forgets the reading, so a claim right after a build or sync reads the new image.
 */
final readonly class TaskVmBaseImageCache
{
    /** The image read at a claim runs in the scheduler tick, so it gets a short limit. */
    private const float ReadSeconds = 15.0;

    private const int CacheSeconds = 10;

    private const int FailureSeconds = 60;

    public function __construct(private IncusHost $incus) {}

    /** The fingerprint that the host's `orbit-task-base` names, or null while it cannot be read. */
    public function current(TaskVmHost $host, Node $node): ?string
    {
        $key = self::key($node->id);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return is_string($cached['image'] ?? null) ? $cached['image'] : null;
        }
        try {
            $image = $this->incus->aliasTarget($host, $node, TaskVmHost::BaseImage, self::ReadSeconds);
        } catch (Throwable $exception) {
            Log::warning('A claim could not read the base image of a task VM host; its warm VMs wait a minute.', ['node_id' => $node->id, 'error' => $exception->getMessage()]);
            Cache::put($key, ['image' => null], self::FailureSeconds);

            return null;
        }
        Cache::put($key, ['image' => $image], self::CacheSeconds);

        return $image;
    }

    /** Forgets a host's reading, after its `orbit-task-base` moved. */
    public function forget(int $hostNodeId): void
    {
        Cache::forget(self::key($hostNodeId));
    }

    private static function key(int $hostNodeId): string
    {
        return 'task-vms:claim-image:'.$hostNodeId;
    }
}
