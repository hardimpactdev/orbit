<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;

interface E2EInstance
{
    public function name(): string;

    public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult;

    public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult;

    public function authorizeSsh(string $user, SshKeyPair $keyPair): void;

    public function copyFileToInstance(string $sourcePath, string $targetPath): void;

    public function waitForIpv4(): string;

    public function waitForSsh(string $user, SshKeyPair $keyPair): void;

    public function delete(): void;
}
