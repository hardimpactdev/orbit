<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActivityLogType;
use App\Services\Vpn\VpnFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use RuntimeException;

use function Laravel\Prompts\table;

#[Signature('vpn-client:list
    {--totp= : One-time code for the gateway VPN backend}
    {--json : Output as JSON}')]
#[Description('List gateway VPN backend clients')]
final class VpnClientListCommand extends VpnCommandSupport
{
    public function handle(): int
    {
        $this->bootActivityLog();

        try {
            return $this->executeVpnClientList();
        } finally {
            $this->finishActivityLog();
        }
    }

    #[\Override]
    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    private function executeVpnClientList(): int
    {
        $denied = $this->ensureAllowedCaller();

        if ($denied !== null) {
            return $denied;
        }

        if (! (bool) config('orbit.is_gateway', false)) {
            $this->activityForwardedToGateway = true;
            $payload = $this->forwardToGateway('vpn-client:list', options: ['totp' => $this->stringOption('totp')]);

            return $payload instanceof VpnFailure ? $this->failFrom($payload) : $this->respondGatewayPayload($payload);
        }

        try {
            $clients = $this->manager()->list($this->stringOption('totp'));
        } catch (RuntimeException $e) {
            return $this->failCommand('vpn_backend_unavailable', $e->getMessage());
        }

        $data = ['clients' => array_map(fn ($client): array => $client->toArray(), $clients)];
        $meta = ['count' => count($clients)];
        $this->activityCount = $meta['count'];

        if ($this->wantsJson()) {
            return $this->jsonSuccess($data, $meta);
        }

        return $this->renderData($data, $meta);
    }

    #[\Override]
    protected function renderData(array $data, array $meta): int
    {
        $clients = is_array($data['clients'] ?? null) ? $data['clients'] : [];

        if ($clients === []) {
            $this->line('No VPN clients configured.');

            return self::SUCCESS;
        }

        table(['Name', 'Address', 'Enabled', 'Kind', 'Latest handshake'], array_map(static fn (array $client): array => [
            $client['name'] ?? '',
            $client['address'] ?? '',
            ($client['enabled'] ?? false) ? 'yes' : 'no',
            $client['kind'] ?? 'unknown',
            $client['latest_handshake_at'] ?? 'never',
        ], $clients));

        return self::SUCCESS;
    }
}
