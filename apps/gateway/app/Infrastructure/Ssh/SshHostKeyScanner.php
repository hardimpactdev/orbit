<?php

declare(strict_types=1);

namespace App\Infrastructure\Ssh;

use App\Domain\Gateway\GatewayServingHost;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Models\Node;

final readonly class SshHostKeyScanner implements HostKeyScanner
{
    public function __construct(
        private AgentCommandRunner $agents,
        private GatewayServingHost $servingHost,
    ) {}

    public function scan(string $host, int $port, ?Node $via = null): HostKey
    {
        $scanner = $via ?? Node::query()->find($this->servingHost->nodeId());
        if (! $scanner instanceof Node) {
            throw new SshHostKeyScanException('The serving Gateway must be registered before scanning an SSH host key.');
        }

        try {
            $key = $this->agents->run($scanner, 'node.scan-host-key', ['host' => $host, 'port' => $port]);
        } catch (AgentCommandRefused|AgentRunnerFailure $exception) {
            throw new SshHostKeyScanException(
                message: "Could not scan the SSH host key for [{$host}:{$port}].",
                result: $exception instanceof AgentRunnerFailure ? $exception->result : null,
                previous: $exception,
            );
        }

        if (! is_string($key['type'] ?? null) || ! is_string($key['value'] ?? null)
            || ($bytes = base64_decode($key['value'], strict: true)) === false || $bytes === '') {
            throw new SshHostKeyScanException("SSH host [{$host}:{$port}] returned an invalid host key.");
        }

        return new HostKey(type: $key['type'], value: $key['value'], fingerprint: 'SHA256:'.rtrim(base64_encode(hash('sha256', $bytes, binary: true)), '='));
    }
}
