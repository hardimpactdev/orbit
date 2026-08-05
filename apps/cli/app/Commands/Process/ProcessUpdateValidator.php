<?php

declare(strict_types=1);

namespace App\Commands\Process;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class ProcessUpdateValidator
{
    public const array RESTART_POLICIES = ['never', 'on_failure', 'always'];

    public const array CRASH_NOTIFICATIONS = ['none'];

    public const array RUNTIMES = ['docker', 'docker-swarm', 'systemd', 'launchd'];

    public function validate(ProcessUpdateInput $input): ?ProcessUpdateValidationFailure
    {
        return (
            new ProcessUpdateContextValidator()->validate(
                $input,
            ) ?? $this->validateProcessName($input->name) ?? $this->validateEditableFields(
                $input,
            ) ?? $this->validateOptionalProcessName($input->newName) ?? $this->validateOptionalLabel(
                $input->label,
            ) ?? new ProcessUpdateAllowedValueValidator()->validate(
                $input,
            ) ?? new ProcessUpdateRuntimeScopeValidator()->validate(
                $input,
            ) ?? new ProcessUpdateBindValidator()->validate(
                $input,
            )
        );
    }

    private function validateProcessName(?string $name): ?ProcessUpdateValidationFailure
    {
        if ($name === null) {
            return new ProcessUpdateValidationFailure('name', 'The process name is required.');
        }

        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $name)) {
            return null;
        }

        return new ProcessUpdateValidationFailure(
            'name',
            'The process name must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.',
            ['value' => $name],
        );
    }

    private function validateEditableFields(ProcessUpdateInput $input): ?ProcessUpdateValidationFailure
    {
        if ($input->newName !== null && $input->newName !== $input->name) {
            return null;
        }

        if (
            $input->label !== null
            || $input->command !== null
            || $input->restartPolicy !== null
            || $input->crashNotification !== null
            || $input->hasBinds()
        ) {
            return null;
        }

        return $input->runtime === null
            ? new ProcessUpdateValidationFailure('editable_fields', 'At least one editable field is required.')
            : null;
    }

    private function validateOptionalProcessName(?string $name): ?ProcessUpdateValidationFailure
    {
        if ($name === null) {
            return null;
        }

        return $this->validateProcessName($name);
    }

    private function validateOptionalLabel(?string $label): ?ProcessUpdateValidationFailure
    {
        if ($label === null) {
            return null;
        }

        $trimmed = trim($label);

        if ($trimmed === '') {
            return new ProcessUpdateValidationFailure(
                'label',
                'The process label must be a non-empty string.',
            );
        }

        if (mb_strlen($trimmed) > 255) {
            return new ProcessUpdateValidationFailure(
                'label',
                'The process label may not be greater than 255 characters.',
                ['max' => 255],
            );
        }

        return null;
    }
}

final readonly class ProcessUpdateContextValidator
{
    public function validate(ProcessUpdateInput $input): ?ProcessUpdateValidationFailure
    {
        if ($input->node !== null && ($input->instance !== null || $input->workspace !== null)) {
            return new ProcessUpdateValidationFailure(
                'context',
                'A node context cannot be combined with instance or workspace context.',
                [
                    'node' => $input->node,
                    'instance' => $input->instance,
                    'workspace' => $input->workspace,
                ],
            );
        }

        if ($input->node !== null || $input->instance !== null || $input->workspace !== null) {
            return null;
        }

        return new ProcessUpdateValidationFailure('instance', 'A node, instance, or workspace context is required.');
    }
}

final readonly class ProcessUpdateAllowedValueValidator
{
    public function validate(ProcessUpdateInput $input): ?ProcessUpdateValidationFailure
    {
        return (
            $this->validateAllowed(
                $input->restartPolicy,
                ProcessUpdateValidator::RESTART_POLICIES,
                'restart_policy',
                'Invalid restart policy.',
            ) ?? $this->validateAllowed(
                $input->crashNotification,
                ProcessUpdateValidator::CRASH_NOTIFICATIONS,
                'crash_notification',
                'Invalid crash notification policy.',
            ) ?? $this->validateAllowed(
                $input->runtime,
                ProcessUpdateValidator::RUNTIMES,
                'runtime',
                'Invalid process runtime.',
            )
        );
    }

    /**
     * @param  list<string>  $allowed
     */
    private function validateAllowed(
        ?string $value,
        array $allowed,
        string $field,
        string $message,
    ): ?ProcessUpdateValidationFailure {
        if ($value === null || in_array($value, $allowed, strict: true)) {
            return null;
        }

        return new ProcessUpdateValidationFailure($field, $message, [
            'value' => $value,
            'allowed' => $allowed,
        ]);
    }
}

final readonly class ProcessUpdateRuntimeScopeValidator
{
    public function validate(ProcessUpdateInput $input): ?ProcessUpdateValidationFailure
    {
        if ($input->runtime === null || $input->node !== null || $input->runtime === 'systemd') {
            return null;
        }

        return match ($input->runtime) {
            'docker' => new ProcessUpdateValidationFailure(
                'runtime',
                'The docker runtime is only valid for managed services or Orbit-managed runtime processes.',
                ['value' => $input->runtime, 'reason' => 'docker_runtime_requires_service_or_managed_process'],
            ),
            'docker-swarm' => new ProcessUpdateValidationFailure(
                'runtime',
                'The docker-swarm runtime is only valid for node-owned processes.',
                ['value' => $input->runtime, 'reason' => 'docker_swarm_requires_node_owned_process'],
            ),
            default => null,
        };
    }
}

final readonly class ProcessUpdateBindValidator
{
    public function validate(ProcessUpdateInput $input): ?ProcessUpdateValidationFailure
    {
        $failure = ProcessBindOption::validate(
            binds: $input->binds,
            node: $input->node,
            service: null,
            runtime: $input->runtime,
            requireService: false,
        );

        if ($failure === null) {
            return null;
        }

        return new ProcessUpdateValidationFailure($failure->field, $failure->message, $failure->meta);
    }
}
