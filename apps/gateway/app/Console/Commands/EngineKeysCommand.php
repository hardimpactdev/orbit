<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Engine\NatsCredentials;
use Illuminate\Console\Command;

/**
 * Makes the machine bus's account seed (ADR 0271; docs/reference/gateway-engine.md#authentication) and prints it as a Gateway
 * environment line. The operator adds it to `gateway.env` and converges the gateway role. Nodes hold no credential, so a new
 * seed needs no Node converge.
 */
final class EngineKeysCommand extends Command
{
    protected $signature = 'orbit:engine-keys';

    protected $description = 'Print a new NATS account seed for the Gateway state machine';

    public function handle(): int
    {
        $this->line('ORBIT_NATS_SEED='.NatsCredentials::newSeed());

        return self::SUCCESS;
    }
}
