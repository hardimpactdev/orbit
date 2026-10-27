<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\SwampReaderCredential;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\Tasks\SwampReaderEnvironment;
use App\Infrastructure\Tasks\WebSocketSwampRunReader;
use RuntimeException;
use Throwable;

final readonly class InstallSwampReaderCredentialAction
{
    public function __construct(
        private GatewayReleaseLock $lock,
        private SwampReaderEnvironment $environment,
        private WebSocketClient $socket,
    ) {}

    /** @return array{name: string, expires_at: string, backup: string} */
    public function execute(SwampReaderCredential $credential): array
    {
        return $this->lock->run(function () use ($credential): array {
            $original = $this->environment->contents();
            $settings = $this->environment->values($original);
            try {
                new WebSocketSwampRunReader(
                    $this->socket,
                    $settings['ORBIT_SWAMP_ADDRESS'] ?? 'swamp.orbit',
                    $credential->token(),
                    $settings['ORBIT_SWAMP_CA_PATH'] ?? rtrim(config()->string('orbit.home'), '/').'/ca/root.pem',
                )->read(0);
            } catch (Throwable) {
                throw new RuntimeException('The replacement could not read Swamp. The shared env was not changed.');
            }
            $backup = $this->environment->install($original, $credential);

            return ['name' => $credential->name, 'expires_at' => $credential->expiresAt->format('Y-m-d\TH:i:s.u\Z'), 'backup' => $backup];
        });
    }
}
