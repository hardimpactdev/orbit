<?php

declare(strict_types=1);

use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\SourceMountedCheckoutInstance;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

it('binds the source-mounted checkout orbit cli into retained incus gateway shims', function (): void {
    $gateway = new class implements E2EInstance, SourceMountedCheckoutInstance
    {
        /** @var list<string> */
        public array $execCommands = [];

        public function name(): string
        {
            return 'gateway';
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->execCommands[] = $command;

            return Process::result();
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            return Process::result();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void {}

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '10.6.0.2';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}

        public function sourceMountedCheckoutPath(): ?string
        {
            return '/home/orbit/orbit';
        }
    };

    E2EGatewayApi::start($gateway, 'source-mounted-test');

    $shimCommands = array_values(array_filter(
        $gateway->execCommands,
        fn (string $command): bool => str_contains($command, 'docker run --rm --detach --pull never'),
    ));

    expect($shimCommands)->toHaveCount(2);

    foreach ($shimCommands as $command) {
        expect($command)
            ->toContain('--mount '.escapeshellarg('type=bind,source=/home/orbit/orbit,target=/srv/orbit'))
            ->toContain(escapeshellarg('/home/orbit/orbit/apps/cli/orbit:/usr/local/bin/orbit-cli:ro'))
            ->not->toContain(escapeshellarg('/usr/local/bin/orbit:/usr/local/bin/orbit-cli:ro'));
    }
});

it('binds an explicit current checkout into prepared incus gateway shims', function (): void {
    $gateway = new class implements E2EInstance
    {
        /** @var list<string> */
        public array $execCommands = [];

        public function name(): string
        {
            return 'gateway';
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->execCommands[] = $command;

            return Process::result();
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            return Process::result();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void {}

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '10.6.0.2';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };

    E2EGatewayApi::restart($gateway, 'current-checkout-test', '/home/orbit/orbit-current');

    $shimCommands = array_values(array_filter(
        $gateway->execCommands,
        fn (string $command): bool => str_contains($command, 'docker run --rm --detach --pull never'),
    ));

    expect($shimCommands)->toHaveCount(2);

    foreach ($shimCommands as $command) {
        expect($command)
            ->toContain('--mount '.escapeshellarg('type=bind,source=/home/orbit/orbit-current,target=/srv/orbit'))
            ->toContain(escapeshellarg('/home/orbit/orbit-current/apps/cli/orbit:/usr/local/bin/orbit-cli:ro'))
            ->not->toContain(escapeshellarg('/usr/local/bin/orbit:/usr/local/bin/orbit-cli:ro'));
    }
});

it('lets an explicit runtime checkout override the source-mounted gateway path', function (): void {
    $gateway = new class implements E2EInstance, SourceMountedCheckoutInstance
    {
        /** @var list<string> */
        public array $execCommands = [];

        public function name(): string
        {
            return 'gateway';
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->execCommands[] = $command;

            return Process::result();
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            return Process::result();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void {}

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '10.6.0.2';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}

        public function sourceMountedCheckoutPath(): ?string
        {
            return '/home/orbit/orbit';
        }
    };

    E2EGatewayApi::restart($gateway, 'runtime-mirror-test', '/home/orbit/orbit-run');

    $shimCommands = array_values(array_filter(
        $gateway->execCommands,
        fn (string $command): bool => str_contains($command, 'docker run --rm --detach --pull never'),
    ));

    expect($shimCommands)->toHaveCount(2);

    foreach ($shimCommands as $command) {
        expect($command)
            ->toContain('--mount '.escapeshellarg('type=bind,source=/home/orbit/orbit-run,target=/srv/orbit'))
            ->toContain(escapeshellarg('/home/orbit/orbit-run/apps/cli/orbit:/usr/local/bin/orbit-cli:ro'))
            ->not->toContain(escapeshellarg('/home/orbit/orbit/apps/cli/orbit:/usr/local/bin/orbit-cli:ro'));
    }
});
