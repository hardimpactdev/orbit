<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Engine\NatsCredentials;
use Illuminate\Console\Command;

/**
 * Makes the machine bus's three nkey seeds (ADR 0271; docs/reference/gateway-engine.md#credentials) and prints them as
 * Gateway environment lines. The operator adds them to `gateway.env` and converges the gateway role. A new account seed
 * revokes every Node's credentials; the next converge of each Node writes new ones.
 */
final class EngineKeysCommand extends Command
{
    protected $signature = 'orbit:engine-keys';

    protected $description = 'Print new NATS operator, account and system seeds for the Gateway state machine';

    public function handle(): int
    {
        $this->line('ORBIT_NATS_OPERATOR_SEED='.NatsCredentials::newSeed(NatsCredentials::PrefixOperator));
        $this->line('ORBIT_NATS_ACCOUNT_SEED='.NatsCredentials::newSeed(NatsCredentials::PrefixAccount));
        $this->line('ORBIT_NATS_SYSTEM_SEED='.NatsCredentials::newSeed(NatsCredentials::PrefixAccount));

        return self::SUCCESS;
    }
}
