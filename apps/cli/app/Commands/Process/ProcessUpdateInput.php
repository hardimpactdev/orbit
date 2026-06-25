<?php

declare(strict_types=1);

namespace App\Commands\Process;

final readonly class ProcessUpdateInput
{
    public ?string $node;

    public ?string $app;

    public ?string $workspace;

    public ?string $name;

    public ?string $newName;

    public ?string $command;

    public ?string $restartPolicy;

    public ?string $crashNotification;

    public ?string $runtime;

    public bool $restart;

    /**
     * @param  array{
     *     node?: ?string,
     *     app?: ?string,
     *     workspace?: ?string,
     *     name?: ?string,
     *     new_name?: ?string,
     *     command?: ?string,
     *     restart_policy?: ?string,
     *     crash_notification?: ?string,
     *     runtime?: ?string,
     *     restart?: bool,
     * }  $values
     */
    private function __construct(array $values)
    {
        $this->node = $this->stringValue($values, 'node');
        $this->app = $this->stringValue($values, 'app');
        $this->workspace = $this->stringValue($values, 'workspace');
        $this->name = $this->stringValue($values, 'name');
        $this->newName = $this->stringValue($values, 'new_name');
        $this->command = $this->stringValue($values, 'command');
        $this->restartPolicy = $this->stringValue($values, 'restart_policy');
        $this->crashNotification = $this->stringValue($values, 'crash_notification');
        $this->runtime = $this->stringValue($values, 'runtime');
        $this->restart = ($values['restart'] ?? false) === true;
    }

    /**
     * @param  array{
     *     node?: ?string,
     *     app?: ?string,
     *     workspace?: ?string,
     *     name?: ?string,
     *     new_name?: ?string,
     *     command?: ?string,
     *     restart_policy?: ?string,
     *     crash_notification?: ?string,
     *     runtime?: ?string,
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
            if ($value !== null && $value !== '') {
                $payload[$field] = $value;
            }
        }

        $payload['restart'] = $this->restart;

        return $payload;
    }

    /**
     * @return array<string, ?string>
     */
    private function stringPayloadFields(): array
    {
        return [
            'node' => $this->node,
            'app' => $this->app,
            'workspace' => $this->workspace,
            'name' => $this->newName,
            'command' => $this->command,
            'restart_policy' => $this->restartPolicy,
            'crash_notification' => $this->crashNotification,
            'runtime' => $this->runtime,
        ];
    }

    /**
     * @param  array{
     *     node?: ?string,
     *     app?: ?string,
     *     workspace?: ?string,
     *     name?: ?string,
     *     new_name?: ?string,
     *     command?: ?string,
     *     restart_policy?: ?string,
     *     crash_notification?: ?string,
     *     runtime?: ?string,
     *     restart?: bool,
     * }  $values
     */
    private function stringValue(array $values, string $key): ?string
    {
        return match ($key) {
            'node' => $values['node'] ?? null,
            'app' => $values['app'] ?? null,
            'workspace' => $values['workspace'] ?? null,
            'name' => $values['name'] ?? null,
            'new_name' => $values['new_name'] ?? null,
            'command' => $values['command'] ?? null,
            'restart_policy' => $values['restart_policy'] ?? null,
            'crash_notification' => $values['crash_notification'] ?? null,
            'runtime' => $values['runtime'] ?? null,
            default => null,
        };
    }
}
