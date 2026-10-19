<?php

declare(strict_types=1);

namespace App\Commands\AiRouter;

final class EnableAiRouterAccountCommand extends AiRouterAccountCommand
{
    #[\Override]
    protected $signature = 'ai-router:account:enable
        {account : Auth file name or auth_index}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Enable one CLIProxyAPI account.';

    protected function enabled(): bool
    {
        return true;
    }
}
