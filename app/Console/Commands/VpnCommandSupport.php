<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\HandlesPromptCancellation;
use App\Concerns\LogsCommandActivity;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Contracts\Loggable;
use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\ActivityLogType;
use App\Exceptions\PromptAborted;
use App\Models\Node;
use App\Services\Vpn\FileVpnBackend;
use App\Services\Vpn\VpnBackend;
use App\Services\Vpn\VpnClientManager;
use App\Services\Vpn\VpnFailure;
use App\Services\Vpn\WgEasyVpnBackend;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Throwable;

abstract class VpnCommandSupport extends Command implements Loggable
{
    use HandlesPromptCancellation;
    use LogsCommandActivity;
    use WithSpinner;
    use WithStepTree;

    protected ?string $activityClientName = null;

    protected ?string $activityActionResult = null;

    protected ?int $activityCount = null;

    protected bool $activityForwardedToGateway = false;

    protected function manager(): VpnClientManager
    {
        $fakeBackendPath = config('services.wg_easy.fake_backend_path');

        if (is_string($fakeBackendPath) && $fakeBackendPath !== '') {
            return new VpnClientManager(new FileVpnBackend($fakeBackendPath));
        }

        $backend = app()->bound(VpnBackend::class)
            ? app(VpnBackend::class)
            : WgEasyVpnBackend::fromConfig();

        return new VpnClientManager($backend);
    }

    protected function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function callerRole(): string
    {
        $localRole = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->value('role');

        if (! is_string($localRole) || $localRole === '') {
            return 'control';
        }

        if (! in_array($localRole, ['gateway', 'app', 'control'], true)) {
            return 'unknown';
        }

        return $localRole;
    }

    protected function ensureAllowedCaller(): ?int
    {
        $role = $this->callerRole();

        if (in_array($role, ['gateway', 'control'], true)) {
            return null;
        }

        return $this->failCommand(
            code: 'caller_role_not_allowed',
            message: 'This command may only be run from a control or gateway node.',
            meta: ['caller_role' => $role],
        );
    }

    protected function forwardToGateway(string $command, array $arguments = [], array $options = []): array|VpnFailure
    {
        $gateway = Node::query()
            ->where('role', 'gateway')
            ->where('status', 'active')
            ->orderByDesc('id')
            ->first();

        if (! $gateway instanceof Node) {
            return new VpnFailure(
                code: 'gateway_ssh_unavailable',
                message: 'Gateway SSH execution is required for VPN administration.',
            );
        }

        $script = $this->gatewayScript($command, $arguments, $options);

        try {
            $result = app(RemoteShell::class)->run($gateway, $script, [
                'cwd' => $gateway->orbit_path ?: '/home/orbit/orbit',
                'timeout' => 120,
            ]);
        } catch (Throwable) {
            return new VpnFailure(
                code: 'gateway_ssh_unavailable',
                message: 'Gateway SSH execution is required for VPN administration.',
            );
        }

        if (! $result->successful()) {
            $details = trim($result->errorOutput()) !== '' ? trim($result->errorOutput()) : trim($result->output());

            return new VpnFailure(
                code: 'gateway_ssh_unavailable',
                message: $details !== '' ? $details : 'Gateway SSH execution failed.',
            );
        }

        return $this->decodeGatewayJson($result);
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<string, mixed>  $options
     */
    protected function gatewayScript(string $command, array $arguments, array $options): string
    {
        $parts = ['php', 'artisan', $command];

        foreach ($arguments as $argument) {
            $parts[] = escapeshellarg((string) $argument);
        }

        foreach ($options as $name => $value) {
            if ($value === false || $value === null) {
                continue;
            }

            $parts[] = $value === true
                ? "--{$name}"
                : "--{$name}=".escapeshellarg((string) $value);
        }

        $parts[] = '--json';

        return implode(' ', $parts);
    }

    /**
     * @return array<string, mixed>|VpnFailure
     */
    private function decodeGatewayJson(RemoteShellResult $result): array|VpnFailure
    {
        try {
            $payload = json_decode(trim($result->stdout), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return new VpnFailure(
                code: 'gateway_ssh_unavailable',
                message: 'Gateway VPN command did not return JSON.',
            );
        }

        if (! is_array($payload)) {
            return new VpnFailure('gateway_ssh_unavailable', 'Gateway VPN command did not return JSON.');
        }

        if (isset($payload['error']) && is_array($payload['error'])) {
            return new VpnFailure(
                code: is_string($payload['error']['code'] ?? null) ? $payload['error']['code'] : 'gateway_ssh_unavailable',
                message: is_string($payload['error']['message'] ?? null) ? $payload['error']['message'] : 'Gateway VPN command failed.',
                meta: is_array($payload['error']['meta'] ?? null) ? $payload['error']['meta'] : [],
            );
        }

        return $payload;
    }

    protected function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    protected function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    protected function jsonSuccess(array $data, array $meta = []): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    protected function respondGatewayPayload(array $payload): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $data = is_array($payload['success']['data'] ?? null) ? $payload['success']['data'] : [];
        $meta = is_array($payload['success']['meta'] ?? null) ? $payload['success']['meta'] : [];

        return $this->renderData($data, $meta);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    protected function renderData(array $data, array $meta): int
    {
        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function failCommand(string $code, string $message, array $meta = []): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => empty($meta) ? (object) [] : $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    protected function failFrom(VpnFailure $failure): int
    {
        return $this->failCommand($failure->code, $failure->message, $failure->meta);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function subject(): ?Model
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return array_filter([
            'client' => $this->activityClientName,
            'result' => $this->activityActionResult,
            'count' => $this->activityCount,
            'forwarded_to_gateway' => $this->activityForwardedToGateway ? true : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function description(): ?string
    {
        return null;
    }

    protected function finishActivityLog(): void
    {
        try {
            $this->finalizeActivityLog();
        } catch (Throwable) {
            // Activity logging must not change the documented VPN command result.
        }
    }

    protected function promptAborted(): int
    {
        return $this->failCommand('validation_failed', 'Operation cancelled.');
    }

    protected function promptForText(string $label): string|int
    {
        try {
            return $this->promptText($label, required: true);
        } catch (PromptAborted) {
            return $this->promptAborted();
        }
    }

    protected function confirmOrFail(string $label): bool|int
    {
        try {
            $confirmed = $this->promptConfirm($label, default: false);
        } catch (PromptAborted) {
            return $this->promptAborted();
        }

        if (! $confirmed) {
            return $this->failCommand('validation_failed', 'Operation cancelled.');
        }

        return true;
    }
}
