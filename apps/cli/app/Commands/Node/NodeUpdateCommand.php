<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class NodeUpdateCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:update
        {name? : Node name to update}
        {--host= : New SSH/bootstrap endpoint}
        {--tld= : Development TLD for app-dev role assignments}
        {--gateway-endpoint= : WireGuard endpoint host this node should use to reach the gateway}
        {--public-ipv4= : Public IPv4 address metadata}
        {--public-ipv6= : Public IPv6 address metadata}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Update node registry metadata and role-owned settings.';

    public function handle(): int
    {
        $name = $this->stringArgument('name');

        if ($name === null) {
            return $this->renderFailure('validation_failed', 'Node name is required.', ['field' => 'name']);
        }

        $payload = array_filter([
            'host' => $this->stringOption('host'),
            'tld' => $this->stringOption('tld'),
            'gateway_endpoint' => $this->stringOption('gateway-endpoint'),
            'public_ipv4' => $this->stringOption('public-ipv4'),
            'public_ipv6' => $this->stringOption('public-ipv6'),
        ], fn (?string $value): bool => $value !== null);

        if ($payload === []) {
            return $this->renderFailure('validation_failed', 'At least one field must be provided to update a node.', ['field' => 'fields']);
        }

        try {
            $response = $this->gatewayPut('/api/nodes/'.rawurlencode($name), $payload);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
