<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Engine\Engine;
use App\Domain\Engine\NodeBusAccess;
use App\Infrastructure\Engine\EngineBus;
use Illuminate\Console\Command;

/**
 * The state machine's one long-running process (ADR 0271): it applies receipts, start events and deadlines as JetStream
 * delivers them, and acknowledges each one only after its transition. It also answers the bus's auth callout for Node
 * connections. `orbit-gateway-engine.service` runs it.
 */
final class EngineConsumeCommand extends Command
{
    protected $signature = 'orbit:engine-consume';

    protected $description = 'Apply executor receipts and state machine events from NATS JetStream';

    public function handle(EngineBus $bus, Engine $engine): never
    {
        $bus->setup();
        $this->line('orbit:engine-consume: consuming receipts and engine events');
        $bus->consume(
            static fn (array $receipt) => $engine->onReceipt($receipt),
            static fn (string $subject, array $event) => $engine->onEvent($subject, $event),
            NodeBusAccess::authorize(...),
        );
    }
}
