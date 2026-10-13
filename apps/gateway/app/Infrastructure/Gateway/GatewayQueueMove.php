<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Nodes\NodeProvisioningException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Moves the jobs that releases before the Gateway queue left in the database queues to the Gateway queue
 * (docs/reference/gateway-queue.md#releases). A delayed job keeps its time and its unique lock; its attempts start
 * again. The runtime handoff runs it after the old scheduler drained, so no old worker takes a job any more.
 * Remove it, with its call, once every Gateway runs a release with the Gateway queue.
 */
final readonly class GatewayQueueMove
{
    /** The database queues of the releases before, and where their jobs go. */
    public const array Queues = [
        'task-vms' => GatewayQueue::Tasks,
        'task-vm-git' => GatewayQueue::Tasks,
        'task-vm-templates' => GatewayQueue::Builds,
        'task-vm-images' => GatewayQueue::Builds,
    ];

    /**
     * @return int the jobs moved
     *
     * @throws NodeProvisioningException when a job cannot be read or queued; it stays in the database
     */
    public function move(): int
    {
        if (! GatewayQueueRedis::configured()) {
            return 0;
        }

        $moved = 0;
        $rows = DB::table('jobs')->whereIn('queue', array_keys(self::Queues))->orderBy('id')->get();

        foreach ($rows as $row) {
            $queue = self::Queues[(string) $row->queue]->value;

            try {
                $payload = json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR);
                $job = is_array($payload) ? unserialize((string) ($payload['data']['command'] ?? ''), ['allowed_classes' => true]) : false;

                if (! is_object($job) || ! method_exists($job, 'onQueue')) {
                    throw new \RuntimeException('The job payload holds no queueable job.');
                }

                $job->onConnection(GatewayQueue::Connection)->onQueue($queue);
                $delay = max(0, (int) $row->available_at - now()->getTimestamp());
                $target = Queue::connection(GatewayQueue::Connection);
                $delay > 0 ? $target->laterOn($queue, $delay, $job) : $target->pushOn($queue, $job);
            } catch (Throwable $exception) {
                throw new NodeProvisioningException(
                    step: 'queue-move',
                    errorCode: 'gateway.queue_move_failed',
                    message: "Database job [{$row->id}] on [{$row->queue}] could not be moved to the Gateway queue.",
                    previous: $exception,
                );
            }

            DB::table('jobs')->where('id', $row->id)->delete();
            $moved++;
        }

        return $moved;
    }
}
