<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Services\Workspaces\LocalWorkspaceSetupStepCwd;
use App\Services\Workspaces\LocalWorkspaceSetupStepEnvironment;
use App\Services\Workspaces\LocalWorkspaceSetupStepTimeout;
use InvalidArgumentException;

final readonly class LocalDeployRunStepPayload
{
    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    private function __construct(
        public string $binary,
        public array $arguments,
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
            binary: self::binary($payload['binary'] ?? null),
            arguments: self::arguments($payload['arguments'] ?? null),
            cwd: LocalWorkspaceSetupStepCwd::from($payload['cwd'] ?? null),
            timeout: LocalWorkspaceSetupStepTimeout::from($payload['timeout'] ?? null),
            environment: LocalWorkspaceSetupStepEnvironment::from($payload['environment'] ?? []),
        );
    }

    private static function binary(mixed $value): string
    {
        if (
            ! is_string($value)
            || ! str_starts_with($value, '/')
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $value) === 1
        ) {
            throw new InvalidArgumentException('Deploy executable is invalid.');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function arguments(mixed $value): array
    {
        if (
            ! is_array($value)
            || ! array_is_list($value)
            || ! array_all($value, static fn (mixed $argument): bool => is_string($argument)
            && ! str_contains($argument, "\0"))
        ) {
            throw new InvalidArgumentException('Deploy arguments are invalid.');
        }

        /** @var list<string> $value */
        return $value;
    }
}
