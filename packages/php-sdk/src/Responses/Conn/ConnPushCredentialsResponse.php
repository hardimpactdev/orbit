<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Conn;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

/** Whether Apple APNs push credentials are configured on the Gateway. Never includes the private key. */
final readonly class ConnPushCredentialsResponse
{
    public function __construct(
        public bool $configured,
        public ?string $keyId,
        public ?string $teamId,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $configured = $data['configured'] ?? null;
        $keyId = $data['key_id'] ?? null;
        $teamId = $data['team_id'] ?? null;

        if (! is_bool($configured)
            || ($keyId !== null && ! is_string($keyId))
            || ($teamId !== null && ! is_string($teamId))) {
            throw new GatewayApiException(
                'Gateway response contains invalid Conn push credentials data.',
                requestId: $requestId,
            );
        }

        return new self($configured, $keyId, $teamId, $requestId);
    }

    /** @return array{configured: bool, key_id: ?string, team_id: ?string, request_id: string} */
    public function toArray(): array
    {
        return [
            'configured' => $this->configured,
            'key_id' => $this->keyId,
            'team_id' => $this->teamId,
            'request_id' => $this->requestId,
        ];
    }
}
