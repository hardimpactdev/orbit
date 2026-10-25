<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\Ssh\SshConnection;
use App\Models\Node;
use LogicException;
use Throwable;

final class FakeRetargetAgentRunner implements AdmissionAgentRunner
{
    /** @var list<array{connection: SshConnection, command: string, input: array<string, mixed>}> */
    public array $calls = [];

    public ?Throwable $failure = null;

    public function architectureOver(Node $node, SshConnection $connection): string
    {
        throw new LogicException('Retarget must use the recorded architecture.');
    }

    public function installOver(Node $node, SshConnection $connection): bool
    {
        throw new LogicException('The command runner owns installation on mismatch.');
    }

    public function runOver(Node $node, SshConnection $connection, string $command, array $input = [], ?float $deadlineSeconds = null): array
    {
        $this->calls[] = compact('connection', 'command', 'input');
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return (new FakeAgentCommandRunner)->answer('agent.status', ['architecture' => $node->architecture])->run($node, $command, $input, $deadlineSeconds);
    }
}
