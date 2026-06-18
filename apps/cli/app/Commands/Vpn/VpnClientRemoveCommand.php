<?php

declare(strict_types=1);

namespace App\Commands\Vpn;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;

final class VpnClientRemoveCommand extends VpnGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'vpn-client:remove
        {name? : VPN client name}
        {--force : Confirm destructive operation without prompting}
        {--totp= : One-time code for the gateway VPN backend}
        {--json : Output as JSON}';

    #[\Override]
    protected $description = 'Remove a non-node gateway VPN client through the gateway.';

    public function handle(): int
    {
        $name = $this->promptForArgument('name', 'name', 'VPN client name', 'VPN client name is required.');

        if (is_int($name)) {
            return $name;
        }

        $consent = $this->confirmForce('Use --force to remove this VPN client.');

        if (is_int($consent)) {
            return $consent;
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->removeClient($name);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderRemovalTree($name);
    }

    private function renderRemovalTree(string $name): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Removing VPN client',
            [
                ['label' => 'Resolve VPN runtime'],
                ['label' => 'Authenticate VPN backend'],
                ['label' => 'Remove VPN client'],
            ],
            work: function () use ($name, &$response): array {
                return $response = $this->removeClientForHuman($name);
            },
            doneFooter: "VPN client `{$name}` removed",
        );

        return $outcome->isCompleted() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function removeClient(string $name): array
    {
        return $this->gatewayDelete('/api/vpn/clients/'.rawurlencode($name), [
            'force' => true,
            ...$this->totpPayload(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function removeClientForHuman(string $name): array
    {
        try {
            return $this->removeClient($name);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }
}
