<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions\Push;

use Orbit\Extensions\Conn\Data\ConnPushCredentialsStatusData;
use Orbit\Extensions\Conn\Domain\Push\ApnsJwtProvider;
use Orbit\Extensions\Conn\Domain\Push\ConnPushCredentials;
use Orbit\Extensions\Conn\Domain\Push\ConnPushCredentialsStore;
use SensitiveParameter;

/** Validates and stores Apple APNs provider credentials in Orbit's encrypted secret storage. */
final readonly class SetConnPushCredentialsAction
{
    public function __construct(
        private ConnPushCredentialsStore $store,
        private ApnsJwtProvider $jwt,
    ) {}

    public function execute(string $keyId, string $teamId, #[SensitiveParameter] string $privateKey): ConnPushCredentialsStatusData
    {
        $status = $this->store->put(new ConnPushCredentials($keyId, $teamId, $privateKey));
        $this->jwt->clear();

        return $status;
    }
}
