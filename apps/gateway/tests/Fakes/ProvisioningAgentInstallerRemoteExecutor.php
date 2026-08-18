<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;

final class ProvisioningAgentInstallerRemoteExecutor implements RemoteShell
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
}
