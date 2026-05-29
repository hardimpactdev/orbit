<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

final readonly class DockerHost
{
    public function __construct(
        public E2EConfig $config,
        public string $host = 'local',
    ) {}

    public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        $process = Process::timeout($timeoutSeconds ?? $this->config->timeoutSeconds);

        if ($this->environment() !== []) {
            $process = $process->env($this->environment());
        }

        return $process->run($command);
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        if ($this->host === 'local') {
            return [];
        }

        return ['DOCKER_HOST' => "ssh://{$this->host}"];
    }

    public function mustRun(string $command, string $errorContext, ?int $timeoutSeconds = null): ProcessResult
    {
        $result = $this->run($command, $timeoutSeconds);

        if (! $result->successful()) {
            throw new \RuntimeException("{$errorContext}: ".trim($result->errorOutput().' '.$result->output()));
        }

        return $result;
    }
}
