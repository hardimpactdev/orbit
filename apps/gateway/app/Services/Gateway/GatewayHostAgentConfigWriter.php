<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Models\Node;
use Illuminate\Support\Facades\File;
use RuntimeException;

class GatewayHostAgentConfigWriter
{
    public function write(Node $gatewayNode): string
    {
        $configRoot = $this->configRoot();
        $path = $this->path();
        $cliConfigPath = "{$configRoot}/config.json";
        $caDir = "{$configRoot}/ca";
        $caPath = "{$configRoot}/ca/root.crt";

        File::ensureDirectoryExists($configRoot, 0o711);
        File::put($path, $this->contents($gatewayNode));

        if (! chmod(filename: $configRoot, permissions: 0o711)) {
            throw new RuntimeException("Unable to set traversable permissions on {$configRoot}.");
        }

        if (! chmod(filename: $path, permissions: 0o644)) {
            throw new RuntimeException("Unable to set host-readable permissions on {$path}.");
        }

        if (File::exists($cliConfigPath) && ! chmod(filename: $cliConfigPath, permissions: 0o644)) {
            throw new RuntimeException("Unable to set host-readable permissions on {$cliConfigPath}.");
        }

        if (File::isDirectory($caDir) && ! chmod(filename: $caDir, permissions: 0o711)) {
            throw new RuntimeException("Unable to set traversable permissions on {$caDir}.");
        }

        if (File::exists($caPath) && ! chmod(filename: $caPath, permissions: 0o644)) {
            throw new RuntimeException("Unable to set host-readable permissions on {$caPath}.");
        }

        return $path;
    }

    public function path(): string
    {
        return $this->configRoot().'/agent.toml';
    }

    private function configRoot(): string
    {
        $configuredRoot = config('orbit.paths.config_root');

        if (! is_string($configuredRoot) || trim($configuredRoot) === '') {
            return '/home/orbit/.config/orbit';
        }

        return rtrim($configuredRoot, characters: '/');
    }

    private function contents(Node $gatewayNode): string
    {
        if ($gatewayNode->hasActiveRole('gateway')) {
            $gatewayAddress = 'gateway';

            return $this->contentsForAddress($gatewayNode, $gatewayAddress);
        }

        $wireguardAddress = is_string($gatewayNode->wireguard_address)
            ? trim($gatewayNode->wireguard_address)
            : '';
        $gatewayAddress = $wireguardAddress !== '' ? $wireguardAddress : $gatewayNode->host;

        return $this->contentsForAddress($gatewayNode, $gatewayAddress);
    }

    private function contentsForAddress(Node $gatewayNode, mixed $gatewayAddress): string
    {
        if (! is_string($gatewayAddress) || trim($gatewayAddress) === '') {
            throw new RuntimeException("Gateway node [{$gatewayNode->name}] must have a WireGuard address or host.");
        }

        $gatewayUrl = "https://{$gatewayAddress}";

        return implode("\n", [
            'gateway_url = "'.$this->tomlString($gatewayUrl).'"',
            'node_id = "'.$this->tomlString((string) $gatewayNode->getKey()).'"',
            'node_name = "'.$this->tomlString($gatewayNode->name).'"',
            'gateway_name = "'.$this->tomlString($gatewayNode->name).'"',
            'ca_pem_path = "'.$this->tomlString($this->caPemPath()).'"',
            '',
        ]);
    }

    private function caPemPath(): string
    {
        return $this->configRoot().'/ca/root.crt';
    }

    private function tomlString(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
