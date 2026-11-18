<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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
            $container = $this->layout->usesContainers();
            $inventory = $this->layout->observe($container ? base_path() : null);
            if ($inventory->superseded) {
                throw new GatewayReleaseException('configuration', 'gateway.release_superseded', 'Run configure in the serving Gateway release.');
            }
            $id = $inventory->current;
            $sha = $id === null ? null : ($inventory->commits[$id] ?? null);
            if ($id === null || $sha === null) {
                throw new GatewayReleaseException('configuration', 'gateway.release_not_adopted', 'The Gateway is not running from a release.');
            }
            $release = $container ? $this->layout->currentPath() : $this->layout->releasePath($id);
            $application = $release.'/apps/gateway';
            $current = $application.'/bootstrap/cache/config.php';
            $candidate = $current.'.next-'.bin2hex(random_bytes(8));
            $previous = $candidate.'.previous';
            $keepPrevious = false;
            try {
                $environment = ['APP_CONFIG_CACHE' => $candidate, 'ORBIT_AGENT_DELIVERY' => $release.'/apps/agent/delivery/manifest.json'];
                if ($container) {
                    $environment['ORBIT_GATEWAY_SSH_MUX'] = '/run/orbit/ssh-mux';
                }
                $cached = Process::path($application)->timeout(120)
                    ->env($environment)
                    ->run([PHP_BINARY, 'artisan', 'config:cache', '--no-interaction', '--quiet']);
                if (! $cached->successful() || ! @chmod($candidate, 0600)) {
                    throw new GatewayReleaseException('configuration', 'gateway.release_configuration_failed',
                        'config:cache failed: '.mb_substr(trim($cached->errorOutput()), -500), 500, sha: $sha);
                }
                if ($container) {
                    // A retained worker must rebuild from the current env before it can serve again.
                    $marker = $this->layout->basePath().'/.gateway-env-digest';
                    if ((file_exists($marker) || is_link($marker)) && ! @unlink($marker)) {
                        throw new GatewayReleaseException('configuration', 'gateway.release_configuration_failed', 'Could not invalidate the release configuration digest.', 500, sha: $sha);
                    }
                }
                if (($container && ! @link($current, $previous)) || ! @rename($candidate, $current)) {
                    throw new GatewayReleaseException('configuration', 'gateway.release_configuration_failed', 'Could not publish the new configuration.', 500, sha: $sha);
                }
                if ($container && ! $this->reloadWorkers()) {
                    if (! @rename($previous, $current)) {
                        $keepPrevious = true;
                        throw new GatewayReleaseException('configuration', 'gateway.release_configuration_failed', "Worker reload failed. Restore the previous cache from [{$previous}].", 500, sha: $sha);
                    }
                    $restored = $this->reloadWorkers();
                    throw new GatewayReleaseException('configuration', 'gateway.release_configuration_failed', $restored
                        ? 'Worker reload failed. The previous configuration was restored.'
                        : 'Worker reload failed. The previous cache was restored. Check the Gateway web service before retrying.', 500, sha: $sha);
                }
            } finally {
                @unlink($candidate);
                if (! $keepPrevious) {
                    @unlink($previous);
                }
            }

            return ['release' => $id, 'sha' => $sha, 'duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000)];
        });
    }

    /** @phpstan-impure */
    private function reloadWorkers(): bool
    {
        try {
            return Http::withoutRedirecting()->connectTimeout(2)->timeout(610)->withOptions([
                'proxy' => '',
                'curl' => [CURLOPT_UNIX_SOCKET_PATH => '/run/orbit-gateway-web/admin.sock'],
            ])->post('http://localhost/frankenphp/workers/restart')->successful();
        } catch (ConnectionException) {
            return false;
        }
    }
}
