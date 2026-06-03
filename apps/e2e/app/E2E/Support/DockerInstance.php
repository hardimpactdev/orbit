<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;

final class DockerInstance implements E2EInstance, SourceMountedCheckoutInstance
{
    private ?string $ipv4 = null;

    public function __construct(
        private readonly DockerHost $host,
        private readonly string $name,
        private readonly ?string $networkName = null,
        private readonly ?string $sourceMountedCheckoutPath = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function sourceMountedCheckoutPath(): ?string
    {
        return $this->sourceMountedCheckoutPath;
    }

    public function hostName(): string
    {
        return $this->host->host;
    }

    public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        return $this->host->run(implode(' ', [
            'docker exec',
            ...E2EGitHubAuth::dockerEnvOptions(),
            escapeshellarg($this->name),
            'sh -lc',
            escapeshellarg($command),
        ]), $timeoutSeconds);
    }

    public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        return $this->host->run(implode(' ', [
            'docker exec',
            ...E2EGitHubAuth::dockerEnvOptions(),
            '--user',
            escapeshellarg($user),
            escapeshellarg($this->name),
            'sh -lc',
            escapeshellarg($command),
        ]), $timeoutSeconds);
    }

    public function authorizeSsh(string $user, SshKeyPair $keyPair): void
    {
        $result = $this->exec(sprintf(
            'test -d %s',
            escapeshellarg("/home/{$user}"),
        ));

        if (! $result->successful()) {
            throw new \RuntimeException("Docker feature topology user [{$user}] does not exist in {$this->name}.");
        }
    }

    public function copyFileToInstance(string $sourcePath, string $targetPath): void
    {
        $result = $this->host->run(sprintf(
            'docker cp %s %s',
            escapeshellarg($sourcePath),
            escapeshellarg("{$this->name}:{$targetPath}"),
        ));

        if (! $result->successful()) {
            throw new \RuntimeException("Could not copy {$sourcePath} into {$this->name}: {$result->errorOutput()}");
        }
    }

    public function waitForAgent(): void
    {
        $result = $this->exec('true', timeoutSeconds: 10);

        if (! $result->successful()) {
            throw new \RuntimeException("Docker command transport is not ready for {$this->name}.");
        }
    }

    public function waitForIpv4(): string
    {
        if ($this->ipv4 !== null) {
            return $this->ipv4;
        }

        $template = $this->networkName === null
            ? '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}'
            : sprintf('{{(index .NetworkSettings.Networks %s).IPAddress}}', json_encode($this->networkName, JSON_THROW_ON_ERROR));

        $result = $this->host->run(sprintf(
            'docker inspect -f %s %s',
            escapeshellarg($template),
            escapeshellarg($this->name),
        ));

        if (! $result->successful() || trim($result->output()) === '') {
            throw new \RuntimeException("Docker container {$this->name} does not have an IPv4 address.");
        }

        return $this->ipv4 = trim($result->output());
    }

    public function waitForSsh(string $user, SshKeyPair $keyPair): void
    {
        $result = $this->ssh($user, $keyPair, 'test "$(uname -s)" = Linux && test -r /etc/os-release', timeoutSeconds: 10);

        if (! $result->successful()) {
            throw new \RuntimeException("Docker command transport is not ready for {$user}@{$this->name}.");
        }
    }

    public function delete(): void
    {
        $this->host->run(sprintf(
            'docker rm -f %s >/dev/null 2>&1 || true',
            implode(' ', array_map(escapeshellarg(...), [
                "{$this->name}-orbit-gateway",
                "{$this->name}-orbit-caddy",
                $this->name,
            ])),
        ), timeoutSeconds: 120);
        $this->host->run(sprintf(
            'docker volume rm -f %s >/dev/null 2>&1 || true',
            implode(' ', array_map(escapeshellarg(...), [
                "{$this->name}-home-orbit",
            ])),
        ), timeoutSeconds: 120);
    }
}
