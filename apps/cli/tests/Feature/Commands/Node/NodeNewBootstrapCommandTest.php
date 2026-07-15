<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Http\JsonEnvelope;

it('prepares bootstrap streams it through client local SSH and then completes over the gateway', function (): void {
    fakeGatewayProgressStreamClient(gatewayProgressFrame('complete', [
        'exit_code' => 0,
        'data' => JsonEnvelope::success([
            'node' => ['name' => 'app-dev-1'],
            'provisioning' => ['transport' => 'client-ssh', 'status' => 'complete'],
        ]),
    ]));

    Http::fake([
        'https://gateway.test/api/nodes/bootstrap' => Http::response(JsonEnvelope::success([
            'bootstrap' => [
                'id' => 'bootstrap-123',
                'status' => 'pending',
                'host' => '192.0.2.20',
                'user' => 'root',
                'wireguard_address' => '10.6.0.4',
                'script' => "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\\n' bootstrapped\n",
            ],
        ])),
    ]);

    Process::fake([
        '*' => Process::result(output: "bootstrapped\n"),
    ]);
    Process::preventStrayProcesses();

    [$exitCode, $output] = runCommand($this, 'node:new', [
        'name' => 'app-dev-1',
        '--roles' => 'app-dev',
        '--host' => '192.0.2.20',
        '--user' => 'root',
        '--tld' => 'test',
        '--json' => true,
    ]);

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        return (
            $request->url() === 'https://gateway.test/api/nodes/bootstrap'
            && $payload['name'] === 'app-dev-1'
            && $payload['roles'] === ['app-dev']
            && $payload['host'] === '192.0.2.20'
            && $payload['user'] === 'root'
            && ! array_key_exists('host_key_fingerprint', $payload)
            && ! array_key_exists('ssh_private_key', $payload)
        );
    });

    Process::assertRan(
        fn ($process): bool => (
            is_array($process->command)
            && $process->command[0] === 'ssh'
            && in_array('root@192.0.2.20', $process->command, true)
            && $process->input === "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\\n' bootstrapped\n"
        ),
    );

    assertGatewayStreamSent(
        fn (FakeGatewayStreamRequest $request): bool => (
            $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/nodes/bootstrap/bootstrap-123/complete'
            && ! isset($request['script'])
        ),
    );

    $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)
        ->toBe(0)
        ->and($decoded['event'])
        ->toBe('complete');
});

it('does not ask the gateway to complete when client local SSH fails', function (): void {
    fakeGatewayProgressStreamClient(gatewayProgressFrame('complete', [
        'exit_code' => 0,
        'data' => JsonEnvelope::success(['node' => ['name' => 'app-dev-1']]),
    ]));

    Http::fake([
        'https://gateway.test/api/nodes/bootstrap' => Http::response(JsonEnvelope::success([
            'bootstrap' => [
                'id' => 'bootstrap-123',
                'status' => 'pending',
                'host' => '192.0.2.20',
                'user' => 'root',
                'wireguard_address' => '10.6.0.4',
                'script' => 'bootstrap-script',
            ],
        ])),
    ]);

    Process::fake([
        '*' => Process::result(errorOutput: 'Permission denied (publickey).', exitCode: 255),
    ]);

    [$exitCode, $output] = runCommand($this, 'node:new', [
        'name' => 'app-dev-1',
        '--roles' => 'app-dev',
        '--host' => '192.0.2.20',
        '--tld' => 'test',
        '--json' => true,
    ]);

    assertGatewayStreamNothingSent();

    $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)
        ->toBe(1)
        ->and($decoded['error']['code'])
        ->toBe('node.bootstrap_ssh_failed')
        ->and($decoded['error']['meta']['host'])
        ->toBe('192.0.2.20');
});

