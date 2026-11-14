<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use Orbit\Extensions\Conn\Models\ConnPairing;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The session the Gateway holds for the calling Node: the client presents `access_token` as its bearer to `url` directly. */
#[MapOutputName(SnakeCaseMapper::class)]
final class ConnHeldSessionData extends Data
{
    public function __construct(
        public string $environmentId,
        public string $url,
        public int $pairingId,
        public string $tokenType,
        public string $accessToken,
        public string $expiresAt,
    ) {}

    public static function fromModel(ConnPairing $pairing): self
    {
        if ($pairing->session === null || $pairing->session_expires_at === null) {
            throw new \LogicException('The pairing holds no session.');
        }

        return new self(
            environmentId: $pairing->environment->environment_id,
            url: $pairing->environment->url,
            pairingId: $pairing->id,
            tokenType: 'Bearer',
            accessToken: $pairing->session,
            expiresAt: $pairing->session_expires_at->toIso8601String(),
        );
    }
}
