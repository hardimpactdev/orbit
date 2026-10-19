<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Console;

use Illuminate\Console\Command;
use Orbit\Extensions\AiRouter\Actions\ConvergeAiRouterAction;

final class ConvergeAiRouterCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:ai-router-converge';

    #[\Override]
    protected $description = 'Converge the configured AI router collector after a release or specification change.';

    public function handle(ConvergeAiRouterAction $converge): int
    {
        $converge->execute();

        return self::SUCCESS;
    }
}
