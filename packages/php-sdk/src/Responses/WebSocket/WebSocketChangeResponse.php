<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\WebSocket;

use InvalidArgumentException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class WebSocketChangeResponse
{
    /** @param ?list<int> $instanceRolloutIds */
    public function __construct(
        public ?WebSocketAppResponse $app,
        public WebSocketApplyResponse $operation,
        public ?array $instanceRolloutIds,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data, #[SensitiveParameter] mixed $operation, string $requestId): self
    {
        $rollout = null;
        if (isset($data['instance_rollout'])) {
            $value = $data['instance_rollout'];
            if (! is_array($value) || ($value['state'] ?? null) !== 'configuration_stored'
                || ($value['requires_env_sync'] ?? null) !== true || ($value['requires_reload'] ?? null) !== true
                || ! is_array($value['instance_ids'] ?? null) || ! array_is_list($value['instance_ids'])) {
                throw new InvalidArgumentException('Invalid WebSocket Instance rollout.');
            }
            foreach ($value['instance_ids'] as $id) {
                if (! is_int($id) || $id < 1) {
                    throw new InvalidArgumentException('Invalid WebSocket Instance rollout.');
                }
            }
            $rollout = $value['instance_ids'];
        }

        return new self($data === [] ? null : WebSocketAppResponse::fromGatewayData($data, $requestId), WebSocketApplyResponse::fromTransport($operation), $rollout, GatewayRequestId::fromTransport($requestId) ?? '');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['app' => $this->app?->toArray(), 'operation' => $this->operation->toArray(),
            'instance_rollout' => $this->instanceRolloutIds === null ? null : ['state' => 'configuration_stored', 'instance_ids' => $this->instanceRolloutIds, 'requires_env_sync' => true, 'requires_reload' => true],
            'request_id' => $this->requestId];
    }
}
