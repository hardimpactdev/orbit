<?php

declare(strict_types=1);

namespace App\Infrastructure\SwampCredentials;

use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\Swamp\SwampCaller;
use App\Infrastructure\Tasks\WebSocketSwampRunReader;

/** Proves a new Gateway token reads Swamp through the serving address before the shared env changes. */
class SwampTokenReadCheck
{
    /** @param array<string,string|null> $values The shared env settings. */
    public function check(#[\SensitiveParameter] string $token, array $values): void
    {
        (new WebSocketSwampRunReader(new StreamWebSocketClient(2_097_152, 2_097_152), $values['ORBIT_SWAMP_ADDRESS'] ?? 'swamp.orbit', $token,
            $values['ORBIT_SWAMP_CA_PATH'] ?? rtrim(config()->string('orbit.home'), '/').'/ca/root.pem', SwampCaller::Release))->read(0);
    }
}
