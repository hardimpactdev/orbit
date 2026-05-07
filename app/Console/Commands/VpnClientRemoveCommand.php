<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActivityLogType;
use App\Services\Vpn\VpnFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('vpn-client:remove
    {name : VPN client name}
    {--force : Confirm destructive operation without prompting}
    {--totp= : One-time code for the gateway VPN backend}
    {--json : Output as JSON}')]
#[Description('Remove a non-node gateway VPN client')]
final class VpnClientRemoveCommand extends VpnCommandSupport
{
    public function handle(): int
    {
        $this->bootActivityLog();

        try {
            return $this->executeVpnClientRemove();
        } finally {
            $this->finishActivityLog();
        }
    }

    #[\Override]
    public function effect(): ActivityLogType
    {
        return ActivityLogType::Destructive;
    }

    private function executeVpnClientRemove(): int
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

        if (! $this->option('force')) {
            if (! $this->isInteractiveInput()) {
                return $this->failCommand('validation_failed', 'Use --force to remove this VPN client.', [
                    'field' => 'force',
                    'reason' => 'destructive_consent_required',
                ]);
            }

            $confirmed = $this->confirmOrFail("Remove VPN client {$name}?");

            if (is_int($confirmed)) {
                return $confirmed;
            }
        }

        if ($this->callerRole() === 'control') {
            $this->activityForwardedToGateway = true;
            $payload = $this->forwardToGateway('vpn-client:remove', [$name], [
                'force' => true,
                'totp' => $this->stringOption('totp'),
            ]);

            return $payload instanceof VpnFailure ? $this->failFrom($payload) : $this->respondGatewayPayload($payload);
        }

        $result = $this->manager()->remove($name, $this->stringOption('totp'));

        if ($result instanceof VpnFailure) {
            return $this->failFrom($result);
        }

        $data = ['client' => $result];
        $this->activityActionResult = 'removed';

        if ($this->wantsJson()) {
            return $this->jsonSuccess($data);
        }

        return $this->renderData($data, []);
    }

    #[\Override]
    protected function renderData(array $data, array $meta): int
    {
        $client = is_array($data['client'] ?? null) ? $data['client'] : [];
        $this->line("VPN client `{$client['name']}` removed");

        return self::SUCCESS;
    }
}
