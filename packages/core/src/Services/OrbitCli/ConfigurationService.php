<?php
declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\OrbitCli;

use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetConfigRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetPhpRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetPhpVersionsRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\ResetPhpRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\SetPhpRequest;
use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\ConnectorService;
use HardImpact\Orbit\Core\Services\SshService;

/**
 * Service for orbit configuration management.
 */
class ConfigurationService
{
    public function __construct(
        protected ConnectorService $connector,
        protected CommandService $command,
        protected SshService $ssh,
        protected StatusService $status
    ) {}

    /**
     * Get orbit configuration for an environment.
     */
    public function getConfig(Environment $environment): array
    {
        if ($environment->is_local) {
            return $this->getLocalConfig();
        }

        return $this->getRemoteConfig($environment);
    }

    /**
     * Save orbit configuration for an environment.
     */
    public function saveConfig(Environment $environment, array $config): array
    {
        if ($environment->is_local) {
            return $this->saveLocalConfig($config);
        }

        return $this->saveRemoteConfig($environment, $config);
    }

    /**
     * Get or set PHP version for a project.
     */
    public function php(Environment $environment, string $site, ?string $version = null): array
    {
        if ($environment->is_local) {
            $command = $version
                ? "php {$site} {$version} --json"
                : "php {$site} --json";

            return $this->command->executeCommand($environment, $command);
        }

        if ($version) {
            return $this->connector->sendRequest($environment, new SetPhpRequest($site, $version));
        }

        return $this->connector->sendRequest($environment, new GetPhpRequest($site));
    }

    /**
     * Reset PHP version for a project to default.
     */
    public function phpReset(Environment $environment, string $site): array
    {
        if ($environment->is_local) {
            return $this->command->executeCommand($environment, "php {$site} --reset --json");
        }

        return $this->connector->sendRequest($environment, new ResetPhpRequest($site));
    }

    /**
     * Get Reverb WebSocket configuration for real-time updates.
     * Uses status endpoint to check if Reverb service is running.
     */
    public function getReverbConfig(Environment $environment): array
    {
        $status = $this->status->status($environment);

        if (! $status['success']) {
            return [
                'success' => false,
                'error' => $status['error'] ?? 'Failed to get status',
            ];
        }

        $statusData = $status['data'] ?? [];
        $services = $statusData['services'] ?? [];

        // Check if Reverb is running
        $reverbService = $services['reverb'] ?? null;
        $reverbRunning = ($reverbService['status'] ?? '') === 'running';

        if (! $reverbRunning) {
            return [
                'success' => true,
                'enabled' => false,
            ];
        }

        // Get TLD from environment cache or status
        $tld = $environment->tld ?? $statusData['tld'] ?? 'test';

        // Caddy terminates TLS for reverb.{tld} and proxies to the Reverb container
        return [
            'success' => true,
            'enabled' => true,
            'host' => "reverb.{$tld}",
            'port' => 443,
            'scheme' => 'https',
            'app_key' => 'orbit-key',
        ];
    }

