<?php

declare(strict_types=1);

namespace App\Commands\Tool;

final class ToolRestartCommand extends ToolActionCommand
{
    #[\Override]
    protected $signature = 'tool:restart
        {tool? : Tool catalog name to restart}
        {--app= : Resolve target by app selector}
        {--node= : Resolve target by node}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Restart a managed tool through the gateway.';

    protected function action(): string
    {
        return 'restart';
    }
}
