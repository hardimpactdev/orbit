<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

/** One command of the agent contract. */
final readonly class AgentCommandSpec
{
    /** @param  list<string>  $errors */
    public function __construct(
        public string $name,
        public bool $mutates,
        public int $timeoutSeconds,
        public ?string $linuxPrivilege,
        public ?string $macosPrivilege,
        public array $errors,
    ) {}

    /** The privilege on a Node platform, or null where the command does not run. */
    public function privilege(string $platform): ?string
    {
        return $platform === 'macos' ? $this->macosPrivilege : $this->linuxPrivilege;
    }

    /** Whether the runner starts the command through `sudo -n` on the platform. */
    public function needsSudo(string $platform): bool
    {
        return $platform === 'linux' && in_array($this->linuxPrivilege, ['root', 'owner'], true);
    }

    public function declares(string $code): bool
    {
        return in_array($code, $this->errors, true);
    }
}
