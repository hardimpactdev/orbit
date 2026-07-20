<?php

declare(strict_types=1);

namespace App\Commands\Tool;

final class ToolReloadCommand extends ToolLifecycleCommand
{
    #[\Override]
    protected $signature = 'tool:reload
        {tool? : Tool catalog name to reload}
        {--instance= : Resolve target by instance selector}
        {--node= : Resolve target by node}
        {--json : Output JSON}
        {--stream-json : Stream newline-delimited JSON progress frames}';

    #[\Override]
    protected $description = 'Reload a lifecycle-capable managed tool through the gateway.';

    #[\Override]
    protected function action(): string
    {
        return 'reload';
    }
}
