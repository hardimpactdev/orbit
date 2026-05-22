<?php

declare(strict_types=1);

use App\E2E\Support\DockerHost;
use App\E2E\Support\DockerInstance;
use App\E2E\Support\E2EConfig;
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

    $script = $method->invoke(null, '/home/orbit/orbit-current', '10.6.0.2', '10.6.0.2', '10.6.0.2');

    expect($script)
        ->toContain('sudo -iu orbit bash -lc')
        ->toContain('mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs')
        ->toContain('VIEW_COMPILED_PATH=\'.escapeshellarg($orbitPath.\'/storage/framework/views\').\' \'.$command;')
        ->toContain('orbit node:new')
        ->not->toContain('php artisan');
});

it('runs Docker gateway api shim commands directly inside orbit runtime', function (): void {
    $reflection = new ReflectionClass(E2EGatewayApi::class);
    $method = $reflection->getMethod('tlsServerTinkerCode');
    $method->setAccessible(true);

    $script = $method->invoke(null, '/home/orbit/orbit', '10.6.0.2', '0.0.0.0', 'gateway', [], true);

    expect($script)
        ->toContain("exec(\$script.' 2>&1'")
        ->toContain("\$process = popen(\$script.' 2>&1'")
        ->not->toContain('sudo -iu orbit bash -lc');
});

it('forwards node grant permissions through the gateway api shim', function (): void {
    $script = gatewayTlsServerScript();

    expect($script)
        ->toContain("\$parts[] = '--preset='.escapeshellarg((string) \$preset);")
        ->toContain("\$parts[] = '--permissions='.escapeshellarg((string) \$permissions);")
        ->toContain("\$parts[] = '--force';");
});

it('prepares runtime environment before issuing gateway api certificates', function (): void {
    $instance = new class implements E2EInstance
    {
        /** @var list<string> */
        public array $commands = [];

        public function name(): string
        {
            return 'gateway';
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

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

    E2EGatewayApi::start($instance, 'runtime-env', '/home/orbit/orbit-current', '10.6.0.2');

    expect($instance->commands[0])
        ->toContain('([ -f .env ] || cp .env.example .env)')
        ->toContain("APP_KEY='")
        ->toContain('printf')
        ->toContain('APP_KEY=base64:.+')
        ->toContain('orbit key:generate --force --no-interaction')
        ->not->toContain("grep -q '^APP_KEY=base64:' .env");
});

it('starts Docker gateway API support through runtime container commands without host PHP or host Caddy', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result();
    });

    $instance = new DockerInstance(
        new DockerHost(E2EConfig::fromEnvironment()),
        'orbit-e2e-run123-gateway',
        'orbit-e2e-run123',
    );

    E2EGatewayApi::start(
        $instance,
        'docker-gateway-api',
        gatewayIp: '10.6.0.2',
        wireguardIdentity: '10.6.0.2',
        bindAddress: '0.0.0.0',
        certKey: 'gateway',
        certSans: ['10.6.0.2'],
    );

    $setup = implode("\n", $commands);

    $httpStart = collect($commands)->first(fn (string $command): bool => str_contains($command, 'sudo docker exec --detach')
        && str_contains($command, 'orbit-e2e-run123-gateway-orbit-runtime')
        && str_contains($command, 'orbit serve --host='));
    $tlsWrite = collect($commands)->first(fn (string $command): bool => str_contains($command, 'sudo docker exec')
        && str_contains($command, 'orbit-e2e-run123-gateway-orbit-runtime')
        && str_contains($command, 'cat >')
        && str_contains($command, '/tmp/orbit-docker-gateway-api-tls.php'));
    $tlsStart = collect($commands)->first(fn (string $command): bool => str_contains($command, 'sudo docker exec --detach')
        && str_contains($command, 'orbit-e2e-run123-gateway-orbit-runtime')
        && str_contains($command, 'orbit tinker --execute='));
    $runtimeIdentity = collect($commands)->first(fn (string $command): bool => str_contains($command, 'sudo docker exec')
        && str_contains($command, 'orbit-e2e-run123-gateway-orbit-runtime')
        && str_contains($command, '/home/orbit/.ssh/id_ed25519')
        && str_contains($command, '/root/.ssh/id_ed25519'));

    expect($setup)
        ->toContain('orbit tinker --execute=')
        ->toContain('orbit serve --host=')
        ->toContain('sudo docker exec --detach')
        ->toContain("'orbit-e2e-run123-gateway-orbit-runtime'")
        ->toContain('ORBIT_SOURCE_PATH=/home/orbit/orbit')
        ->not->toContain('php artisan')
        ->not->toContain('php -S')
        ->not->toContain('nohup php')
        ->not->toContain('php -r')
        ->not->toContain('systemctl stop caddy');

    expect($runtimeIdentity)->toBeString()
        ->toContain('install -d -m 700 /root/.ssh')
        ->toContain('cp /home/orbit/.ssh/id_ed25519 /root/.ssh/id_ed25519');

    expect(array_search($runtimeIdentity, $commands, strict: true))
        ->toBeLessThan(array_search($httpStart, $commands, strict: true))
        ->toBeLessThan(array_search($tlsStart, $commands, strict: true));

    expect($httpStart)->toBeString()
        ->toContain('ORBIT_SOURCE_PATH=/home/orbit/orbit');

    expect($tlsWrite)->toBeString()
        ->toContain('/tmp/orbit-docker-gateway-api-tls.php');

    expect($tlsStart)->toBeString()
        ->toContain('/tmp/orbit-docker-gateway-api-tls.php')
        ->not->toContain('sudo -iu orbit');
});

