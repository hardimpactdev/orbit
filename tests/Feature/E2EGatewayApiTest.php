<?php

declare(strict_types=1);

use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

it('installs provisioning SSH keys for gateway API runtime users', function (): void {
    $privateKey = tempnam(sys_get_temp_dir(), 'orbit-e2e-key-');

    if (! is_string($privateKey)) {
        throw new RuntimeException('Could not create temporary SSH key.');
    }

    $publicKey = "{$privateKey}.pub";

    file_put_contents($privateKey, 'private-key');
    file_put_contents($publicKey, 'public-key');

    $instance = new class implements E2EInstance
    {
        /** @var list<array{source: string, target: string}> */
        public array $copies = [];

        public function name(): string
        {
            return 'gateway';
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            return Process::result();
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            return Process::result();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void
        {
            $this->copies[] = ['source' => $sourcePath, 'target' => $targetPath];
        }

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '10.6.0.2';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };

    try {
        E2EGatewayApi::installProvisioningSshKey($instance, new SshKeyPair($privateKey, $publicKey));

        expect(array_column($instance->copies, 'target'))->toBe([
            '/root/.ssh/id_ed25519',
            '/home/orbit/.ssh/id_ed25519',
            '/var/www/.ssh/id_ed25519',
        ]);
    } finally {
        @unlink($privateKey);
        @unlink($publicKey);
    }
});

it('runs gateway api shim commands as the orbit runtime user', function (): void {
    $reflection = new ReflectionClass(E2EGatewayApi::class);
    $method = $reflection->getMethod('tlsServerScript');
    $method->setAccessible(true);

    $script = $method->invoke(null, '/home/orbit/orbit-current', '10.6.0.2');

    expect($script)
        ->toContain('sudo -iu orbit bash -lc')
        ->toContain('$script = \'cd \'.escapeshellarg($orbitPath).\' && \'.$command;');
});
