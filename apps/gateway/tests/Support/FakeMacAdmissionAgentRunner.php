<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Infrastructure\Ssh\SshConnection;
use App\Models\Node;
use Throwable;

final class FakeMacAdmissionAgentRunner implements AdmissionAgentRunner
{
    public string $architecture = 'arm64';

    public ?Throwable $probeFailure = null;

    public ?Throwable $installFailure = null;

    public ?Throwable $inspectionFailure = null;

    /** @var list<string> */
    public array $commands = [];

    /** @var list<SshConnection> */
    public array $connections = [];

    /** @var list<array<string, mixed>> */
    public array $inputs = [];

    public function architectureOver(Node $node, SshConnection $connection): string
    {
        $this->commands[] = 'probe';
        $this->connections[] = $connection;
        if ($this->probeFailure !== null) {
            throw $this->probeFailure;
        }

        return $this->architecture;
    }

    public function installOver(Node $node, SshConnection $connection): bool
    {
        $this->commands[] = 'agent.install';
        if ($this->installFailure !== null) {
            throw $this->installFailure;
        }

        return true;
    }

    public function runOver(Node $node, SshConnection $connection, string $command, array $input = [], ?float $deadlineSeconds = null): array
    {
        FakeAgentCommandRunner::admit($node, $command, $input);
        $result = $this->respond($node, $connection, $command, $input, $deadlineSeconds);
        AgentSchemas::assertOutput($command, $result);

        return $result;
    }

    private function respond(Node $node, SshConnection $connection, string $command, array $input = [], ?float $deadlineSeconds = null): array
    {
        $this->commands[] = $command;
        $this->inputs[] = $input;
        if ($this->inspectionFailure !== null) {
            throw $this->inspectionFailure;
        }

        return ['architecture' => $this->architecture];
    }
}
