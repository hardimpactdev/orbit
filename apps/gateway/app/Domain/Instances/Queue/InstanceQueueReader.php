<?php

declare(strict_types=1);

namespace App\Domain\Instances\Queue;

interface InstanceQueueReader
{
    /**
     * Reads the queue store and the failed jobs with read statements only.
     *
     * @param  'pending'|'failed'  $state
     * @return array{pending: int, failed: ?int, queues: list<array{name: string, length: int}>, jobs: list<array{id: string, name: string, queue: string, pushed_at: int|float|null, failed_at: ?string, exception: ?string}>}
     *                                                                                                                                                                                                                          The values come out of the application's data, so the caller treats them as untrusted.
     */
    public function read(InstanceQueueTarget $target, string $state, int $limit): array;
}
