<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Commands\LocalOnlyCommand;
use App\Services\OrbitConfigStore;

final class GatewayListCommand extends LocalOnlyCommand
{
    #[\Override]
    protected $signature = 'gateway:list {--json}';

    #[\Override]
    protected $description = 'List configured local gateway entries.';

    public function handle(OrbitConfigStore $configStore): int
    {
        $gateways = $configStore->gatewayEntries();

        if ($gateways === []) {
            return $this->renderFailure(
                'validation_failed',
                'No gateways are configured. Run orbit gateway:add first.',
                ['field' => 'gateway', 'reason' => 'missing'],
            );
        }

        $activeGateway = $configStore->activeGatewayName();

        return $this->renderSuccess([
            'active_gateway' => $activeGateway,
            'gateways' => array_map(
                fn (string $name, array $entry): array => $this->gatewayPayload($name, $entry, $name === $activeGateway),
                array_keys($gateways),
                array_values($gateways),
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function gatewayPayload(string $name, array $entry, bool $active): array
    {
        return array_filter([
            'name' => $name,
            'active' => $active,
            'url' => is_string($entry['url'] ?? null) ? $entry['url'] : null,
            'wireguard_ip' => is_string($entry['wireguard_ip'] ?? null) ? $entry['wireguard_ip'] : null,
            'ca_fingerprint' => is_string($entry['ca_fingerprint'] ?? null) ? $entry['ca_fingerprint'] : null,
            'timeout' => is_numeric($entry['timeout'] ?? null) ? (int) $entry['timeout'] : null,
            'self_mode' => is_string($entry['self_mode'] ?? null) ? $entry['self_mode'] : null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
