<?php

declare(strict_types=1);

namespace Orbit\Core\Progress;

final readonly class ProgressEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public ProgressEventType $type,
        public array $payload = [],
    ) {}

    public function exitCode(?int $default = null): ?int
    {
        $value = $this->payload['exit_code'] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return $default;
    }

    public function isSuccessfulTerminal(): bool
    {
        if ($this->type !== ProgressEventType::Complete) {
            return false;
        }

        if (! array_key_exists('exit_code', $this->payload)) {
            return true;
        }

        return $this->exitCode() === 0;
    }

    public function terminalExitCode(): int
    {
        $exitCode = $this->exitCode();

        if ($this->type === ProgressEventType::Complete) {
            return $this->isSuccessfulTerminal() ? 0 : $exitCode ?? 1;
        }

        if ($this->type === ProgressEventType::Error) {
            return $exitCode === null || $exitCode === 0 ? 1 : $exitCode;
        }

        return 1;
    }
}
