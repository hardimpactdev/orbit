<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteExecutor;
use Illuminate\Contracts\Process\InvokedProcess;
use RuntimeException;

final class ProvisioningAgentInstallerRemoteExecutor implements RemoteExecutor
{
    public ?RemoteShellResult $result = null;

    /**
     * @var list<array{node: Node, script: string, options: array<string, mixed>}>
     */
    public array $runs = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = compact('node', 'script', 'options');

        return $this->result ?? new RemoteShellResult(exitCode: 0, stdout: 'agent-ready', stderr: '', durationMs: 1);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('Not implemented for this test.');
    }
}
