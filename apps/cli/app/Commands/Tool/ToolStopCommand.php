<?php

declare(strict_types=1);

namespace App\Commands\Tool;

final class ToolStopCommand extends ToolActionCommand
{
    #[\Override]
    protected $signature = 'tool:stop
        {tool? : Tool catalog name to stop}
        {--app= : Resolve target by app selector}
        {--node= : Resolve target by node}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Stop a managed tool through the gateway.';

    protected function action(): string
    {
        return 'stop';
    }
}
