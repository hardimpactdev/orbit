<?php

declare(strict_types=1);

namespace App\Data\RuntimeBackend;

final readonly class SupervisorProgramDefinition
{
    /**
     * @param  array<string, string>  $environment
     */
    public function __construct(
        public string $name,
        public string $directory,
        public string $command,
        public string $user,
        public string $restartPolicy,
        public string $stdoutLogFile,
        public array $environment = [],
        public bool $autostart = false,
        public int $startSeconds = 0,
        public bool $redirectStderr = true,
        public string $stdoutLogFileMaxBytes = '20MB',
        public int $stdoutLogFileBackups = 5,
    ) {}
}
