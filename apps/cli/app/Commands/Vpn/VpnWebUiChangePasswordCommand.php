<?php

declare(strict_types=1);

namespace App\Commands\Vpn;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;

final class VpnWebUiChangePasswordCommand extends VpnGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'vpn-web-ui:change-password
        {password? : New gateway VPN backend web UI password}
        {--force : Confirm credential rotation without prompting}
        {--totp= : One-time code for the gateway VPN backend}
        {--json : Output as JSON}';

    #[\Override]
    protected $description = 'Change the gateway VPN web UI password through the gateway.';

    public function handle(): int
    {
        $password = $this->stringArgument('password');

        if ($password === null) {
            if ($this->wantsJson() || ! $this->input->isInteractive()) {
                return $this->failValidation('password', 'VPN web UI password is required.');
            }

            $answer = $this->secret('New VPN web UI password');
            $password = is_string($answer) && trim($answer) !== '' ? trim($answer) : null;
        }

        if ($password === null) {
            return $this->failValidation('password', 'VPN web UI password is required.');
        }

        if (mb_strlen($password) < 12) {
            return $this->failValidation('password', 'Password must be at least 12 characters.');
        }

        $consent = $this->confirmForce('Use --force to rotate the VPN web UI password.');

        if (is_int($consent)) {
            return $consent;
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->rotatePassword($password);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderRotationTree($password);
    }

    private function renderRotationTree(string $password): int
    {
        $outcome = $this->runStepOperation(
            'Rotating VPN web UI password',
            [
                ['label' => 'Resolve VPN runtime'],
                ['label' => 'Authenticate VPN backend'],
                ['label' => 'Update backend password'],
                ['label' => 'Rotate admin sessions'],
                ['label' => 'Apply and verify credential rotation'],
            ],
            work: fn (): array => $this->rotatePasswordForHuman($password),
            doneFooter: 'VPN web UI password rotated',
        );

        return $outcome->isCompleted() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function rotatePassword(string $password): array
    {
        return $this->gatewayPost('/api/vpn/web-ui/password', [
            'password' => $password,
            'force' => true,
            ...$this->totpPayload(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rotatePasswordForHuman(string $password): array
    {
        try {
            return $this->rotatePassword($password);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }
}
