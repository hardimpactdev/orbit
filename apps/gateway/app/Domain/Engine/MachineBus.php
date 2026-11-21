<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use App\Domain\Executor\SignedExecutorOrder;

/**
 * What the state machine needs from the machine bus (ADR 0271). `App\Infrastructure\Engine\EngineBus` is the NATS JetStream
 * side; every publish waits for the store's acknowledgement and throws when it does not come.
 */
interface MachineBus
{
    public function publishOrder(int $node, SignedExecutorOrder $order): void;

    public function publishStart(int $operation): void;

    public function scheduleDeadline(int $operation, string $step, int $sends, int $atMs): void;

    /**
     * Delivers an immediate deadline event to the engine, once per `$id`: a cancel, or the reconciler's nudge of an Operation
     * whose start or order may have been lost.
     */
    public function nudge(int $operation, string $id): void;

    /** @return array{sha256: string} */
    public function putContent(int $node, string $content): array;

    /** @return list<string> */
    public function nodeActions(int $node): array;

    /**
     * Enabled actions on `$node` that accept `<node>:<part>` targets (from the Node's `node_targets` capability report).
     *
     * @return list<string>
     */
    public function nodeTargetActions(int $node): array;

    /** Whether the Node's executor last reported its clock as synchronized; null without a fresh report that says. */
    public function nodeClock(int $node): ?bool;
}