it('verifies an explicit SSH host key locally before streaming the bootstrap', function (): void {
    fakeGatewayProgressStreamClient(gatewayProgressFrame('complete', [
        'exit_code' => 0,
        'data' => JsonEnvelope::success(['node' => ['name' => 'database-1']]),
    ]));

    Http::fake([
        'https://gateway.test/api/nodes/bootstrap' => Http::response(JsonEnvelope::success([
            'bootstrap' => [
                'id' => 'bootstrap-verified',
                'status' => 'pending',
                'host' => '192.0.2.30',
                'user' => 'root',
                'wireguard_address' => '10.6.0.5',
                'script' => 'verified-bootstrap-script',
            ],
        ])),
    ]);

    $knownHostsPath = null;
    Process::fake(function ($process) use (&$knownHostsPath) {
        $command = $process->command;

        if (is_array($command) && $command[0] === 'ssh-keyscan') {
            return Process::result(output: implode("\n", [
                '192.0.2.30 ssh-rsa AAAARSA',
                '192.0.2.30 ssh-ed25519 AAAATEST',
                '',
            ]));
        }

        if (is_array($command) && $command[0] === 'ssh-keygen') {
            return Process::result(output: implode("\n", [
                '3072 SHA256:other 192.0.2.30 (RSA)',
                '256 SHA256:verified 192.0.2.30 (ED25519)',
                '',
            ]));
        }

        if (is_array($command) && $command[0] === 'ssh') {
            foreach ($command as $argument) {
                if (is_string($argument) && str_starts_with($argument, 'UserKnownHostsFile=')) {
                    $knownHostsPath = substr($argument, strlen('UserKnownHostsFile='));
                }
            }

            return Process::result();
        }

        return Process::result(exitCode: 1);
    });
    Process::preventStrayProcesses();

    [$exitCode] = runCommand($this, 'node:new', [
        'name' => 'database-1',
        '--roles' => 'database',
        '--host' => '192.0.2.30',
        '--host-key-fingerprint' => 'SHA256:verified',
        '--tld' => 'database-test',
        '--json' => true,
    ]);

    Http::assertSent(
        fn (Request $request): bool => $request->url() === 'https://gateway.test/api/nodes/bootstrap'
        && ! array_key_exists('host_key_fingerprint', $request->data()),
    );

    Process::assertRan(
        fn ($process): bool => (
            is_array($process->command)
            && $process->command[0] === 'ssh'
            && in_array('StrictHostKeyChecking=yes', $process->command, true)
            && $process->input === 'verified-bootstrap-script'
        ),
    );

    expect($exitCode)
        ->toBe(0)
        ->and($knownHostsPath)
        ->toBeString()
        ->and(File::exists((string) $knownHostsPath))
        ->toBeFalse();
});

it('rejects an SSH host key mismatch locally without completing the bootstrap', function (): void {
    fakeGatewayProgressStreamClient(gatewayProgressFrame('complete', [
        'exit_code' => 0,
        'data' => JsonEnvelope::success(['node' => ['name' => 'database-1']]),
    ]));

    Http::fake([
        'https://gateway.test/api/nodes/bootstrap' => Http::response(JsonEnvelope::success([
            'bootstrap' => [
                'id' => 'bootstrap-mismatch',
                'status' => 'pending',
                'host' => '192.0.2.30',
                'user' => 'root',
                'wireguard_address' => '10.6.0.5',
                'script' => 'untrusted-bootstrap-script',
            ],
        ])),
    ]);

    Process::fake(function ($process) {
        $command = $process->command;

        if (is_array($command) && $command[0] === 'ssh-keyscan') {
            return Process::result(output: "192.0.2.30 ssh-ed25519 AAAATEST\n");
        }

        if (is_array($command) && $command[0] === 'ssh-keygen') {
            return Process::result(output: "256 SHA256:observed 192.0.2.30 (ED25519)\n");
        }

        return Process::result(exitCode: 1);
    });
    Process::preventStrayProcesses();

    [$exitCode, $output] = runCommand($this, 'node:new', [
        'name' => 'database-1',
        '--roles' => 'database',
        '--host' => '192.0.2.30',
        '--host-key-fingerprint' => 'SHA256:expected',
        '--tld' => 'database-test',
        '--json' => true,
    ]);

    $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)
        ->toBe(1)
        ->and($decoded['error']['code'])
        ->toBe('node.host_key_mismatch');

    Process::assertRanTimes(
        fn ($process): bool => is_array($process->command) && $process->command[0] === 'ssh',
        0,
    );
    assertGatewayStreamNothingSent();
});
