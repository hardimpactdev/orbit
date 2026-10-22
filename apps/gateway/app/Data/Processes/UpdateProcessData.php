<?php

declare(strict_types=1);

namespace App\Data\Processes;

use App\Domain\Processes\ProcessTargetType;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Process;
use App\Support\ValidatedData;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class UpdateProcessData
{
    /** @param list<string> $providedFields */
    public function __construct(
        #[SensitiveParameter] public AddProcessData $specification,
        public array $providedFields,
    ) {}

    public function forProcess(#[SensitiveParameter] Process $process): AddProcessData
    {
        $data = $this->specification;
        $config = $process->runtime_config;

        return new AddProcessData(
            targetType: ProcessTargetType::fromModelClass($process->owner_type),
            targetId: $process->owner_id,
            name: $process->name,
            runtime: $this->has('runtime') ? $data->runtime : $process->runtime,
            command: $this->has('command') ? $data->command : ValidatedData::stringList($config['command']),
            image: $this->has('image') ? $data->image : ValidatedData::nullableString($config['image'] ?? null),
            workingDirectory: $this->has('working_directory') ? $data->workingDirectory : $process->working_directory,
            environment: $this->has('environment') ? $data->environment : ValidatedData::stringMap($config['environment'] ?? []),
            ports: $this->has('ports') ? $data->ports : ValidatedData::stringList($config['ports'] ?? []),
            volumes: $this->has('volumes') ? $data->volumes : $this->volumes($config['volumes'] ?? []),
            restartPolicy: $this->has('restart_policy') ? $data->restartPolicy : $process->restart_policy,
            start: false,
            keepAlive: $this->has('keep_alive') ? $data->keepAlive : $process->keep_alive,
            user: $this->has('user') ? $data->user : ValidatedData::nullableString($config['user'] ?? null),
            restartOnDeploy: $this->has('restart_on_deploy') ? $data->restartOnDeploy : $process->restart_on_deploy,
        );
    }

    /** @param array<string, mixed> $runtimeConfig */
    public function assertPreservesOmittedFields(#[SensitiveParameter] Process $process, #[SensitiveParameter] array $runtimeConfig): void
    {
        foreach (['image', 'command', 'environment', 'ports', 'volumes', 'user'] as $field) {
            $stored = $process->runtime_config[$field] ?? null;
            if ($this->has($field) || $stored === null || $stored === []
                || ($runtimeConfig[$field] ?? null) === $stored) {
                continue;
            }

            throw new ResourceOperationException(
                'process.option_invalid',
                'The runtime change would discard an omitted Process field. Send a compatible value explicitly.',
                details: ['field' => $field],
            );
        }
    }

    /** @return list<array{source: string, target: string, read_only: bool}> */
    private function volumes(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Stored Process volumes must be a list.');
        }
        $volumes = [];
        foreach ($value as $volume) {
            if (! is_array($volume) || ! is_string($volume['source'] ?? null)
                || ! is_string($volume['target'] ?? null) || ! is_bool($volume['read_only'] ?? null)) {
                throw new InvalidArgumentException('Stored Process volume is invalid.');
            }
            $volumes[] = ['source' => $volume['source'], 'target' => $volume['target'], 'read_only' => $volume['read_only']];
        }

        return $volumes;
    }

    private function has(string $field): bool
    {
        return in_array($field, $this->providedFields, true);
    }
}
