<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Vpn\VpnFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('vpn-client:new
    {name : VPN client name}
    {--config : Include the generated WireGuard config}
    {--totp= : One-time code for the gateway VPN backend}
    {--json : Output as JSON}')]
#[Description('Create a non-node gateway VPN client')]
final class VpnClientNewCommand extends VpnCommandSupport
{
    public function handle(): int
    {
        $this->bootActivityLog();

        try {
            return $this->executeVpnClientNew();
        } finally {
            $this->finishActivityLog();
        }
    }

    private function executeVpnClientNew(): int
    {
        $denied = $this->ensureAllowedCaller();

        if ($denied !== null) {
            return $denied;
        }

        $name = $this->stringArgument('name');

        if ($name === null) {
            return $this->failCommand('validation_failed', 'VPN client name is required.', ['field' => 'name']);
        }

        $this->activityClientName = $name;
        $includeConfig = (bool) $this->option('config');
        $totp = $this->stringOption('totp');

        if ($this->callerRole() === 'control') {
            $this->activityForwardedToGateway = true;
            $payload = $this->forwardToGateway('vpn-client:new', [$name], [
                'config' => $includeConfig,
                'totp' => $totp,
            ]);

            return $payload instanceof VpnFailure ? $this->failFrom($payload) : $this->respondGatewayPayload($payload);
        }

        $client = $this->manager()->create($name, $includeConfig, $totp);

        if ($client instanceof VpnFailure) {
            return $this->failFrom($client);
        }

        $data = ['client' => $client->toArray(includeConfig: $includeConfig)];
        $meta = ['config_included' => $includeConfig];
        $this->activityActionResult = 'created';

        if ($this->wantsJson()) {
            return $this->jsonSuccess($data, $meta);
        }

        return $this->renderData($data, $meta);
    }

    #[\Override]
    protected function renderData(array $data, array $meta): int
    {
        $client = is_array($data['client'] ?? null) ? $data['client'] : [];
        $this->line("VPN client `{$client['name']}` created");
        $this->line("Address: {$client['address']}");

        if (is_string($client['config'] ?? null) && $client['config'] !== '') {
            $this->newLine();
            $this->line('# --- wg-orbit.conf ---');
            $this->line(rtrim($client['config']));
        }

        return self::SUCCESS;
    }
}
