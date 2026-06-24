<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Commands\LocalOnlyCommand;
use App\Services\OrbitConfigStore;

use function Laravel\Prompts\table;

final class GatewayListCommand extends LocalOnlyCommand
{
    private const string ActiveMarker = "\e[32m●\e[0m";

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

        $payloads = array_map(
            fn (string $name, array $entry): array => $this->gatewayPayload($name, $entry, $name === $activeGateway),
            array_keys($gateways),
            array_values($gateways),
        );

        if ($this->wantsJson()) {
            return $this->renderSuccess([
                'active_gateway' => $activeGateway,
                'gateways' => $payloads,
            ]);
        }

        $this->line('Active gateway: '.($activeGateway ?? '—'));
        $this->newLine();

        table(
            headers: ['', 'NAME', 'URL', 'WIREGUARD IP'],
            rows: array_map(fn (array $gateway): array => [
                ($gateway['active'] ?? false) === true ? self::ActiveMarker : '',
                $this->gatewayString($gateway, 'name'),
                $this->gatewayString($gateway, 'url'),
                $this->gatewayString($gateway, 'wireguard_ip'),
            ], $payloads),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $gateway
     */
    private function gatewayString(array $gateway, string $key): string
    {
        $value = $gateway[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function gatewayPayload(string $name, array $entry, bool $active): array
    {
        return array_filter(
            [
                'name' => $name,
                'active' => $active,
                'url' => is_string($entry['url'] ?? null) ? $entry['url'] : null,
                'wireguard_ip' => is_string($entry['wireguard_ip'] ?? null) ? $entry['wireguard_ip'] : null,
                'ca_fingerprint' => is_string($entry['ca_fingerprint'] ?? null) ? $entry['ca_fingerprint'] : null,
                'timeout' => is_numeric($entry['timeout'] ?? null) ? (int) $entry['timeout'] : null,
                'self_mode' => is_string($entry['self_mode'] ?? null) ? $entry['self_mode'] : null,
            ],
            fn (mixed $value): bool => $value !== null,
        );
    }
}
