<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

/**
 * The update the Gateway runs on a Node now: `gateway_release` with the release record id. `since` is when it
 * started, as ISO 8601.
 */
final readonly class NodeUpdatingResponse
{
    public const string GatewayRelease = 'gateway_release';

    public function __construct(
        public string $kind,
        public string $since,
        public ?int $release = null,
    ) {}

    public static function tryFromGatewayData(mixed $data): ?self
    {
        if (
            ! is_array($data)
            || ($data['kind'] ?? null) !== self::GatewayRelease
            || ! is_string($data['since'] ?? null)
            || $data['since'] === ''
        ) {
            return null;
        }

        return new self(
            kind: $data['kind'],
            since: $data['since'],
            release: is_int($data['release'] ?? null) ? $data['release'] : null,
        );
    }

    /** @return array{kind: string, since: string, release: int|null} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'since' => $this->since,
            'release' => $this->release,
        ];
    }
}
