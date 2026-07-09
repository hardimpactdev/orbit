<?php

declare(strict_types=1);

namespace App\Services\Schedules;

final readonly class LocalScheduleRunPayload
{
    /**
     * @param  array<string, string>  $environment
     */
    private function __construct(
        public string $executionType,
        public string $executionValue,
        public ?string $cwd,
        public int $timeout,
        public array $environment,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            executionType: LocalScheduleRunExecutionType::from($payload['execution_type'] ?? null),
            executionValue: LocalScheduleRunExecutionValue::from($payload['execution_value'] ?? null),
            cwd: LocalScheduleRunCwd::from($payload['cwd'] ?? null),
            timeout: LocalScheduleRunTimeout::from($payload['timeout'] ?? null),
            environment: LocalScheduleRunEnvironment::from($payload['environment'] ?? []),
        );
    }

    public function shellCommand(): string
    {
        if ($this->executionType === 'script') {
            return escapeshellarg($this->executionValue);
        }

        return $this->executionValue;
    }
}
