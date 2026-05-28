<?php

declare(strict_types=1);

namespace App\Commands\Tool;

final class ToolStartCommand extends ToolActionCommand
{
    #[\Override]
    protected $signature = 'tool:start
        {tool? : Tool catalog name to start}
        {--app= : Resolve target by app selector}
        {--node= : Resolve target by node}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Start a managed tool through the gateway.';

    protected function action(): string
    {
        return 'start';
    }
}
