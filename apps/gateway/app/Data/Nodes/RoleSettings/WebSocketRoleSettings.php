<?php

declare(strict_types=1);

namespace App\Data\Nodes\RoleSettings;

use InvalidArgumentException;

final readonly class WebSocketRoleSettings implements NodeRoleSettings
{
    public function __construct(
        public int $valkeyNodeId,
    ) {
        if ($valkeyNodeId < 1) {
            throw new InvalidArgumentException('The websocket role requires a valid valkey_node_id setting.');
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromArray(array $settings): self
    {
        $unknownKeys = array_diff(array_keys($settings), ['valkey_node_id']);

        if ($unknownKeys !== []) {
            throw new InvalidArgumentException('The websocket role does not accept unknown settings.');
        }

        $valkeyNodeId = $settings['valkey_node_id'] ?? null;

        if (! is_int($valkeyNodeId) || $valkeyNodeId < 1) {
            throw new InvalidArgumentException('The websocket role requires a valid valkey_node_id setting.');
        }

        return new self($valkeyNodeId);
    }

    #[\Override]
    public function toArray(): array
    {
        return ['valkey_node_id' => $this->valkeyNodeId];
    }
}
