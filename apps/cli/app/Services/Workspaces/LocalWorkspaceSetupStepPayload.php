<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

final readonly class LocalWorkspaceSetupStepPayload
{
    /**
     * @param  array<string, string>  $environment
     */
    private function __construct(
        public string $command,
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
            command: LocalWorkspaceSetupStepCommandValue::from($payload['command'] ?? null),
            cwd: LocalWorkspaceSetupStepCwd::from($payload['cwd'] ?? null),
            timeout: LocalWorkspaceSetupStepTimeout::from($payload['timeout'] ?? null),
            environment: LocalWorkspaceSetupStepEnvironment::from($payload['environment'] ?? []),
        );
    }
}
