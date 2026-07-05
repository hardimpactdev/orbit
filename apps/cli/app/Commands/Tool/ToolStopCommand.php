<?php

declare(strict_types=1);

namespace App\Commands\Tool;

final class ToolStopCommand extends ToolLifecycleCommand
{
    #[\Override]
    protected $signature = 'tool:stop
        {tool? : Tool catalog name to stop}
        {--app= : Resolve target by app selector}
        {--node= : Resolve target by node}
        {--node-transport= : Node command transport preference (auto|agent-push|transitional-ssh-fallback)}
        {--json : Output JSON}
        {--stream-json : Stream newline-delimited JSON progress frames}';

    #[\Override]
    protected $description = 'Stop a lifecycle-capable managed tool through the gateway.';

    #[\Override]
    protected function action(): string
    {
        return 'stop';
    }
}
