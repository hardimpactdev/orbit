<?php

declare(strict_types=1);

namespace App\Commands\Process;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\Concerns\WithStepTree;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use RuntimeException;

/** @mago-expect lint:kan-defect */
abstract class ProcessGatewayCommand extends GatewayCommand
{
    use ResolvesHostContext;
    use WithStepTree;

    private const array RESTART_POLICIES = ['never', 'on_failure', 'always'];

    private const array CRASH_NOTIFICATIONS = ['none'];

    private const array RUNTIMES = ['docker', 'docker-swarm', 'systemd', 'launchd'];

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    protected function failValidation(string $field, string $message, array $extraMeta = []): int
    {
        return $this->renderFailure('validation_failed', $message, array_merge(['field' => $field], $extraMeta));
    }

    protected function appContext(): ?string
    {
        return $this->stringOption('instance') ?? $this->instanceFromOrbitMarker();
    }

    protected function nodeContext(): ?string
    {
        return $this->stringOption('node');
    }

    protected function workspaceContext(): ?string
    {
        return $this->stringOption('workspace');
    }

    protected function appHostnameContext(): ?string
    {
        return $this->stringOption('app');
    }

    /**
     * @return int|null Failure exit code when selectors conflict.
     */
    protected function rejectConflictingProcessSelectors(
        ?string $appHostname,
        ?string $node,
        ?string $instance,
        ?string $workspace,
    ): ?int {
        $failure = ProcessTargetSelectorValidation::conflict(
            $appHostname,
            $node,
            $instance,
            $workspace,
        );

        if ($failure === null) {
            return null;
        }

        return $this->failValidation($failure['field'], $failure['message'], $failure['meta']);
    }

    /**
     * @return int|null Failure exit code when no process target is present.
     */
    protected function requireProcessSelector(
        ?string $appHostname,
        ?string $node,
        ?string $instance,
        ?string $workspace,
    ): ?int {
        $failure = ProcessTargetSelectorValidation::missing(
            $appHostname,
            $node,
            $instance,
            $workspace,
        );

        if ($failure === null) {
            return null;
        }

        return $this->failValidation($failure['field'], $failure['message'], $failure['meta']);
    }

    protected function validateProcessName(?string $name): ?int
    {
        if ($name === null) {
            return $this->failValidation('name', 'The process name is required.');
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $name)) {
            return $this->failValidation(
                'name',
                'The process name must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.',
                [
                    'value' => $name,
                ],
            );
        }

        return null;
    }

    /**
     * Resolve optional --label.
     *
     * Omitted → null (default on add / no-change on update).
     * Explicit empty (`--label=` or whitespace) → validation_failed field=label.
     * Valid non-empty → trimmed string.
     *
     * Do not use stringOption() here: it collapses empty strings to null and
     * hides explicit empty labels.
     *
     * @return string|null|int Null when omitted, trimmed label when valid, int exit code when invalid.
     */
    protected function resolveProcessLabelOption(): string|int|null
    {
        $value = $this->option('label');

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return $this->failValidation(
                'label',
                'The process label must be a non-empty string.',
            );
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return $this->failValidation(
                'label',
                'The process label must be a non-empty string.',
            );
        }

        if (mb_strlen($trimmed) > 255) {
            return $this->failValidation(
                'label',
                'The process label may not be greater than 255 characters.',
                ['max' => 255],
            );
        }

        return $trimmed;
    }

    protected function validateRestartPolicy(?string $value): ?int
    {
        if ($value === null || in_array($value, self::RESTART_POLICIES, true)) {
            return null;
        }

        return $this->failValidation('restart_policy', 'Invalid restart policy.', [
            'value' => $value,
            'allowed' => self::RESTART_POLICIES,
        ]);
    }

    protected function validateCrashNotification(?string $value): ?int
    {
        if ($value === null || in_array($value, self::CRASH_NOTIFICATIONS, true)) {
            return null;
        }

        return $this->failValidation('crash_notification', 'Invalid crash notification policy.', [
            'value' => $value,
            'allowed' => self::CRASH_NOTIFICATIONS,
        ]);
    }

    protected function validateRuntime(?string $value): ?int
    {
        if ($value === null || in_array($value, self::RUNTIMES, true)) {
            return null;
        }

        return $this->failValidation('runtime', 'Invalid process runtime.', [
            'value' => $value,
            'allowed' => self::RUNTIMES,
        ]);
    }

    protected function validateAppWorkspaceCommandRuntime(
        ?string $runtime,
        ?string $node,
        ?string $service = null,
    ): ?int {
        if ($runtime === null || $node !== null || $service !== null || $runtime === 'systemd') {
            return null;
        }

        return match ($runtime) {
            'docker' => $this->failValidation(
                'runtime',
                'The docker runtime is only valid for managed services or Orbit-managed runtime processes.',
                [
                    'value' => $runtime,
                    'reason' => 'docker_runtime_requires_service_or_managed_process',
                ],
            ),
            'docker-swarm' => $this->failValidation(
                'runtime',
                'The docker-swarm runtime is only valid for node-owned processes.',
                [
                    'value' => $runtime,
                    'reason' => 'docker_swarm_requires_node_owned_process',
                ],
            ),
            default => null,
        };
    }

    /**
     * Resolve the operator-facing owner label for progress-tree footers,
     * mirroring the gateway's resolution order: workspace, then instance, then node.
     */
    protected function contextLabel(?string $node, ?string $app, ?string $workspace): string
    {
        if ($workspace !== null) {
            return "workspace '{$workspace}'";
        }

        if ($app !== null) {
            return "instance '{$app}'";
        }

        return "node '{$node}'";
    }

    /**
     * Run a gateway mutation inside the progress tree, re-throwing gateway
     * failures with their operator-facing message so the failed footer renders
     * the documented prose rather than a JSON envelope.
     *
     * @param  callable(): array<string, mixed>  $call
     * @return array<string, mixed>
     */
    protected function gatewayCallForHuman(callable $call): array
    {
        try {
            return $call();
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    protected function successData(array $response): array
    {
        $data = $response['success']['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    protected function successMeta(array $response): array
    {
        $meta = $response['success']['meta'] ?? null;

        return is_array($meta) ? $meta : [];
    }

    /**
     * Repairable runtime-unit drift surfaced by the gateway as `meta.warnings`.
     *
     * @param  array<string, mixed>  $response
     * @return list<string>
     */
    protected function driftMessages(array $response): array
    {
        $warnings = $this->successMeta($response)['warnings'] ?? null;

        if (! is_array($warnings)) {
            return [];
        }

        $messages = [];

        foreach ($warnings as $warning) {
            if (is_string($warning) && trim($warning) !== '') {
                $messages[] = trim($warning);
            } elseif (
                is_array($warning)
                && is_string($warning['message'] ?? null)
                && trim($warning['message']) !== ''
            ) {
                $messages[] = trim($warning['message']);
            }
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function renderDriftNotes(array $response): void
    {
        foreach ($this->driftMessages($response) as $message) {
            $this->line("  Drift detected: {$message}");
        }
    }
}
