<?php

declare(strict_types=1);

namespace App\Commands\Tool;

final class ToolStartCommand extends ToolLifecycleCommand
{
    #[\Override]
    protected $signature = 'tool:start
        {tool? : Tool catalog name to start}
        {--app= : Resolve target by app selector}
        {--node= : Resolve target by node}
        {--json : Output JSON}
        {--stream-json : Stream newline-delimited JSON progress frames}';

    #[\Override]
    protected $description = 'Start a lifecycle-capable managed tool through the gateway.';

    #[\Override]
    protected function action(): string
    {
        return 'start';
    }
}
