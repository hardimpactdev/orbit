<?php

declare(strict_types=1);

namespace App\Services\Processes;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:excessive-parameter-list
 */
final readonly class LocalDockerSwarmServiceSpec
{
    public const string SPEC_HASH_LABEL = 'orbit.process.spec_hash';

    /**
     * @param  array<string, string>  $labels
     * @param  list<string>  $ports
     * @param  list<string>  $mounts
     * @param  array<string, string>  $environment
     */
    private function __construct(
        public string $name,
        public string $image,
        public string $command,
        public string $commandMode,
        public string $restartCondition,
        public array $labels,
        public array $ports,
        public array $mounts,
        public array $environment,
        public string $updateOrder,
        public string $updateParallelism,
        public string $expectedHash,
    ) {}

    public static function from(mixed $value): self
    {
        if (! is_array($value)) {
            throw self::validationFailure('spec');
        }

        return new self(
            name: self::serviceName($value['name'] ?? null),
            image: self::nonEmptyString($value, 'image'),
            command: self::nonEmptyString($value, 'command'),
            commandMode: self::commandMode($value['command_mode'] ?? null),
            restartCondition: self::restartCondition($value['restart_condition'] ?? null),
            labels: self::stringMap($value['labels'] ?? null),
            ports: self::stringList($value['ports'] ?? null, 'ports'),
            mounts: self::stringList($value['mounts'] ?? null, 'mounts'),
            environment: self::stringMap($value['environment'] ?? null),
            updateOrder: self::updateOrder($value['update_order'] ?? null),
            updateParallelism: self::positiveIntegerString($value['update_parallelism'] ?? null, 'update_parallelism'),
            expectedHash: self::hash($value['expected_hash'] ?? null),
        );
    }

    /**
     * @return list<string>
     */
    public function createCommand(): array
    {
        $command = [
            'docker',
            'service',
            'create',
            '--name',
            $this->name,
            '--replicas',
            '0',
            '--restart-condition',
            $this->restartCondition,
        ];

        foreach ($this->labelsWithHash() as $key => $value) {
            $command[] = '--label';
            $command[] = "{$key}={$value}";
        }

        foreach ($this->ports as $port) {
            $command[] = '--publish';
            $command[] = $port;
        }

        foreach ($this->mounts as $mount) {
            $command[] = '--mount';
            $command[] = $mount;
        }

        foreach ($this->environment as $key => $value) {
            $command[] = '--env';
            $command[] = "{$key}={$value}";
        }

        $command[] = '--update-order';
        $command[] = $this->updateOrder;
        $command[] = '--update-parallelism';
        $command[] = $this->updateParallelism;

        if ($this->usesShellEntrypoint()) {
            $command[] = '--entrypoint';
            $command[] = 'sh';
        }

        $command[] = $this->image;

        if ($this->usesShellEntrypoint()) {
            $command[] = '-lc';
            $command[] = $this->command;
        }

        return $command;
    }

    private function usesShellEntrypoint(): bool
    {
        return $this->commandMode === 'shell';
    }

    /**
     * @return array<string, string>
     */
    private function labelsWithHash(): array
    {
        $labels = [
            ...$this->labels,
            self::SPEC_HASH_LABEL => $this->expectedHash,
        ];
        ksort($labels);

        return $labels;
    }

    private static function serviceName(mixed $value): string
    {
        if (is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $value) === 1) {
            return $value;
        }

        throw self::validationFailure('name');
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private static function nonEmptyString(array $payload, string $key): string
    {
        if (array_key_exists($key, $payload) && is_string($payload[$key]) && trim($payload[$key]) !== '') {
            return trim($payload[$key]);
        }

        throw self::validationFailure($key);
    }

    private static function commandMode(mixed $value): string
    {
        if ($value === 'shell' || $value === 'image_entrypoint') {
            return $value;
        }

        throw self::validationFailure('command_mode');
    }

    private static function restartCondition(mixed $value): string
    {
        if (is_string($value) && in_array($value, ['any', 'none', 'on-failure'], strict: true)) {
            return $value;
        }

        throw self::validationFailure('restart_condition');
    }

    private static function updateOrder(mixed $value): string
    {
        if (is_string($value) && in_array($value, ['start-first', 'stop-first'], strict: true)) {
            return $value;
        }

        throw self::validationFailure('update_order');
    }

    private static function positiveIntegerString(mixed $value, string $field): string
    {
        if (is_int($value) && $value > 0) {
            return (string) $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return $value;
        }

        throw self::validationFailure($field);
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach (array_keys($value) as $key) {
            if (! is_string($key) || ! is_scalar($value[$key])) {
                continue;
            }

            $map[$key] = (string) $value[$key];
        }

        ksort($map);

        return $map;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        $values = array_values($value);

        for ($index = 0; $index < count($values); $index++) {
            if (! is_string($values[$index]) || trim($values[$index]) === '') {
                throw self::validationFailure($field);
            }

            $items[] = trim($values[$index]);
        }

        return $items;
    }

    private static function hash(mixed $value): string
    {
        if ($value === '') {
            return '';
        }

        if (is_string($value) && preg_match('/^[a-f0-9]{6,128}$/', $value) === 1) {
            return $value;
        }

        throw self::validationFailure('expected_hash');
    }

    private static function validationFailure(string $field): LocalDockerSwarmServiceFailure
    {
        return new LocalDockerSwarmServiceFailure(
            errorCode: 'validation_failed',
            message: 'Docker Swarm service spec is invalid.',
            meta: ['field' => $field],
        );
    }
}
