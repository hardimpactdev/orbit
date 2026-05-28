<?php

declare(strict_types=1);

namespace App\Commands\Tool;

final class ToolReloadCommand extends ToolActionCommand
{
    protected $signature = 'tool:reload
        {tool? : Tool catalog name to reload}
        {--app= : Resolve target by app selector}
        {--node= : Resolve target by node}
        {--json : Output JSON}';

    protected $description = 'Reload a managed tool through the gateway.';

    protected function action(): string
    {
        return 'reload';
    }
}
