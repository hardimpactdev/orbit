<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

final class HcloudInstance implements E2EInstance
{
    private ?string $ipv4 = null;

    public function __construct(
        private readonly E2EConfig $config,
        private readonly string $name,
        private readonly SshKeyPair $rootKeyPair,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        return $this->ssh('root', $this->rootKeyPair, $command, $timeoutSeconds);
    }

    public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        return $this->run(sprintf(
            'ssh -i %s -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s %s',
            escapeshellarg($keyPair->privateKeyPath),
            escapeshellarg("{$user}@{$this->waitForIpv4()}"),
            escapeshellarg($command),
        ), $timeoutSeconds);
    }

    public function authorizeSsh(string $user, SshKeyPair $keyPair): void
    {
        $publicKey = trim((string) file_get_contents($keyPair->publicKeyPath));

        $result = $this->exec(sprintf(
            'id -u %1$s >/dev/null 2>&1 || useradd -m -s /bin/bash %1$s; install -d -m 700 -o %1$s -g %1$s /home/%1$s/.ssh; printf %2$s > /home/%1$s/.ssh/authorized_keys; chown %1$s:%1$s /home/%1$s/.ssh/authorized_keys; chmod 600 /home/%1$s/.ssh/authorized_keys',
            escapeshellarg($user),
            escapeshellarg("{$publicKey}\n"),
        ));

        if (! $result->successful()) {
            throw new \RuntimeException("Could not prepare SSH access on {$this->name}: {$result->errorOutput()}");
        }
    }

    public function copyFileToInstance(string $sourcePath, string $targetPath): void
    {
        $result = $this->run(sprintf(
            'scp -i %s -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s %s',
            escapeshellarg($this->rootKeyPair->privateKeyPath),
            escapeshellarg($sourcePath),
            escapeshellarg("root@{$this->waitForIpv4()}:{$targetPath}"),
        ));

        if (! $result->successful()) {
            throw new \RuntimeException("Could not copy {$sourcePath} into {$this->name}: {$result->errorOutput()}");
        }
    }

    public function waitForIpv4(): string
    {
        if ($this->ipv4 !== null) {
            return $this->ipv4;
        }

        $deadline = time() + $this->config->timeoutSeconds;

        while (time() < $deadline) {
            $result = $this->run(sprintf('hcloud server ip %s', escapeshellarg($this->name)), timeoutSeconds: 10);
            $ip = trim($result->output());

            if ($result->successful() && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $this->ipv4 = $ip;

                return $ip;
            }

            sleep(2);
        }

        throw new \RuntimeException("hcloud server {$this->name} did not receive an IPv4 address.");
    }

    public function waitForSsh(string $user, SshKeyPair $keyPair): void
    {
        $deadline = time() + $this->config->timeoutSeconds;

        while (time() < $deadline) {
            if ($this->ssh($user, $keyPair, 'test "$(uname -s)" = Linux && test -r /etc/os-release', timeoutSeconds: 10)->successful()) {
                return;
            }

            sleep(3);
        }

        throw new \RuntimeException("SSH never became ready on {$this->name}.");
    }

    public function delete(): void
    {
        $this->run(sprintf(
            'hcloud server delete %s >/dev/null 2>&1 || true',
            escapeshellarg($this->name),
        ), timeoutSeconds: 120);
    }

    private function run(string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        return Process::timeout($timeoutSeconds ?? $this->config->timeoutSeconds)->run($command);
    }
}
