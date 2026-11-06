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
        {--apt-proxy= : The package cache of the task VM that runs a nested fleet, such as http://10.232.2.1:3142; empty reads the archive directly}
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
        $proxy = $this->option('apt-proxy');
        if ($proxy !== null && $proxy !== '' && preg_match('#^http://[^\s"/]+$#', $proxy) !== 1) {
            $this->error('Pass --apt-proxy as a plain http origin, such as http://10.232.2.1:3142.');

            return self::FAILURE;
        }
        $plan = GatewayBootstrapPlan::make($values['url'], $values['home'], $values['checkout'], $proxy === '' ? null : $proxy);
        if ($this->option('environment')) {
            $this->getOutput()->write(is_string($plan['environment']) ? $plan['environment'] : '');

            return self::SUCCESS;
        }
        $this->line(json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
