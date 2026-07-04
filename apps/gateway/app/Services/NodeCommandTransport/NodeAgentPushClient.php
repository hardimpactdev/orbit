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

        $response = Http::timeout(10)
            ->acceptJson()
            ->withToken($operationToken)
            ->post($this->urlFor($node), $this->payloadForTransport($envelope, $operationToken));

        if (! $response->successful()) {
            throw new RuntimeException("Orbit Agent push request failed with HTTP {$response->status()}.");
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        return new NodeAgentPushResult([
            'transport' => $this->stringValue($payload, 'transport'),
            'operation_id' => $this->stringValue($payload, 'operation_id'),
            'binary' => $this->stringValue($payload, 'binary'),
            'status' => $this->stringValue($payload, 'status'),
            'frames' => $this->frames($payload),
            'exit_code' => $this->intValue($payload, 'exit_code'),
        ]);
    }

    private function assertAllowlisted(NodeCommandEnvelope $envelope): void
    {
        if (
            $envelope->commandId === self::AGENT_PUSH_BINARY_COMMAND_ID
            && $envelope->requiresNodeExecution
            && $envelope->supportsAgentPushTransport
            && $envelope->binary === self::AGENT_PUSH_BINARY_ALLOWLISTED_BINARY
        ) {
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

    /**
     * @return array{
     *     operation_id: string,
     *     binary: string,
     *     argv: list<string>,
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
        return [
            'operation_id' => (string) $envelope->operationId,
            'binary' => (string) $envelope->binary,
            'argv' => $envelope->argv,
            'operation_token' => $operationToken,
            'timeout_seconds' => $envelope->timeoutSeconds,
            'stream' => $envelope->stream,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringValue(array $payload, string $key): string
    {
        if (! array_key_exists($key, $payload) || ! is_string($payload[$key]) || trim($payload[$key]) === '') {
            throw new RuntimeException("Orbit Agent push response is missing '{$key}'.");
        }

        return $payload[$key];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<array-key, mixed>>
     */
    private function frames(array $payload): array
    {
        if (! array_key_exists('frames', $payload) || ! is_array($payload['frames'])) {
            return [];
        }

        return array_values(array_filter(
            $payload['frames'],
            is_array(...),
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function intValue(array $payload, string $key): ?int
    {
        if (! array_key_exists($key, $payload)) {
            return null;
        }

        if (! is_int($payload[$key])) {
            throw new RuntimeException("Orbit Agent push response '{$key}' must be an integer.");
        }

        return $payload[$key];
    }
}
