<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use App\Domain\Executor\SignedExecutorOrder;

/**
 * A signed read on the machine bus (docs/reference/executor.md#signed-reads): one order sent as a core NATS request to the Node's
 * `orbit.node.<node>.reads`, answered by its executor's result line. A read changes nothing, so it needs no stream, no Operation
 * and no receipt. `App\Infrastructure\Engine\EngineBus` is the NATS side.
 */
interface NodeReadBus
{
    /**
     * The executor's result line, or null when no reply came within `$seconds`.
     *
     * @return array<string, mixed>|null
     *
     * @throws \RuntimeException `engine.read_failed` when the request could not be sent.
     */
    public function read(int $node, SignedExecutorOrder $order, float $seconds): ?array;
}
