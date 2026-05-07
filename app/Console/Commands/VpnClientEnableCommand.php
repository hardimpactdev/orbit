<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\Vpn\VpnClientMutationResult;
use App\Services\Vpn\VpnFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('vpn-client:enable
    {name : VPN client name}
    {--totp= : One-time code for the gateway VPN backend}
    {--json : Output as JSON}')]
#[Description('Enable a non-node gateway VPN client')]
class VpnClientEnableCommand extends VpnCommandSupport
{
    public function handle(): int
    {
        $this->bootActivityLog();

        try {
            return $this->handleToggle(enabled: true);
        } finally {
            $this->finishActivityLog();
        }
    }

    protected function handleToggle(bool $enabled): int
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
        $command = $enabled ? 'vpn-client:enable' : 'vpn-client:disable';

        if ($this->callerRole() === 'control') {
            $this->activityForwardedToGateway = true;
            $payload = $this->forwardToGateway($command, [$name], ['totp' => $this->stringOption('totp')]);

            return $payload instanceof VpnFailure ? $this->failFrom($payload) : $this->respondGatewayPayload($payload);
        }

        $result = $enabled
            ? $this->manager()->enable($name, $this->stringOption('totp'))
            : $this->manager()->disable($name, $this->stringOption('totp'));

        if ($result instanceof VpnFailure) {
            return $this->failFrom($result);
        }

        return $this->respond($result);
    }

    protected function respond(VpnClientMutationResult $result): int
    {
        $alreadyKey = $result->action === 'disabled' ? 'already_disabled' : 'already_enabled';
        $this->activityActionResult = $result->action;

        $data = [
            'client' => [
                'name' => $result->client->name,
                'enabled' => $result->client->enabled,
                'action' => $result->action,
                $alreadyKey => $result->alreadyInDesiredState,
            ],
        ];

        if ($this->wantsJson()) {
            return $this->jsonSuccess($data);
        }

        return $this->renderData($data, []);
    }

    #[\Override]
    protected function renderData(array $data, array $meta): int
    {
        $client = is_array($data['client'] ?? null) ? $data['client'] : [];
        $action = (string) ($client['action'] ?? 'enabled');
        $already = (bool) ($client['already_enabled'] ?? $client['already_disabled'] ?? false);

        $this->line($already
            ? "VPN client `{$client['name']}` already {$action}"
            : "VPN client `{$client['name']}` {$action}");

        return self::SUCCESS;
    }
}
