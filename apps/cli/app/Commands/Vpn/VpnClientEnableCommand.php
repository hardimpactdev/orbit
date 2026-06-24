<?php

declare(strict_types=1);

namespace App\Commands\Vpn;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use InvalidArgumentException;
use RuntimeException;

class VpnClientEnableCommand extends VpnGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'vpn-client:enable
        {name? : VPN client name}
        {--totp= : One-time code for the gateway VPN backend}
        {--json : Output as JSON}';

    #[\Override]
    protected $description = 'Enable a non-node gateway VPN client through the gateway.';

    public function handle(): int
    {
        return $this->handleToggle('enable');
    }

    protected function handleToggle(string $action): int
    {
        $name = $this->promptForArgument('name', 'name', 'VPN client name', 'VPN client name is required.');

        if (is_int($name)) {
            return $name;
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->toggleClient($name, $action);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderToggleTree($name, $action);
    }

    private function renderToggleTree(string $name, string $action): int
    {
        [$title, $step, $pastTense] = match ($action) {
            'enable' => ['Enabling VPN client', 'Enable VPN client', 'enabled'],
            'disable' => ['Disabling VPN client', 'Disable VPN client', 'disabled'],
            default => throw new InvalidArgumentException("Unsupported VPN client toggle action: {$action}."),
        };
        $response = [];

        $outcome = $this->runStepOperation(
            $title,
            [
                ['label' => 'Resolve VPN runtime'],
                ['label' => 'Authenticate VPN backend'],
                ['label' => $step],
            ],
            work: function () use ($name, $action, &$response): array {
                return $response = $this->toggleClientForHuman($name, $action);
            },
            doneFooter: function () use (&$response, $name, $pastTense): string {
                return $this->wasAlreadyInDesiredState($response, $pastTense)
                    ? "VPN client `{$name}` already {$pastTense}"
                    : "VPN client `{$name}` {$pastTense}";
            },
        );

        return $outcome->isCompleted() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function toggleClient(string $name, string $action): array
    {
        return $this->gatewayPost(
            '/api/vpn/clients/'.rawurlencode($name)."/{$action}",
            $this->totpPayload(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toggleClientForHuman(string $name, string $action): array
    {
        try {
            return $this->toggleClient($name, $action);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function wasAlreadyInDesiredState(array $response, string $pastTense): bool
    {
        $data = $response['success']['data'] ?? null;
        $client = is_array($data) ? $data['client'] ?? null : null;

        return is_array($client) && ($client["already_{$pastTense}"] ?? false) === true;
    }
}
