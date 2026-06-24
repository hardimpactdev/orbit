<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Commands\LocalOnlyCommand;
use App\Services\OrbitConfigStore;

final class GatewayUseCommand extends LocalOnlyCommand
{
    #[\Override]
    protected $signature = 'gateway:use
        {name : Local gateway entry name}
        {--json}';

    #[\Override]
    protected $description = 'Select the active local gateway entry.';

    public function handle(OrbitConfigStore $configStore): int
    {
        $name = $this->argument('name');
        $name = is_string($name) ? trim($name) : '';

        if (! OrbitConfigStore::isValidGatewayName($name)) {
            return $this->renderFailure(
                'validation_failed',
                'Gateway name must be a local slug.',
                ['field' => 'name', 'reason' => 'invalid_name'],
            );
        }

        $entry = $configStore->gatewayEntry($name);

        if ($entry === null) {
            return $this->renderFailure(
                'validation_failed',
                "Gateway [{$name}] is not configured.",
                ['field' => 'name', 'reason' => 'not_found'],
            );
        }

        if (! $configStore->setActiveGateway($name)) {
            return $this->renderFailure(
                'node.local_config_write_failed',
                'Failed to store local gateway configuration.',
                ['gateway_name' => $name],
            );
        }

        $payload = $this->gatewayPayload($name, $entry);

        if ($this->wantsJson()) {
            return $this->renderSuccess([
                'result' => [
                    'action' => 'selected',
                ],
                'gateway' => $payload,
            ]);
        }

        $url = is_string($payload['url'] ?? null) ? $payload['url'] : null;

        $this->line(
            $url !== null
                ? "Now using gateway '{$name}' ({$url})."
                : "Now using gateway '{$name}'.",
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function gatewayPayload(string $name, array $entry): array
    {
        return array_filter(
            [
                'name' => $name,
                'active' => true,
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