it('stops Docker gateway API TLS shim before restarting', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result();
    });

    $instance = new DockerInstance(
        new DockerHost(E2EConfig::fromEnvironment()),
        'orbit-e2e-run123-gateway',
        'orbit-e2e-run123',
    );

    E2EGatewayApi::restart(
        $instance,
        'docker-gateway-api',
        gatewayIp: '10.6.0.2',
        wireguardIdentity: '10.6.0.2',
        bindAddress: '0.0.0.0',
        certKey: 'gateway',
        certSans: ['10.6.0.2'],
    );

    $stop = collect($commands)->first(fn (string $command): bool => str_contains($command, 'sudo docker exec')
        && str_contains($command, 'orbit-e2e-run123-gateway-orbit-runtime')
        && str_contains($command, '/proc/[0-9]*/cmdline'));
    $tlsStart = collect($commands)->first(fn (string $command): bool => str_contains($command, 'sudo docker exec --detach')
        && str_contains($command, 'orbit-e2e-run123-gateway-orbit-runtime')
        && str_contains($command, 'orbit tinker --execute='));

    expect($stop)->toBeString()
        ->toContain('/tmp/orbit-')
        ->toContain('-tls.php')
        ->toContain('orbit\ serve\ --host=')
        ->toContain('php\ *artisan\ serve\ --host=');

    expect($tlsStart)->toBeString()
        ->toContain('/tmp/orbit-docker-gateway-api-tls.php');

    expect(array_search($stop, $commands, strict: true))
        ->toBeLessThan(array_search($tlsStart, $commands, strict: true));
});

it('stops Docker gateway API HTTP processes after the runtime entrypoint execs artisan serve', function (): void {
    expect(gatewayStopScriptMatchesCommand(
        'php /home/orbit/orbit/artisan serve --host=0.0.0.0 --port=80 --tries=1 --no-reload --quiet',
    ))->toBeTrue();
});

