<?php

declare(strict_types=1);

namespace App\Infrastructure\SwampCredentials;

use App\Actions\GatewayReleases\ConfigureGatewayReleaseAction;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Support\Facades\Process;

/** Caches the Gateway configuration again after a Swamp token renewal changed the shared env. */
class SwampCredentialConfiguration
{
    public function __construct(private readonly ConfigureGatewayReleaseAction $configure) {}

    /** The serving release, as `gateway:release:configure` does. */
    public function serving(): void
    {
        $this->configure->execute();
    }

    /** A release candidate, as the release build does: it caches its configuration from the shared env in one rename. */
    public function candidate(): void
    {
        $application = base_path();
        $candidate = $application.'/bootstrap/cache/config.next-'.bin2hex(random_bytes(8)).'.php';
        $cached = Process::path($application)->timeout(120)->env(['APP_CONFIG_CACHE' => $candidate,
            'ORBIT_AGENT_DELIVERY' => dirname($application).'/agent/delivery/manifest.json'])
            ->run([PHP_BINARY, 'artisan', 'config:cache', '--no-interaction', '--quiet']);
        if (! $cached->successful() || ! @chmod($candidate, 0600) || ! @rename($candidate, $application.'/bootstrap/cache/config.php')) {
            @unlink($candidate);
            throw new ResourceOperationException('gateway.release_configuration_failed', 'config:cache failed after the Swamp token renewal.', 500);
        }
    }
}
