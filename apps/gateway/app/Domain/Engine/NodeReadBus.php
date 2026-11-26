<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use App\Domain\Executor\SignedExecutorOrder;
use RuntimeException;

/**
 * A signed read on the machine bus (docs/reference/executor.md#signed-reads): one order sent as a core NATS request to the Node's
 * `orbit.node.<node>.reads`, answered by its executor's result line. A read changes nothing, so it needs no stream, no Operation
 * and no receipt. Every call ends by its `$deadline` (`hrtime` seconds), the read's own request budget, on a connection of its
 * own. `App\Infrastructure\Engine\EngineBus` is the NATS side.
 */
interface NodeReadBus
{
    /**
     * The actions the Node's executor last reported; empty when none is fresh or the bus does not answer by `$deadline`.
     *
     * @return list<string>
     */
    public function actions(int $node, float $deadline): array;

    /**
     * Content for one read's order, kept under `n<node>.<sha256>` as `MachineBus::putContent` keeps it.
     *
     * @return array{sha256: string}
     *
     * @throws RuntimeException when it is not stored by `$deadline`.
     */
    public function content(int $node, string $content, float $deadline): array;

    /**
     * The executor's result line, or null when none came by `$deadline` (a reply after it is dropped).
     *
     * @return array<string, mixed>|null
     *
     * @throws RuntimeException `engine.read_failed` when the connection failed, which may be after the order was sent.
     */
    public function read(int $node, SignedExecutorOrder $order, float $deadline): ?array;
}
