<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Engine\EngineBus;
use Illuminate\Console\Command;

/** Makes the machine bus's streams; idempotent. The consumer process makes them too when it starts; this command serves an operator (ADR 0271). */
final class EngineSetupCommand extends Command
{
    protected $signature = 'orbit:engine-setup';

    protected $description = 'Make the NATS JetStream streams of the Gateway state machine';

    public function handle(EngineBus $bus): int
    {
        $bus->setup();
        $this->line('orbit:engine-setup: streams ORDERS, RECEIPTS, ENGINE, KV_CONTENT and KV_NODES are ready');

        return self::SUCCESS;
    }
}
