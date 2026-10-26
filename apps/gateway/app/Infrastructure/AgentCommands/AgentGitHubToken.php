<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

/** Marks a GitHub installation token so the transport forbids sending it to a task VM. */
final readonly class AgentGitHubToken
{
    public function __construct(#[\SensitiveParameter] public string $value) {}

    /** @return array{type: class-string} */
    public function __debugInfo(): array
    {
        return ['type' => self::class];
    }
}
