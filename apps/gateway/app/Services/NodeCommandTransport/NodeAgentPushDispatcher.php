<?php

declare(strict_types=1);

namespace App\Services\NodeCommandTransport;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use RuntimeException;
use SensitiveParameter;

final readonly class NodeAgentPushDispatcher
{
    public function __construct(
        private NodeCommandTransportSelector $selector,
        private NodeAgentPushClient $client,
    ) {}

    public function select(Node $node, NodeCommandEnvelope $envelope): NodeTransport
    {
        return $this->selector->select($node, $envelope);
    }

    public function execute(
        Node $node,
        NodeCommandEnvelope $envelope,
        #[SensitiveParameter]
        string $operationToken,
    ): RemoteShellResult {
        return $this->result($this->client->execute($node, $envelope, $operationToken));
    }

    /**
     * @param  callable(string): void  $onOutput
     */
    public function stream(
        Node $node,
        NodeCommandEnvelope $envelope,
        #[SensitiveParameter]
        string $operationToken,
        callable $onOutput,
    ): NodeAgentPushStreamResult {
        if ($this->select($node, $envelope) !== NodeTransport::AgentPush) {
            throw new RuntimeException('agent-push streaming transport is unavailable');
        }

        return $this->client->stream($node, $envelope, $operationToken, $onOutput);
    }

    private function result(NodeAgentPushResult $result): RemoteShellResult
    {
        $stdout = '';
        $stderr = '';

        foreach ($result->frames as $frame) {
            $message = $frame['message'] ?? '';

            if (! is_string($message)) {
                continue;
            }

            if (! array_key_exists('type', $frame) || ! is_string($frame['type'])) {
                continue;
            }

            $type = $frame['type'];

            if ($type === 'stderr') {
                $stderr .= $message;

                continue;
            }

            if ($type === 'stdout') {
                $stdout .= $message;
            }
        }

        return new RemoteShellResult(
            exitCode: $result->exitCode ?? 1,
            stdout: $stdout,
            stderr: $stderr,
            // Record the gateway-observed Agent dispatch round trip.
            durationMs: $result->timings['gateway_post_ms'],
        );
    }
}
