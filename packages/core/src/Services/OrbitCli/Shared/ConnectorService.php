<?php
declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\OrbitCli\Shared;

use HardImpact\Orbit\Core\Http\Integrations\Orbit\OrbitConnector;
use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\SshService;
use Saloon\Http\Request;

/**
 * Shared service for HTTP API connectivity to orbit web app.
 * Handles TLD resolution, Saloon connector creation, and request execution.
 */
class ConnectorService
{
    public function __construct(protected SshService $ssh) {}

    /**
     * Get the TLD for an environment.
     * Uses cached value from database or fetches via SSH on first request.
     */
    public function getTld(Environment $environment): string
    {
        // Use cached TLD if available
        if ($environment->tld) {
            return $environment->tld;
        }

        // For local environments, read from local config
        if ($environment->is_local) {
            $config = $this->getLocalConfigForTld();
            $tld = $config['tld'] ?? 'test';
            $environment->update(['tld' => $tld]);

            return $tld;
        }

        // Bootstrap: fetch via SSH and cache (one-time only)
        $result = $this->ssh->execute($environment, 'cat ~/.config/orbit/config.json 2>/dev/null');
        if ($result['success'] && ! empty($result['output'])) {
            $config = json_decode((string) $result['output'], true);
            $tld = $config['tld'] ?? 'test';
        } else {
            $tld = 'test';
        }

        $environment->update(['tld' => $tld]);

        return $tld;
    }

    /**
     * Get the Saloon connector for the orbit web app API.
     */
    public function getConnector(Environment $environment, int $timeout = 30): OrbitConnector
    {
        $tld = $this->getTld($environment);

        return OrbitConnector::forEnvironment($tld, $timeout);
    }

    /**
     * Send a Saloon request and return the result as an array.
     */
    public function sendRequest(Environment $environment, Request $request): array
    {
        return $this->sendRequestWithTimeout($environment, $request, 30);
    }

    /**
     * Send a Saloon request with configurable timeout.
     */
    public function sendRequestWithTimeout(Environment $environment, Request $request, int $timeout): array
    {
        try {
            $connector = $this->getConnector($environment, $timeout);
            $response = $connector->send($request);

            if ($response->successful()) {
                return $response->json() ?? ['success' => true];
            }

            return [
                'success' => false,
                'error' => $response->json('error') ?? 'Request failed with status '.$response->status(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Connection error: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Read local config file to get TLD.
     * Minimal version - only reads what's needed for TLD.
     */
    protected function getLocalConfigForTld(): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? $_ENV['HOME'] ?? '');
        $configPath = $home.'/.config/orbit/config.json';

        if (! file_exists($configPath)) {
            return ['tld' => 'test'];
        }

        $content = file_get_contents($configPath);
        $config = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['tld' => 'test'];
        }

        return $config;
    }
}
