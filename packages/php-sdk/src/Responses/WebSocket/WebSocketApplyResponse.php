<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\WebSocket;

use InvalidArgumentException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class WebSocketApplyResponse
{
    /**
     * @param  list<array{id: string, node_id: int, boot_id: ?string, applied_revision: ?int, state: string}>  $processes
     * @param  list<int>  $missingNodeIds
     */
    public function __construct(
        public string $id,
        public string $epoch,
        public int $revision,
        public string $state,
        public array $processes,
        public array $missingNodeIds,
    ) {}

    public static function fromTransport(#[SensitiveParameter] mixed $data): self
    {
        if (! is_array($data) || ! is_string($data['id'] ?? null) || GatewayRequestId::fromTransport($data['id']) === null
            || ! is_string($data['epoch'] ?? null) || GatewayRequestId::fromTransport($data['epoch']) === null
            || ! is_int($data['revision'] ?? null) || $data['revision'] < 1
            || ! in_array($data['state'] ?? null, ['applied', 'pending', 'superseded'], true)
            || ! is_array($data['processes'] ?? null) || ! array_is_list($data['processes'])
            || ! is_array($data['missing_node_ids'] ?? null) || ! array_is_list($data['missing_node_ids'])) {
            throw new InvalidArgumentException('Invalid WebSocket apply response.');
        }
        $processes = [];
        foreach ($data['processes'] as $process) {
            if (! is_array($process) || ! is_string($process['id'] ?? null) || GatewayRequestId::fromTransport($process['id']) === null
                || ! is_int($process['node_id'] ?? null) || $process['node_id'] < 1
                || ! array_key_exists('boot_id', $process) || ($process['boot_id'] !== null && (! is_string($process['boot_id']) || GatewayRequestId::fromTransport($process['boot_id']) === null))
                || ! array_key_exists('applied_revision', $process) || ($process['applied_revision'] !== null && (! is_int($process['applied_revision']) || $process['applied_revision'] < 1))
                || ! in_array($process['state'] ?? null, ['applied', 'pending', 'stale'], true)) {
                throw new InvalidArgumentException('Invalid WebSocket process response.');
            }
            $processes[] = ['id' => $process['id'], 'node_id' => $process['node_id'], 'boot_id' => $process['boot_id'], 'applied_revision' => $process['applied_revision'], 'state' => $process['state']];
        }
        foreach ($data['missing_node_ids'] as $id) {
            if (! is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Invalid missing WebSocket Node.');
            }
        }

        return new self($data['id'], $data['epoch'], $data['revision'], $data['state'], $processes, $data['missing_node_ids']);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'epoch' => $this->epoch, 'revision' => $this->revision, 'state' => $this->state,
            'processes' => $this->processes, 'missing_node_ids' => $this->missingNodeIds,
            'status_url' => '/api/v1/websocket-operations/'.$this->id];
    }
}
