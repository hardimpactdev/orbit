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
            throw new RuntimeException("Orbit Agent push request failed with HTTP {$response->status()}.");
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        return NodeAgentPushResult::fromAgentPayload($payload, $gatewayPostMs);
    }

    private function assertAllowlisted(NodeCommandEnvelope $envelope): void
    {
        $isAllowlisted =
            $envelope->commandId === self::AGENT_PUSH_BINARY_COMMAND_ID
            && $envelope->requiresNodeExecution
            && $envelope->supportsAgentPushTransport
            && $envelope->binary === self::AGENT_PUSH_BINARY_ALLOWLISTED_BINARY;

        if ($isAllowlisted) {
            return;
        }

        throw new InvalidArgumentException(
            'Only allowlisted agent-push envelopes can be sent to the Orbit Agent listener.',
        );
    }

    private function urlFor(Node $node): string
    {
        $host = trim((string) $node->wireguard_address);

        if ($host === '') {
            throw new InvalidArgumentException('Agent-push target node must have a WireGuard address.');
        }

        return "http://{$host}:9477/v1/commands";
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
     *     operation_id: string,
     *     binary: string,
     *     argv: list<string>,
     *     input?: string,
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
        $payload = [
            'operation_id' => (string) $envelope->operationId,
            'binary' => (string) $envelope->binary,
            'argv' => $envelope->argv,
            'operation_token' => $operationToken,
            'timeout_seconds' => $envelope->timeoutSeconds,
            'stream' => $envelope->stream,
        ];

        if ($envelope->input !== null) {
            $payload['input'] = $envelope->input;
        }

        return $payload;
    }
}
