<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\Vpn\VpnPasswordRotationResult;
use App\Exceptions\PromptAborted;
use App\Services\Vpn\VpnFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Laravel\Prompts\Prompt;

use function Laravel\Prompts\password;

#[Signature('vpn-web-ui:change-password
    {password? : New gateway VPN backend web UI password}
    {--force : Confirm credential rotation without prompting}
    {--totp= : One-time code for the gateway VPN backend}
    {--json : Output as JSON}')]
#[Description('Change the gateway VPN web UI password')]
final class VpnWebUiChangePasswordCommand extends VpnCommandSupport
{
    public function handle(): int
    {
        $this->bootActivityLog();

        try {
            return $this->executeVpnWebUiChangePassword();
        } finally {
            $this->finishActivityLog();
        }
    }

    private function executeVpnWebUiChangePassword(): int
    {
        $denied = $this->ensureAllowedCaller();

        if ($denied !== null) {
            return $denied;
        }

        $newPassword = $this->stringArgument('password');

        if ($newPassword === null) {
            if (! $this->isInteractiveInput()) {
                return $this->failCommand('validation_failed', 'VPN web UI password is required.', ['field' => 'password']);
            }

            try {
                $newPassword = $this->withPasswordPrompt();
            } catch (PromptAborted) {
                return $this->promptAborted();
            }
        }

        if (mb_strlen($newPassword) < 12) {
            return $this->failCommand('validation_failed', 'Password must be at least 12 characters.', ['field' => 'password']);
        }

        if (! $this->option('force')) {
            if (! $this->isInteractiveInput()) {
                return $this->failCommand('validation_failed', 'Use --force to rotate the VPN web UI password.', [
                    'field' => 'force',
                    'reason' => 'destructive_consent_required',
                ]);
            }

            $confirmed = $this->confirmOrFail('Rotate the VPN web UI password?');

            if (is_int($confirmed)) {
                return $confirmed;
            }
        }

        if ($this->callerRole() === 'control') {
            $this->activityActionResult = 'rotated';
            $this->activityForwardedToGateway = true;
            $payload = $this->forwardToGateway('vpn-web-ui:change-password', [$newPassword], [
                'force' => true,
                'totp' => $this->stringOption('totp'),
            ]);

            return $payload instanceof VpnFailure ? $this->failFrom($payload) : $this->respondGatewayPayload($payload);
        }

        $result = $this->manager()->changeWebUiPassword($newPassword, $this->stringOption('totp'));

        if ($result instanceof VpnFailure) {
            return $this->failFrom($result);
        }

        return $this->respond($result);
    }

    /**
     * @throws PromptAborted
     */
    private function withPasswordPrompt(): string
    {
        Prompt::cancelUsing(fn () => throw new PromptAborted);

        try {
            return password('New VPN web UI password', required: true);
        } finally {
            Prompt::cancelUsing(null);
        }
    }

    private function respond(VpnPasswordRotationResult $result): int
    {
        $this->activityActionResult = 'rotated';

        $data = [
            'vpn' => [
                'password_changed' => $result->passwordChanged,
                'sessions_invalidated' => $result->sessionsInvalidated,
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
        $this->line('VPN web UI password rotated');

        return self::SUCCESS;
    }
}
