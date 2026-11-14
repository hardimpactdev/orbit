<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Conn;

use InvalidArgumentException;

/** The answers of `conn:environment-restart` (one Instance) or `conn:node-environments-restart` (each container on a Node). */
final readonly class ConnEnvironmentRestartsResponse
{
    /** @param list<ConnEnvironmentRestartResponse> $restarts */
    public function __construct(public array $restarts, public string $requestId) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        $restarts = $data['restarts'] ?? null;
        if (! is_array($restarts) || ! array_is_list($restarts)) {
            throw new InvalidArgumentException('Gateway response contains invalid T3 restart answers.');
        }

        return new self(array_map(static function (mixed $restart) use ($requestId): ConnEnvironmentRestartResponse {
            if (! is_array($restart)) {
                throw new InvalidArgumentException('Gateway response contains an invalid T3 restart answer.');
            }

            return ConnEnvironmentRestartResponse::fromGatewayData($restart, $requestId);
        }, $restarts), $requestId);
    }
}
