<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Services\Ca\OrbitCaService;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class GatewayApiRuntimeInstaller
{
    public function __construct(
        private readonly OrbitCaService $caService,
    ) {}

    public function install(string $wireguardAddress, string $phpVersion = '8.5', string $orbitPath = ''): void
    {
        if (filter_var($wireguardAddress, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException("Invalid WireGuard API address: {$wireguardAddress}");
        }

        if (! preg_match('/^\d+\.\d+$/', $phpVersion)) {
            throw new RuntimeException("Invalid PHP-FPM version: {$phpVersion}");
        }

        $orbitPath = $orbitPath !== '' ? $orbitPath : base_path();
        $leaf = $this->caService->issueLeaf($wireguardAddress);

        $this->runRequired($this->grantCaddyAccessScript($leaf['cert'], $leaf['key']), 'grant Caddy access to Orbit TLS files');
        $this->runRequiredWithInput(
            "sudo tee /etc/php/{$phpVersion}/fpm/pool.d/orbit-api.conf > /dev/null",
            $this->fpmPool($orbitPath),
            'write Orbit API PHP-FPM pool',
        );
        $this->runRequired('sudo install -d -m 0755 /etc/caddy', 'prepare Caddy config directory');
        $this->runRequiredWithInput('sudo tee /etc/caddy/Caddyfile > /dev/null', $this->caddyfile(
            wireguardAddress: $wireguardAddress,
            orbitPath: $orbitPath,
            certPath: $leaf['cert'],
            keyPath: $leaf['key'],
        ), 'write Caddy config');
        $this->runRequired("sudo systemctl restart php{$phpVersion}-fpm", 'restart PHP-FPM');
        $this->runRequired('sudo systemctl restart caddy', 'restart Caddy');
        $this->runRequired('sudo systemctl enable caddy', 'enable Caddy');
    }

    private function fpmPool(string $orbitPath): string
    {
        return <<<FPM
[orbit-api]
user = orbit
group = orbit
listen = /run/php/orbit-api.sock
listen.owner = orbit
listen.group = caddy
listen.mode = 0660
pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 10s
chdir = {$orbitPath}

FPM;
    }

    private function caddyfile(
        string $wireguardAddress,
        string $orbitPath,
        string $certPath,
        string $keyPath,
    ): string {
        return <<<CADDY
https://{$wireguardAddress}:443 {
    tls {$certPath} {$keyPath}
    root * {$orbitPath}/public
    encode zstd gzip
    php_fastcgi unix//run/php/orbit-api.sock
    file_server
}

CADDY;
    }

    private function grantCaddyAccessScript(string $certPath, string $keyPath): string
    {
        $paths = [
            dirname(base_path()),
            base_path(),
            storage_path(),
            storage_path('app'),
            storage_path('app/orbit'),
            dirname($certPath),
        ];

        $chmodDirs = implode(' ', array_map(escapeshellarg(...), array_unique($paths)));
        $chmodFiles = escapeshellarg($certPath).' '.escapeshellarg($keyPath);

        return <<<BASH
if id caddy >/dev/null 2>&1 && getent group orbit >/dev/null 2>&1; then
    sudo usermod -aG orbit caddy
    sudo chmod g+rx {$chmodDirs}
    sudo chmod g+r {$chmodFiles}
fi
BASH;
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

    private function output(string $errorOutput, string $output): string
    {
        $message = trim($errorOutput.' '.$output);

        return $message !== '' ? $message : 'unknown error';
    }
}
