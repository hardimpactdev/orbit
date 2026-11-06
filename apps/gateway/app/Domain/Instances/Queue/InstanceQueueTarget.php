<?php

declare(strict_types=1);

namespace App\Domain\Instances\Queue;

use App\Models\DatabaseConnection;

/** Where an Instance keeps its queue, as its stored environment and registered connections say. */
final readonly class InstanceQueueTarget
{
    /**
     * @param  'redis'|'database'  $driver
     * @param  list<string>  $queues  The queue names the stored environment names; a Redis store adds the ones it holds.
     * @param  ?DatabaseConnection  $failed  The registered database connection that holds `failed_jobs`.
     */
    public function __construct(
        public string $driver,
        public DatabaseConnection $store,
        public ?DatabaseConnection $failed,
        public string $prefix,
        public array $queues,
    ) {}
}
