<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Gateway\GatewayBootstrapPlan;
use Illuminate\Console\Command;

/** Prints the prerequisites of a fresh Gateway as one JSON object, from the machine software recipe alone. */
final class BootstrapPlanCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:bootstrap-plan
        {--url= : The Gateway address, APP_URL}
        {--home= : ORBIT_HOME}
        {--checkout= : ORBIT_GATEWAY_CHECKOUT, the apps/gateway folder of the release}
        {--environment : Print only the .env text}';

    #[\Override]
    protected $description = 'Print the prerequisites a fresh Gateway needs, from the machine software recipe.';

    public function handle(): int
    {
        $values = [];
        foreach (['url', 'home', 'checkout'] as $name) {
            $value = $this->option($name);
            if (! is_string($value) || $value === '') {
                $this->error("Pass --{$name}.");

                return self::FAILURE;
            }
            $values[$name] = $value;
        }
        $plan = GatewayBootstrapPlan::make($values['url'], $values['home'], $values['checkout']);
        if ($this->option('environment')) {
            $this->getOutput()->write(is_string($plan['environment']) ? $plan['environment'] : '');

            return self::SUCCESS;
        }
        $this->line(json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
