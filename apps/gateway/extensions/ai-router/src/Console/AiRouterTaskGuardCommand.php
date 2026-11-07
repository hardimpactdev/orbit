<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Console;

use Illuminate\Console\Command;
use Orbit\Extensions\AiRouter\Actions\AdmitAiRouterTaskGuardAction;

final class AiRouterTaskGuardCommand extends Command
{
    #[\Override]
    protected $signature = 'ai-router:task-guard {--remove : Take the guard, its keys and its rules off the Node}';

    #[\Override]
    protected $description = 'Apply, or with --remove withdraw, the task VM guard of the model router: its Caddy, its keys and its two UFW rules.';

    public function handle(AdmitAiRouterTaskGuardAction $admit): int
    {
        $this->line("Operation {$admit->execute((bool) $this->option('remove'), 'ai-router:task-guard')->id} running");

        return self::SUCCESS;
    }
}
