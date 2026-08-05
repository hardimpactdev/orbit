<?php

declare(strict_types=1);

namespace App\Commands\Process;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-properties
 */
final readonly class ProcessUpdateInput
{
    public ?string $node;

    public ?string $instance;

    public ?string $workspace;

    public ?string $name;

    public ?string $newName;

    public ?string $label;

    public ?string $command;

    public ?string $restartPolicy;

    public ?string $crashNotification;

    public ?string $runtime;

    /** @var list<string> */
    public array $binds;

    public bool $restart;

    /**
     * @param  array{
     *     node?: ?string,
     *     instance?: ?string,
     *     workspace?: ?string,
     *     name?: ?string,
     *     new_name?: ?string,
     *     label?: ?string,
     *     command?: ?string,
     *     restart_policy?: ?string,
     *     crash_notification?: ?string,
     *     runtime?: ?string,
     *     binds?: list<string>,
     *     restart?: bool,
     * }  $values
     */
    private function __construct(array $values)
    {
        $this->node = $this->stringValue($values, 'node');
        $this->instance = $this->stringValue($values, 'instance');
        $this->workspace = $this->stringValue($values, 'workspace');
        $this->name = $this->stringValue($values, 'name');
        $this->newName = $this->stringValue($values, 'new_name');
        $this->label = $this->stringValue($values, 'label');
        $this->command = $this->stringValue($values, 'command');
        $this->restartPolicy = $this->stringValue($values, 'restart_policy');
        $this->crashNotification = $this->stringValue($values, 'crash_notification');
        $this->runtime = $this->stringValue($values, 'runtime');
        $this->binds = $this->bindValues($values);
        $this->restart = ($values['restart'] ?? false) === true;
    }

    /**
     * @param  array{
     *     node?: ?string,
     *     instance?: ?string,
     *     workspace?: ?string,
     *     name?: ?string,
     *     new_name?: ?string,
     *     label?: ?string,
     *     command?: ?string,
     *     restart_policy?: ?string,
     *     crash_notification?: ?string,
     *     runtime?: ?string,
     *     binds?: list<string>,
     *     restart?: bool,
     * }  $values
     */
    public static function fromValues(array $values): self
    {
        return new self($values);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        foreach ($this->stringPayloadFields() as $field => $value) {
            if (! ($value !== null && $value !== '')) {
                continue;
            }

            $payload[$field] = $value;
        }

        if ($this->binds !== []) {
            $payload['binds'] = ProcessBindOption::normalize($this->binds);
        }

        $payload['restart'] = $this->restart;

        return $payload;
    }

    public function hasBinds(): bool
    {
        return $this->binds !== [];
    }

    /**
     * @return array<string, ?string>
     */
    private function stringPayloadFields(): array
    {
        return [
            'node' => $this->node,
            'instance' => $this->instance,
            'workspace' => $this->workspace,
            'name' => $this->newName,
            'label' => $this->label,
            'command' => $this->command,
            'restart_policy' => $this->restartPolicy,
            'crash_notification' => $this->crashNotification,
            'runtime' => $this->runtime,
        ];
    }

    /**
     * @param  array{
     *     node?: ?string,
     *     instance?: ?string,
     *     workspace?: ?string,
     *     name?: ?string,
     *     new_name?: ?string,
     *     label?: ?string,
     *     command?: ?string,
     *     restart_policy?: ?string,
     *     crash_notification?: ?string,
     *     runtime?: ?string,
     *     binds?: list<string>,
     *     restart?: bool,
     * }  $values
     */
    private function stringValue(array $values, string $key): ?string
    {
        return match ($key) {
            'node' => $values['node'] ?? null,
            'instance' => $values['instance'] ?? null,
            'workspace' => $values['workspace'] ?? null,
            'name' => $values['name'] ?? null,
            'new_name' => $values['new_name'] ?? null,
            'label' => $values['label'] ?? null,
            'command' => $values['command'] ?? null,
            'restart_policy' => $values['restart_policy'] ?? null,
            'crash_notification' => $values['crash_notification'] ?? null,
            'runtime' => $values['runtime'] ?? null,
            default => null,
        };
    }

    /**
     * @param  array{
     *     node?: ?string,
     *     instance?: ?string,
     *     workspace?: ?string,
     *     name?: ?string,
     *     new_name?: ?string,
     *     label?: ?string,
     *     command?: ?string,
     *     restart_policy?: ?string,
     *     crash_notification?: ?string,
     *     runtime?: ?string,
     *     binds?: list<string>,
     *     restart?: bool,
     * }  $values
     * @return list<string>
     */
    private function bindValues(array $values): array
    {
        return ProcessBindOption::fromOption($values['binds'] ?? []);
    }
}
