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

    /** @return array{sha256: string} */
    public function putContent(int $node, string $content): array;

    /** @return list<string> */
    public function nodeActions(int $node): array;
}
