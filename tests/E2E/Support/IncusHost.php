<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class IncusHost
{
    public function __construct(
        public readonly E2EConfig $config,
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

    public function instanceExists(string $name): bool
    {
        return $this->run(sprintf('incus info %s >/dev/null 2>&1', escapeshellarg($name)))->successful();
    }

    public function runningE2EInstanceCount(): int
    {
        $result = $this->run('incus list --format json');

        if (! $result->successful()) {
            throw new \RuntimeException("Could not list Incus instances on {$this->config->host}: {$result->errorOutput()}");
        }

        $instances = json_decode($result->output(), associative: true);

        if (! is_array($instances)) {
            return 0;
        }

        $prefix = $this->config->instancePrefix;
        $count = 0;

        foreach ($instances as $instance) {
            if (! is_array($instance)) {
                continue;
            }

            $name = $instance['name'] ?? null;
            $status = $instance['status'] ?? null;

            if (is_string($name) && is_string($status)
                && str_starts_with($name, $prefix)
                && $status === 'Running'
            ) {
                $count++;
            }
        }

        return $count;
    }

    public function snapshotExists(string $instance, string $snapshot): bool
    {
        return $this->run(sprintf(
            'incus info %s --show-log=false 2>/dev/null | grep -q %s',
            escapeshellarg($instance),
            escapeshellarg("{$snapshot}"),
        ))->successful();
    }

    public function copyInstance(string $source, string $target): ProcessResult
    {
        return $this->run(sprintf(
            'incus copy %s %s',
            escapeshellarg($source),
            escapeshellarg($target),
        ));
    }

    public function startInstance(string $name): ProcessResult
    {
        return $this->run(sprintf('incus start %s', escapeshellarg($name)));
    }

    public function setInstanceLimits(string $name, string $cpus, string $memory): ProcessResult
    {
        return $this->run(sprintf(
            'incus config set %s limits.cpu=%s limits.memory=%s',
            escapeshellarg($name),
            escapeshellarg($cpus),
            escapeshellarg($memory),
        ));
    }

    public function stopInstance(string $name): ProcessResult
    {
        return $this->run(sprintf('incus stop --force %s', escapeshellarg($name)));
    }

    public function snapshotInstance(string $name, string $snapshot): ProcessResult
    {
        return $this->run(sprintf(
            'incus snapshot create %s %s',
            escapeshellarg($name),
            escapeshellarg($snapshot),
        ));
    }

    public function restoreSnapshot(string $name, string $snapshot): ProcessResult
    {
        return $this->run(sprintf(
            'incus restore %s %s',
            escapeshellarg($name),
            escapeshellarg($snapshot),
        ));
    }

    public function deleteSnapshot(string $name, string $snapshot): ProcessResult
    {
        return $this->run(sprintf(
            'incus snapshot delete %s %s >/dev/null 2>&1 || true',
            escapeshellarg($name),
            escapeshellarg($snapshot),
        ));
    }
}
