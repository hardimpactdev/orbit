<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Services\Ca\OrbitCaService;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Runtime\OrbitContainerNames;
use App\Tools\CaddyTool;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Docker-first gateway API runtime installer.
 *
 * The gateway API is the gateway `orbit-runtime` container, exposed through
 * the gateway `orbit-caddy` container. The installer issues the leaf
 * certificate, writes the gateway-API Caddy site that orbit-caddy serves on
 * the WireGuard address, and reloads orbit-caddy. It does not install or
 * restart host PHP, host PHP-FPM, or host Caddy.
 *
 * @see docs/domains/2_gateway/README.md — "The gateway API runtime is the
 *     gateway `orbit-runtime` container, exposed on the Orbit network
 *     through the gateway `orbit-caddy` container."
 */
class GatewayApiRuntimeInstaller
{
    /**
     * HTTP port the gateway's `orbit-runtime` container listens on for the
     * typed Orbit API. `orbit-caddy` reverse-proxies HTTPS gateway traffic
     * to this internal HTTP port over the orbit-network bridge.
     */
    public const RuntimeApiPort = 8080;

    public function __construct(
        private readonly OrbitCaService $caService,
        private readonly CaddyGlobalConfig $caddyGlobalConfig,
        private readonly CaddyTool $caddyTool = new CaddyTool,
        private readonly OrbitContainerNames $containerNames = new OrbitContainerNames,
    ) {}

    public function install(string $wireguardAddress, string $phpVersion = '8.5', string $orbitPath = ''): void
    {
        if (filter_var($wireguardAddress, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException("Invalid WireGuard API address: {$wireguardAddress}");
        }

        $leaf = $this->caService->issueLeaf($wireguardAddress);

        $this->ensureOrbitCaddyContainer($wireguardAddress);
        $this->runRequiredWithInput('sudo tee /etc/caddy/orbit/orbit-api.caddy > /dev/null', $this->gatewayApiCaddyfile(
            wireguardAddress: $wireguardAddress,
            certPath: $this->caddyVisiblePath($leaf['cert']),
            keyPath: $this->caddyVisiblePath($leaf['key']),
        ), 'write Orbit API Caddy config');
        $this->runRequired(CaddyTool::reloadCommand($this->containerNames->caddy()), 'reload orbit-caddy container');
    }

    /**
     * Translate a path that the runtime container sees under
     * `/opt/orbit/...` into the host path that the same file lives at,
     * so orbit-caddy can read it through its `/home` bind mount. When the
     * installer is run outside the container (no ORBIT_HOST_PATH), the
     * original path is returned unchanged.
     */
    private function caddyVisiblePath(string $containerPath): string
    {
        $hostPath = trim((string) getenv('ORBIT_HOST_PATH'));
        $sourcePath = '/opt/orbit';

        if ($hostPath === '' || $hostPath === $sourcePath) {
            return $containerPath;
        }

        if ($containerPath === $sourcePath) {
            return $hostPath;
        }

        if (str_starts_with($containerPath, $sourcePath.'/')) {
            return $hostPath.substr($containerPath, strlen($sourcePath));
        }

        return $containerPath;
    }

    /**
     * Converge the gateway orbit-caddy container from the role-appropriate
     * `OrbitCaddyContainer::forPrivateNode($wireguardAddress)` spec before
     * the gateway-API site is written. Without this, fresh gateway hosts
     * end up with no orbit-caddy container to restart and bootstrap fails
     * silently into a host caddy fallback.
     */
    private function ensureOrbitCaddyContainer(string $wireguardAddress): void
    {
        $container = OrbitCaddyContainer::forPrivateNode($wireguardAddress, $this->containerNames);

        $this->runRequired('sudo install -d -m 0755 /etc/caddy /etc/caddy/orbit /etc/caddy/sites', 'prepare Caddy config directories');
        $this->ensureGlobalCaddyfile();

        $script = $this->caddyTool->updateScript(['container' => $container->spec()]);

        $this->runShellScript($script, 'converge orbit-caddy container');
    }

    private function gatewayApiCaddyfile(
        string $wireguardAddress,
        string $certPath,
        string $keyPath,
    ): string {
        $runtimeAlias = $this->containerNames->runtime();
        $port = self::RuntimeApiPort;

        return <<<CADDY
https://{$wireguardAddress}:443 {
    tls {$certPath} {$keyPath}
    encode zstd gzip

    request_header -X-Forwarded-For
    request_header -X-Real-IP
    request_header -Forwarded

    reverse_proxy http://{$runtimeAlias}:{$port} {
        header_up Host {host}
        header_up X-Forwarded-Proto https
    }
}

CADDY;
    }

    private function ensureGlobalCaddyfile(): void
    {
        $contents = $this->readOptional('/etc/caddy/Caddyfile');
        $updated = $this->caddyGlobalConfig->ensure($contents);

        if ($updated === $contents) {
            return;
        }

        $this->runRequiredWithInput('sudo tee /etc/caddy/Caddyfile > /dev/null', $updated, 'write global Caddy config');
    }

    private function readOptional(string $path): string
    {
        $command = 'sudo test -f '.escapeshellarg($path).' && sudo cat '.escapeshellarg($path).' || true';
        $result = Process::timeout(30)->run($command);

        if ($result->successful()) {
            return $result->output();
        }

        throw new RuntimeException("Failed to read {$path}: ".$this->output($result->errorOutput(), $result->output()));
    }

    private function runRequired(string $command, string $step): void
    {
        $result = Process::timeout(60)->run($command);

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException("Failed to {$step}: ".$this->output($result->errorOutput(), $result->output()));
    }

    private function runRequiredWithInput(string $command, string $input, string $step): void
    {
        $result = Process::timeout(60)->input($input)->run($command);

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException("Failed to {$step}: ".$this->output($result->errorOutput(), $result->output()));
    }

    private function runShellScript(string $script, string $step): void
    {
        $result = Process::timeout(180)->input($script)->run('bash -s');

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException("Failed to {$step}: ".$this->output($result->errorOutput(), $result->output()));
    }

    private function output(string $errorOutput, string $output): string
    {
        $message = trim($errorOutput.' '.$output);

        return $message !== '' ? $message : 'unknown error';
    }
}
