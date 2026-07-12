<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Contracts\Process\InvokedProcess;
use RuntimeException;

final class WorkspaceSetupStepRunnerExecutorTransport implements RemoteExecutor, RunsInternalCommands
{
    /** @var list<array{node: Node, script: string, options: array<string, mixed>}> */
    public array $runs = [];

    /** @var list<RemoteShellResult> */
    private array $responses;

    public function __construct(RemoteShellResult ...$responses)
    {
        $this->responses = $responses;
    }

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = ['node' => $node, 'script' => $script, 'options' => $options];

        return (
            array_shift($this->responses) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1)
        );
    }

    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('Workspace setup step runner tests do not start long-running transports.');
    }

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        return $this->run($node, $commandName, $transportOptions);
    }
}
