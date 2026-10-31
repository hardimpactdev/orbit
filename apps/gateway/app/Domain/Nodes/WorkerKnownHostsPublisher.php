<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

/** Publish the Gateway's recorded public pins through the shared native intent. */
interface WorkerKnownHostsPublisher
{
    public function publish(): void;
}