it('can split gateway wireguard identity from bind address and cert key', function (): void {
    $instance = new class implements E2EInstance
    {
        /** @var list<string> */
        public array $commands = [];

        public function name(): string
        {
            return 'gateway';
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

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

    E2EGatewayApi::start(
        $instance,
        'dns-alias',
        '/home/orbit/orbit-current',
        gatewayIp: '10.99.0.2',
        wireguardIdentity: '10.6.0.2',
        bindAddress: '0.0.0.0',
        certKey: 'gateway',
        certSans: ['10.6.0.2'],
    );

    expect($instance->commands[0])
        ->toContain('issueLeaf')
        ->toContain('gateway')
        ->toContain('10.6.0.2')
        ->and(implode("\n", $instance->commands))
        ->toContain('php -d display_errors=0 -S 0.0.0.0:80')
        ->toContain('$certKey = \'gateway\'')
        ->toContain('/storage/app/orbit/certs/')
        ->toContain('$wireguardIdentity = \'10.6.0.2\'')
        ->toContain('$bindAddress = \'0.0.0.0\'')
        ->toContain("stream_socket_server('tls://'.\$bindAddress.':443'")
        ->toContain("'wireguard' => \$wireguardIdentity");
});

it('maps configured docker peer ips to canonical wireguard identities in the gateway tls proxy', function (): void {
    $script = gatewayTlsServerScript(peerIdentityMap: [
        '10.61.42.3' => '10.6.0.3',
        '10.61.42.4' => '10.6.0.4',
    ]);

    expect(gatewayCanonicalPeerIp($script, '10.61.42.4'))->toBe('10.6.0.4')
        ->and($script)->toContain("\$headers['x-orbit-e2e-wireguard-ip'] = canonical_peer_ip(\$clientIp);");
});

it('forwards raw peer ips in the default gateway tls proxy mode', function (): void {
    expect(gatewayCanonicalPeerIp(gatewayTlsServerScript(), '10.61.42.4'))->toBe('10.61.42.4');
});

/**
 * @param  array<string, string>  $peerIdentityMap
 */
function gatewayTlsServerScript(array $peerIdentityMap = []): string
{
    $reflection = new ReflectionClass(E2EGatewayApi::class);
    $method = $reflection->getMethod('tlsServerScript');
    $method->setAccessible(true);

    return $method->invoke(
        null,
        '/home/orbit/orbit-current',
        '10.6.0.2',
        '0.0.0.0',
        'gateway',
        $peerIdentityMap,
    );
}

function gatewayCanonicalPeerIp(string $script, string $peerIp): string
{
    $prefix = strstr($script, 'function proxy_to_laravel', before_needle: true);

    if (! is_string($prefix)) {
        throw new RuntimeException('Generated gateway TLS script is missing proxy_to_laravel().');
    }

    $file = tempnam(sys_get_temp_dir(), 'orbit-gateway-script-');

    if (! is_string($file)) {
        throw new RuntimeException('Could not create temporary PHP script.');
    }

    file_put_contents($file, $prefix."\n\necho canonical_peer_ip(\$argv[1]);\n");

    try {
        $output = [];
        $exitCode = 0;

        exec('php '.escapeshellarg($file).' '.escapeshellarg($peerIp), $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException('Generated gateway TLS identity script exited with code '.$exitCode.'.');
        }

        return implode("\n", $output);
    } finally {
        @unlink($file);
    }
}

function gatewayStopScriptMatchesCommand(string $command): bool
{
    $reflection = new ReflectionClass(E2EGatewayApi::class);
    $method = $reflection->getMethod('stopServerShellScript');
    $method->setAccessible(true);
    $script = $method->invoke(null);

    preg_match('/case "\$command" in\s+(?<patterns>.+?)\)\s+pids=/s', $script, $matches);

    if (! isset($matches['patterns'])) {
        throw new RuntimeException('Gateway stop script is missing its process matcher.');
    }

    $file = tempnam(sys_get_temp_dir(), 'orbit-gateway-stop-');

    if (! is_string($file)) {
        throw new RuntimeException('Could not create temporary shell script.');
    }

    file_put_contents($file, implode("\n", [
        '#!/usr/bin/env bash',
        'command='.escapeshellarg($command),
        'case "$command" in',
        trim($matches['patterns']).') echo match ;;',
        '*) echo miss ;;',
        'esac',
    ]));

    try {
        $output = [];
        $exitCode = 0;

        exec('bash '.escapeshellarg($file), $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException('Gateway stop matcher shell check exited with code '.$exitCode.'.');
        }

        return trim(implode("\n", $output)) === 'match';
    } finally {
        @unlink($file);
    }
}
