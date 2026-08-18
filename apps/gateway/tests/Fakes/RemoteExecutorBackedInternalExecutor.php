<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RunsInternalCommands;

final readonly class RemoteExecutorBackedInternalExecutor implements RunsInternalCommands
{
    public function __construct(
        private RemoteShell $executor,
        private LocalExecutorCommandBuilder $commands = new LocalExecutorCommandBuilder,
    ) {}

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        return $this->executor->run(
            $node,
            $this->commands->build($node, $commandName, $arguments, $commandOptions, 'test-operation-token'),
            $transportOptions,
        );
    }
}
