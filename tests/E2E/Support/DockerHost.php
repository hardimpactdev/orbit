<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

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

        if ($this->host !== 'local') {
            $process = $process->env(['DOCKER_HOST' => "ssh://{$this->host}"]);
        }

        return $process->run($command);
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
