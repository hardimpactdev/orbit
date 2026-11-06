<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Gateway\GatewayBootstrapPlan;
use Illuminate\Console\Command;

/** Prints the prerequisites of a fresh Gateway as one JSON object, from the machine software recipe alone. */
final class BootstrapPlanCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:bootstrap-plan';

    #[\Override]
    protected $description = 'Print the prerequisites a fresh Gateway needs, from the machine software recipe.';

    public function handle(): int
    {
        $this->line(json_encode(GatewayBootstrapPlan::make(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
