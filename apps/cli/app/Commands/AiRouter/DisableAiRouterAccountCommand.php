<?php

declare(strict_types=1);

namespace App\Commands\AiRouter;

final class DisableAiRouterAccountCommand extends AiRouterAccountCommand
{
    #[\Override]
    protected $signature = 'ai-router:account:disable
        {account : Auth file name or auth_index}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Disable one CLIProxyAPI account.';

    protected function enabled(): bool
    {
        return false;
    }
}
