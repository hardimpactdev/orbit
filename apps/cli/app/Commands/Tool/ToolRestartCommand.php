<?php

declare(strict_types=1);

namespace App\Commands\Tool;

final class ToolRestartCommand extends ToolLifecycleCommand
{
    #[\Override]
    protected $signature = 'tool:restart
        {tool? : Tool catalog name to restart}
        {--instance= : Resolve target by instance selector}
        {--node= : Resolve target by node}
        {--json : Output JSON}
        {--stream-json : Stream newline-delimited JSON progress frames}';

    #[\Override]
    protected $description = 'Restart a lifecycle-capable managed tool through the gateway.';

    #[\Override]
    protected function action(): string
    {
        return 'restart';
    }
}
