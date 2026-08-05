<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

use App\Models\Node;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationStreamTokens;
use Illuminate\Support\Str;
use Orbit\Sdk\Laravel\GatewayApiException;

final readonly class ApplicationLogStreamTarget
{
    public function __construct(
        private OperationRunRecorder $operationRuns,
        private OperationStreamTokens $streamTokens,
        private NodeHostPaths $nodeHostPaths,
    ) {}

    /**
     * @param  array{authorized_root: string, absolute_path: string, logical_path: string}  $paths
     * @return array{node: Node, absolute_path: string, authorized_root: string, lines: int, operation_stream: array<string, mixed>}
     */
    public function create(
        Node $node,
        array $paths,
        int $lines,
        ?string $gatewayUrl,
        string $operationType,
    ): array {
        if ($lines < 1) {
            throw new GatewayApiException('The lines value must be a positive integer.', 'validation_failed', [
                'field' => 'lines',
                'value' => $lines,
            ]);
        }

        $run = $this->operationRuns->queued(
            operationId: (string) Str::uuid(),
            lane: 'gateway',
            operationType: $operationType,
            targetNodeId: $node->id,
        );
        $this->operationRuns->running($run->id);

        $channel = "private-operations.{$run->id}";
        $publisher = $this->streamTokens->publisherToken($run, $channel);

        return [
            'node' => $node,
            'absolute_path' => $paths['absolute_path'],
            'authorized_root' => $paths['authorized_root'],
            'lines' => $lines,
            'operation_stream' => [
                'operation_uuid' => $run->id,
                'channel' => $channel,
                'gateway_url' => $gatewayUrl,
                'ca_pem_path' => $this->nodeHostPaths->runtimeTrustPoolPath($node),
                'publish_endpoint' => "/api/operations/{$run->id}/stream/publish",
                'stop_decision_endpoint' => "/api/operations/{$run->id}/stream/stop-decision",
                'publisher_token' => $publisher['token'],
                'publisher_expires_at' => $publisher['expires_at']->toIso8601String(),
            ],
        ];
    }
}
