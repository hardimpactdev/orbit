<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Console;

use Illuminate\Console\Command;
use Orbit\Extensions\AiRouter\Actions\AdmitAiRouterTaskGuardAction;

final class AiRouterTaskGuardCommand extends Command
{
    #[\Override]
    protected $signature = 'ai-router:task-guard';

    #[\Override]
    protected $description = 'Apply the task VM guard of the model router: its Caddy, its key files and its two UFW rules.';

    public function handle(AdmitAiRouterTaskGuardAction $admit): int
    {
        $this->line("Operation {$admit->execute('ai-router:task-guard')->id} running");

        return self::SUCCESS;
    }
}
