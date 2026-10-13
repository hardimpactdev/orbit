<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

/** What the Horizon unit's convergence reads and signals on the Gateway host (docs/reference/gateway-queue.md#releases). */
interface GatewayHorizonRuntime
{
    /** @return list<int> the process ids of the running `task-vms` workers */
    public function taskVmWorkers(): array;

    public function running(int $pid): bool;

    /** @return list<string> the names of the Horizon masters that Redis knows */
    public function masters(): array;

    /** Pauses the `task-vms` queue, at most for the given seconds, so a lost resume never stops it for good. */
    public function pauseTaskVms(int $seconds): void;

    public function resumeTaskVms(): void;

    /** Sends SIGTERM to the running Horizon masters, as `php artisan horizon:terminate` does. */
    public function terminate(): void;

    public function sleep(int $milliseconds): void;
}
