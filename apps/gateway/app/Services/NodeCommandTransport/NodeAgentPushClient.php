<?php

declare(strict_types=1);

namespace App\Services\NodeCommandTransport;

use App\Models\Node;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

final readonly class NodeAgentPushClient
{
    private const string AGENT_PUSH_BINARY_COMMAND_ID = 'orbit.agent.binary';
    private const string AGENT_PUSH_BINARY_ALLOWLISTED_BINARY = 'orbit';

    public function execute(
        Node $node,
        NodeCommandEnvelope $envelope,
        #[SensitiveParameter]
        string $operationToken,
    ): NodeAgentPushResult {
        $this->assertAllowlisted($envelope);

        $operationToken = trim($operationToken);

        if ($operationToken === '') {
            throw new InvalidArgumentException('Agent-push operation token is required.');
        }

        $gatewayPostStartedAt = $this->monotonicNanoseconds();

        $response = Http::timeout($this->requestTimeoutSeconds($envelope))
            ->acceptJson()
            ->withToken($operationToken)
            ->post($this->urlFor($node), $this->payloadForTransport($envelope, $operationToken));

        $gatewayPostMs = $this->elapsedMilliseconds($gatewayPostStartedAt);

        if (! $response->successful()) {
            throw new RuntimeException(NodeAgentPushFailureMessage::forResponse(
                prefix: 'Orbit Agent push request failed',
                response: $response,
                operationToken: $operationToken,
            ));
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        return NodeAgentPushResult::fromAgentPayload($payload, $gatewayPostMs);
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
    ): void {
        $this->assertAllowlisted($envelope);

        $operationToken = trim($operationToken);

        if ($operationToken === '') {
            throw new InvalidArgumentException('Agent-push operation token is required.');
        }

        $response = Http::withOptions([
            'stream' => true,
            'read_timeout' => 0,
            'timeout' => 0,
        ])
            ->accept('text/plain')
            ->withToken($operationToken)
            ->post($this->streamUrlFor($node), $this->payloadForTransport($envelope, $operationToken));

        if (! $response->successful()) {
            throw new RuntimeException(NodeAgentPushFailureMessage::forResponse(
                prefix: 'Orbit Agent push stream request failed',
                response: $response,
                operationToken: $operationToken,
            ));
        }

        new NodeAgentPushStreamReader()->read($response, $onOutput);
    }

    private function assertAllowlisted(NodeCommandEnvelope $envelope): void
    {
        $isAllowlisted =
            $envelope->commandId === self::AGENT_PUSH_BINARY_COMMAND_ID
            && $envelope->requiresNodeExecution
            && $envelope->supportsAgentPushTransport
            && $envelope->agentPushCommand?->binary === self::AGENT_PUSH_BINARY_ALLOWLISTED_BINARY;

        if ($isAllowlisted) {
            return;
        }

        throw new InvalidArgumentException(
            'Only allowlisted agent-push envelopes can be sent to the Orbit Agent listener.',
        );
    }

    private function urlFor(Node $node): string
    {
        return $this->nodeUrlFor($node, '/v1/commands');
    }

    private function streamUrlFor(Node $node): string
    {
        return $this->nodeUrlFor($node, '/v1/commands/stream');
    }

    private function nodeUrlFor(Node $node, string $path): string
    {
        $host = trim((string) $node->wireguard_address);

        if ($host === '') {
            throw new InvalidArgumentException('Agent-push target node must have a WireGuard address.');
        }

        return "http://{$host}:9477{$path}";
    }

    private function requestTimeoutSeconds(NodeCommandEnvelope $envelope): int
    {
        return max(10, $envelope->timeoutSeconds + 5);
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) round(($this->monotonicNanoseconds() - $startedAt) / 1_000_000));
    }

    private function monotonicNanoseconds(): int
    {
        $now = hrtime(true);

        if (! is_int($now)) {
            throw new RuntimeException('Could not read monotonic clock.');
        }

        return $now;
    }

    /**
     * @return array{
     *     command_id: string,
     *     operation_id: string,
     *     payload: array<string, mixed>,
     *     binary: string,
     *     argv: list<string>,
     *     input?: string,
     *     cwd?: string,
     *     environment?: array<string, string>,
     *     operation_token: string,
     *     timeout_seconds: int,
     *     stream: bool,
     * }
     */
    private function payloadForTransport(
        NodeCommandEnvelope $envelope,
        #[SensitiveParameter]
        string $operationToken,
    ): array {
        $command = $envelope->agentPushCommand ?? throw new InvalidArgumentException(
            'Agent-push envelopes require binary command details.',
        );

        $payload = [
            'command_id' => $envelope->commandId,
            'operation_id' => $command->operationId,
            'binary' => $command->binary,
            'argv' => $command->argv,
            'operation_token' => $operationToken,
            'timeout_seconds' => $envelope->timeoutSeconds,
            'stream' => $envelope->stream,
        ];

        if ($command->input !== null) {
            $payload['input'] = $command->input;
        }

        if ($command->executionContext->cwd !== null) {
            $payload['cwd'] = $command->executionContext->cwd;
        }

        if ($command->executionContext->environment !== []) {
            $payload['environment'] = $command->executionContext->environment;
        }

        $payload['payload'] = array_diff_key($payload, ['command_id' => true, 'payload' => true]);

        return $payload;
    }
}
