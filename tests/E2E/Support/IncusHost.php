<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

final readonly class IncusHost
{
    public function __construct(
        public E2EConfig $config,
    ) {}

    public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        $remoteCommand = sprintf(
            'bash -lc %s',
            escapeshellarg("set -euo pipefail\n{$command}"),
        );

        return Process::timeout($timeoutSeconds ?? $this->config->timeoutSeconds)
            ->run(sprintf(
                'ssh -o BatchMode=yes -o IdentitiesOnly=yes -o ConnectTimeout=10 %s %s',
                escapeshellarg($this->config->host),
                escapeshellarg($remoteCommand),
            ));
    }

    public function commandExists(string $command): bool
    {
        return $this->run(sprintf('command -v %s >/dev/null', escapeshellarg($command)))->successful();
    }

    public function imageExists(string $alias): bool
    {
        return $this->run(sprintf('incus image info %s >/dev/null 2>&1', escapeshellarg($alias)))->successful();
    }
}
