<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use Illuminate\Support\Facades\Process;

/**
 * Caches the current release's configuration again from the shared env file, as the release run's build does. A
 * release runs with a cached configuration, so an env change takes effect only after this. The new cache replaces the
 * old one in one rename, so requests in flight never read half of it.
 */
final readonly class ConfigureGatewayReleaseAction
{
    public function __construct(
        private GatewayReleaseLock $lock,
        private GatewayReleaseLayout $layout,
    ) {}

    /** @return array{release: string, sha: string, duration_ms: int} */
    public function execute(): array
    {
        return $this->lock->run(function (): array {
            $startedAt = hrtime(true);
            $id = $this->layout->currentReleaseId();
            $sha = $id === null ? null : $this->layout->preparedCommit($id);
            if ($id === null || $sha === null) {
                throw new GatewayReleaseException('configuration', 'gateway.release_not_adopted', 'The Gateway is not running from a release.');
            }
            $application = $this->layout->releaseApplicationPath($id);
            $candidate = $application.'/bootstrap/cache/config.next-'.bin2hex(random_bytes(8)).'.php';
            $cached = Process::path($application)->timeout(120)
                ->env(['APP_CONFIG_CACHE' => $candidate, 'ORBIT_AGENT_DELIVERY' => $this->layout->releasePath($id).'/apps/agent/delivery/manifest.json'])
                ->run([PHP_BINARY, 'artisan', 'config:cache', '--no-interaction', '--quiet']);
            if (! $cached->successful() || ! chmod($candidate, 0600) || ! rename($candidate, $application.'/bootstrap/cache/config.php')) {
                @unlink($candidate);

                throw new GatewayReleaseException('configuration', 'gateway.release_configuration_failed',
                    'config:cache failed: '.mb_substr(trim($cached->errorOutput()), -500), 500, sha: $sha);
            }

            return ['release' => $id, 'sha' => $sha, 'duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000)];
        });
    }
}
