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
        public int $outputMaxBytes = 0,
        public int $inputMaxBytes = 0,
    ) {}

    /** The privilege on a Node platform, or null where the command does not run. */
    public function privilege(string $platform): ?string
    {
        return $platform === 'macos' ? $this->macosPrivilege : $this->linuxPrivilege;
    }

    /**
     * Whether the runner starts the command through `sudo -n` on the platform. On Linux a mutation always does, because
     * only root starts its service; the service then runs as the identity the privilege declares.
     */
    public function needsSudo(string $platform): bool
    {
        return $platform === 'linux' && ($this->mutates || in_array($this->linuxPrivilege, ['root', 'owner'], true));
    }

    public function declares(string $code): bool
    {
        return in_array($code, $this->errors, true);
    }
}
