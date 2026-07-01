<?php

declare(strict_types=1);

namespace App\Tools;

use Closure;

final readonly class UserScopedCliProfile
{
    /**
     * @param  Closure(string): (string|array{command: string, arguments: list<string>})  $installCommand
     * @param  (Closure(string): string)|null  $binaryPath
     * @param  (Closure(string): string)|null  $updateCommand
     * @param  (Closure(string): string)|null  $versionCommand
     */
    public function __construct(
        public string $binaryName,
        private Closure $installCommand,
        private ?Closure $binaryPath = null,
        private ?Closure $updateCommand = null,
        private ?Closure $versionCommand = null,
    ) {}

    /**
     * @return array{command: string, arguments: list<string>}
     */
    public function installCommand(string $version): array
    {
        $command = ($this->installCommand)($version);

        if (is_string($command)) {
            return [
                'command' => $command,
                'arguments' => [],
            ];
        }

        return $command;
    }

    public function binaryPath(string $user): string
    {
        if ($this->binaryPath !== null) {
            return ($this->binaryPath)($user);
        }

        return UserScopedCliUsers::homeDirectory($user).'/.local/bin/'.$this->binaryName;
    }

    public function updateCommand(string $user): string
    {
        if ($this->updateCommand !== null) {
            return ($this->updateCommand)($user);
        }

        return $this->installCommand('latest')['command'];
    }

    public function versionCommand(string $binary): string
    {
        if ($this->versionCommand !== null) {
            return ($this->versionCommand)($binary);
        }

        return "{$binary} --version";
    }
}
