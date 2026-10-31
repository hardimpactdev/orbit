<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class LifecycleStep
{
    public const string ImportCiCaches = '@orbit/ci-caches';

    public const int DefaultTimeoutSeconds = 240;

    /**
     * Public configuration bounds for a Project list. Swamp executes each admitted step
     * with its complete timeout; no Gateway request budget truncates it.
     */
    public const int MaxTimeoutSeconds = 540;

    public const int MaxTotalTimeoutSeconds = self::MaxTimeoutSeconds;

    public function __construct(
        public string $name,
        #[SensitiveParameter]
        public string $command,
        public int $timeoutSeconds = self::DefaultTimeoutSeconds,
    ) {
        if (preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $name) !== 1) {
            throw new InvalidArgumentException('The lifecycle step name is invalid.');
        }

        if ($command === '' || strlen($command) > 16 * 1024 || str_contains($command, "\0")) {
            throw new InvalidArgumentException('The lifecycle step command is invalid.');
        }

        if (preg_match('//u', $command) !== 1) {
            throw new InvalidArgumentException('The lifecycle step command is invalid.');
        }

        if ($timeoutSeconds < 1 || $timeoutSeconds > self::MaxTimeoutSeconds) {
            throw new InvalidArgumentException('The lifecycle step timeout is invalid.');
        }
    }

    /** @return array{name: string, command: string, timeout_seconds: int} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'command' => $this->command,
            'timeout_seconds' => $this->timeoutSeconds,
        ];
    }
}
