<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final readonly class DockerHost
{
    public function __construct(
        public E2EConfig $config,
        public string $host = 'local',
    ) {}

    public static function fromCurrentDockerEnvironment(E2EConfig $config): self
    {
        return new self($config, self::hostNameFromCurrentDockerEnvironment());
    }

    public static function hostNameFromCurrentDockerEnvironment(): string
    {
        $dockerHost = getenv('DOCKER_HOST');

        if (! is_string($dockerHost) || trim($dockerHost) === '') {
            return 'local';
        }

        $dockerHost = trim($dockerHost);

        if (! str_starts_with($dockerHost, 'ssh://')) {
            return 'local';
        }

        $host = substr($dockerHost, strlen('ssh://'));

        if ($host === '') {
            return 'local';
        }

        if (str_contains($host, '@')) {
            $host = substr($host, strrpos($host, '@') + 1);
        }

        $host = explode(':', $host, 2)[0];

        return $host !== '' ? $host : 'local';
    }

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

    public function isLocal(): bool
    {
        return $this->host === 'local';
    }

    public function mustRun(string $command, string $errorContext, ?int $timeoutSeconds = null): ProcessResult
    {
        $result = $this->run($command, $timeoutSeconds);

        if (! $result->successful()) {
            throw new RuntimeException("{$errorContext}: ".trim($result->errorOutput().' '.$result->output()));
        }

        return $result;
    }

    public function dockerSocketGroupId(): int
    {
        if (($cached = DockerSocketGroupAdd::resolvedGroupId($this->host)) !== null) {
            return $cached;
        }

        $result = $this->isLocal()
            ? $this->run(self::localDockerSocketGroupIdCommand(), timeoutSeconds: 10)
            : $this->resolveRemoteDockerSocketGroupId();

        if (! $result->successful()) {
            throw new RuntimeException(sprintf(
                'Could not resolve Docker socket group id on %s: %s',
                $this->host,
                trim($result->errorOutput().' '.$result->output()),
            ));
        }

        $groupId = (int) trim($result->output());

        if ($groupId <= 0) {
            throw new RuntimeException(sprintf(
                'Docker socket group id on %s resolved to an invalid value: %s',
                $this->host,
                trim($result->output()),
            ));
        }

        return DockerSocketGroupAdd::rememberGroupId($this->host, $groupId);
    }

    public static function remoteDockerSocketGroupIdCommand(): string
    {
        return 'stat -c %g /var/run/docker.sock 2>/dev/null || stat -f %g /var/run/docker.sock';
    }

    public static function localDockerSocketGroupIdCommand(): string
    {
        return (
            'for path in /var/run/docker.sock "${HOME}/.orbstack/run/docker.sock" "${HOME}/.docker/run/docker.sock"; do '
            .'if [ -S "$path" ]; then stat -c %g "$path" 2>/dev/null || stat -f %g "$path"; exit 0; fi; '
            .'done; exit 1'
        );
    }

    private function resolveRemoteDockerSocketGroupId(): ProcessResult
    {
        return Process::timeout(10)->run(sprintf(
            'ssh -o BatchMode=yes -o ConnectTimeout=10 %s %s',
            escapeshellarg($this->host),
            escapeshellarg(self::remoteDockerSocketGroupIdCommand()),
        ));
    }
}