    /**
     * Get default configuration values.
     */
    public function getDefaultConfig(): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? $_ENV['HOME'] ?? '/home/user');

        return [
            'paths' => [$home.'/projects'],
            'tld' => 'test',
            'default_php_version' => '8.4',
        ];
    }

    /**
     * Get local configuration.
     */
    protected function getLocalConfig(): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? $_ENV['HOME'] ?? '');
        $configPath = $home.'/.config/orbit/config.json';

        if (! file_exists($configPath)) {
            $data = $this->getDefaultConfig();
            $data['available_php_versions'] = $this->getLocalAvailablePhpVersions();

            return [
                'success' => true,
                'data' => $data,
                'exists' => false,
            ];
        }

        $content = file_get_contents($configPath);
        $config = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Failed to parse config: '.json_last_error_msg(),
            ];
        }

        $data = array_merge($this->getDefaultConfig(), $config);
        $data['available_php_versions'] = $this->getLocalAvailablePhpVersions();

        return [
            'success' => true,
            'data' => $data,
            'exists' => true,
        ];
    }

    /**
     * Get available PHP versions for local environment.
     */
    protected function getLocalAvailablePhpVersions(): array
    {
        // Query docker for running orbit-php-* containers
        $output = shell_exec("docker ps --format '{{.Names}}' --filter 'name=orbit-php-' 2>/dev/null | grep -oE '[0-9]+' | sort -u");

        if (in_array($output, ['', '0', false, null], true)) {
            return ['8.3', '8.4', '8.5'];
        }

        $versions = [];
        $numbers = explode("\n", trim($output));

        foreach ($numbers as $num) {
            $num = trim($num);
            if (strlen($num) === 2) {
                $versions[] = substr($num, 0, 1).'.'.substr($num, 1);
            }
        }

        usort($versions, version_compare(...));

        return $versions === [] ? ['8.3', '8.4', '8.5'] : $versions;
    }

    /**
     * Get remote configuration via HTTP API.
     */
    protected function getRemoteConfig(Environment $environment): array
    {
        // Use HTTP API to get config
        $result = $this->connector->sendRequest($environment, new GetConfigRequest);

        if (! $result['success']) {
            // Fallback to defaults if API fails
            $data = $this->getDefaultConfig();
            $data['available_php_versions'] = $this->getAvailablePhpVersions($environment);

            return [
                'success' => true,
                'data' => $data,
                'exists' => false,
            ];
        }

        // API returns flat response, merge with defaults
        $data = array_merge($this->getDefaultConfig(), $result);
        unset($data['success']); // Remove success flag from data

        $data['available_php_versions'] = $this->getAvailablePhpVersions($environment);

        return [
            'success' => true,
            'data' => $data,
            'exists' => true,
        ];
    }

    /**
     * Get available PHP versions by scanning running containers.
     */
    protected function getAvailablePhpVersions(Environment $environment): array
    {
        // For remote environments, use HTTP API
        if (! $environment->is_local) {
            $result = $this->connector->sendRequest($environment, new GetPhpVersionsRequest);
            if ($result['success'] && isset($result['versions'])) {
                return $result['versions'];
            }

            // Fallback to default versions
            return ['8.3', '8.4', '8.5'];
        }

        // For local environments, query docker for running orbit-php-* containers
        $result = $this->ssh->execute(
            $environment,
            "docker ps --format '{{.Names}}' --filter 'name=orbit-php-' 2>/dev/null | grep -oE '[0-9]+' | sort -u"
        );

        if (! $result['success'] || in_array(trim((string) $result['output']), ['', '0'], true)) {
            // Fallback to default versions
            return ['8.3', '8.4', '8.5'];
        }

        $versions = [];
        $numbers = explode("\n", trim((string) $result['output']));

        foreach ($numbers as $num) {
            $num = trim($num);
            if (strlen($num) === 2) {
                // Convert "83" to "8.3", "84" to "8.4", "85" to "8.5"
                $versions[] = substr($num, 0, 1).'.'.substr($num, 1);
            }
        }

        // Sort versions
        usort($versions, version_compare(...));

        return $versions === [] ? ['8.3', '8.4', '8.5'] : $versions;
    }

    /**
     * Save local configuration.
     */
    protected function saveLocalConfig(array $config): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? $_ENV['HOME'] ?? '');
        $configDir = $home.'/.config/orbit';
        $configPath = $configDir.'/config.json';

        // Ensure directory exists
        if (! is_dir($configDir) && ! mkdir($configDir, 0755, true)) {
            return [
                'success' => false,
                'error' => 'Failed to create config directory',
            ];
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($configPath, $json) === false) {
            return [
                'success' => false,
                'error' => 'Failed to write config file',
            ];
        }

        return [
            'success' => true,
            'data' => $config,
        ];
    }

    /**
     * Save config to remote environment via SSH.
     *
     * Note: Config write intentionally uses SSH rather than HTTP API.
     * The orbit-cli API does not expose a config write endpoint for security
     * reasons - allowing arbitrary config changes via HTTP could be exploited.
     * Config reads use the API (fast, no SSH overhead), but writes require SSH
     * authentication which provides an additional security layer.
     */
    protected function saveRemoteConfig(Environment $environment, array $config): array
    {
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $escapedJson = escapeshellarg($json);

        // Ensure directory exists and write file
        $command = "mkdir -p ~/.config/orbit && echo {$escapedJson} > ~/.config/orbit/config.json";
        $result = $this->ssh->execute($environment, $command);

        if (! $result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'Failed to save config',
            ];
        }

        return [
            'success' => true,
            'data' => $config,
        ];
    }

    /**
     * Get DNS mappings for an environment.
     */
    public function getDnsMappings(Environment $environment): array
    {
        $configResult = $this->getConfig($environment);

        if (! $configResult['success']) {
            return $configResult;
        }

        $config = $configResult['data'];
        $mappings = $config['dns_mappings'] ?? [
            ['type' => 'address', 'tld' => 'test', 'value' => '127.0.0.1'],
            ['type' => 'server', 'value' => '8.8.8.8'],
            ['type' => 'server', 'value' => '8.8.4.4'],
        ];

        return [
            'success' => true,
            'mappings' => $mappings,
        ];
    }

    /**
     * Set DNS mappings for an environment.
     */
    public function setDnsMappings(Environment $environment, array $mappings): array
    {
        $configResult = $this->getConfig($environment);

        if (! $configResult['success']) {
            return $configResult;
        }

        $config = $configResult['data'];
        $config['dns_mappings'] = $mappings;

        return $this->saveConfig($environment, $config);
    }
}
