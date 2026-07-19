<?php

declare(strict_types=1);

use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\SourceMountedCheckoutInstance;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

it('routes source-mounted gateway-local actions through the candidate orbit cli', function (): void {
    $command = E2EGatewayApi::sourceMountedGatewayStateCommand();

    expect($command)
        ->toContain('ORBIT_LOCAL_EXECUTOR_BINARY=/usr/local/bin/orbit-cli')
        ->not
        ->toContain('/opt/orbit/apps/cli/orbit')
        ->and(strpos(haystack: $command, needle: 'ORBIT_LOCAL_EXECUTOR_BINARY=/usr/local/bin/orbit-cli'))
        ->toBeLessThan(strpos(haystack: $command, needle: 'php apps/gateway/artisan migrate'));
});

it('starts and preflights a source-mounted gateway-local executor shim', function (): void {
    $gateway = new class implements E2EInstance, SourceMountedCheckoutInstance {
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

        public function ssh(
            string $user,
            SshKeyPair $keyPair,
            string $command,
            ?int $timeoutSeconds = null,
        ): ProcessResult {
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

    E2EGatewayApi::startSourceMountedGatewayLocalExecutor($gateway);

    $joined = implode("\n", $gateway->execCommands);
    $start = strpos(haystack: $joined, needle: 'exec sleep infinity');
    $preflight = strpos(haystack: $joined, needle: 'test -x');

    expect($joined)
        ->toContain('--name '.escapeshellarg('orbit-gateway'))
        ->toContain('--mount '.escapeshellarg('type=bind,source=/home/orbit/orbit,target=/srv/orbit'))
        ->toContain(escapeshellarg('/home/orbit/orbit/apps/cli/orbit:/usr/local/bin/orbit-cli:ro'))
        ->toContain('--env '.escapeshellarg('ORBIT_LOCAL_EXECUTOR_BINARY=/usr/local/bin/orbit-cli'))
        ->toContain('exec sleep infinity')
        ->toContain(
            'docker exec '.escapeshellarg('orbit-gateway').' test -x '.escapeshellarg('/usr/local/bin/orbit-cli'),
        )
        ->and($start)
        ->toBeInt()
        ->and($preflight)
        ->toBeInt()
        ->and($start)
        ->toBeLessThan($preflight);
});

it('binds the source-mounted checkout orbit cli into retained incus gateway shims', function (): void {
    $gateway = new class implements E2EInstance, SourceMountedCheckoutInstance {
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

        public function ssh(
            string $user,
            SshKeyPair $keyPair,
            string $command,
            ?int $timeoutSeconds = null,
        ): ProcessResult {
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
    $gateway = new class implements E2EInstance {
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

        public function ssh(
            string $user,
            SshKeyPair $keyPair,
            string $command,
            ?int $timeoutSeconds = null,
        ): ProcessResult {
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
    $gateway = new class implements E2EInstance, SourceMountedCheckoutInstance {
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

        public function ssh(
            string $user,
            SshKeyPair $keyPair,
            string $command,
            ?int $timeoutSeconds = null,
        ): ProcessResult {
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
