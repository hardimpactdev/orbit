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
        $path = "{$configRoot}/agent.toml";
        $caDir = "{$configRoot}/ca";
        $caPath = "{$configRoot}/ca/root.crt";

        File::ensureDirectoryExists($configRoot, 0o711);
        File::put($path, $this->contents($gatewayNode));

        if (! chmod($configRoot, 0o711)) {
            throw new RuntimeException("Unable to set traversable permissions on {$configRoot}.");
        }

        if (! chmod($path, 0o644)) {
            throw new RuntimeException("Unable to set host-readable permissions on {$path}.");
        }

        if (File::isDirectory($caDir) && ! chmod($caDir, 0o711)) {
            throw new RuntimeException("Unable to set traversable permissions on {$caDir}.");
        }

        if (File::exists($caPath) && ! chmod($caPath, 0o644)) {
            throw new RuntimeException("Unable to set host-readable permissions on {$caPath}.");
        }

        return $path;
    }

    private function configRoot(): string
    {
        $configuredRoot = config('orbit.paths.config_root');

        if (! is_string($configuredRoot) || trim($configuredRoot) === '') {
            return '/home/orbit/.config/orbit';
        }

        return rtrim($configuredRoot, '/');
    }

    private function contents(Node $gatewayNode): string
    {
        $gatewayAddress = $gatewayNode->wireguard_address ?: $gatewayNode->host;

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
