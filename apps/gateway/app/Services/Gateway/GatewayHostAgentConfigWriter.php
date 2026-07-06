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

        File::ensureDirectoryExists($configRoot, 0o700);
        File::put($path, $this->contents($gatewayNode));

        if (! chmod($path, 0o600)) {
            throw new RuntimeException("Unable to set private permissions on {$path}.");
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
            '',
        ]);
    }

    private function tomlString(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
