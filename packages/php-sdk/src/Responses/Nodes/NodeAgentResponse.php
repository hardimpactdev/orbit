<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

/**
 * The liveness of a Node's agent: `online`, `offline` (with `since`, the time of its last event), `not_seen`, or
 * `unknown` while the Gateway's agent view is down. A Node without an observing agent has none.
 */
final readonly class NodeAgentResponse
{
    public const array States = ['online', 'offline', 'not_seen', 'unknown'];

    public function __construct(
        public string $state,
        public ?string $since = null,
    ) {}

    public static function tryFromGatewayData(mixed $data): ?self
    {
        if (! is_array($data) || ! in_array($data['state'] ?? null, self::States, true)) {
            return null;
        }

        return new self($data['state'], is_string($data['since'] ?? null) ? $data['since'] : null);
    }

    /** @return array{state: string, since: string|null} */
    public function toArray(): array
    {
        return ['state' => $this->state, 'since' => $this->since];
    }

    /** The state for people, such as `not seen`; a table shows only this. */
    public function stateLabel(): string
    {
        return $this->state === 'not_seen' ? 'not seen' : $this->state;
    }

    /** One line for people, such as `offline since 2026-11-02T17:55:00Z`. */
    public function label(): string
    {
        return $this->state === 'offline' && $this->since !== null ? 'offline since '.$this->since : $this->stateLabel();
    }
}
