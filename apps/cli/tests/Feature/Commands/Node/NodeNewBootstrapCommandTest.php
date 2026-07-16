<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Http\JsonEnvelope;

/** @mago-expect lint:cyclomatic-complexity */
it('prepares bootstrap streams it through client local SSH and then completes over the gateway', function (): void {
    $prepareTimeout = null;

    fakeGatewayProgressStreamClient(gatewayProgressFrame('complete', [
        'exit_code' => 0,
        'data' => JsonEnvelope::success([
            'node' => ['name' => 'app-dev-1'],
            'provisioning' => ['transport' => 'client-ssh', 'status' => 'complete'],
        ]),
    ]));

    Http::fake(function (Request $request, array $options) use (&$prepareTimeout) {
        $prepareTimeout = $options['timeout'] ?? null;

        if ($request->url() === 'https://gateway.test/api/nodes/bootstrap/resume') {
            return Http::response(JsonEnvelope::success(['preflight_required' => true]));
        }

        return Http::response(JsonEnvelope::success([
            'bootstrap' => [
                'id' => 'bootstrap-123',
                'status' => 'pending',
                'host' => '192.0.2.20',
                'user' => 'root',
                'wireguard_address' => '10.6.0.4',
                'script' => "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\\n' bootstrapped\n",
            ],
        ]));
    });

    Process::fake(function ($process) {
        if (str_contains((string) $process->input, 'ORBIT_TARGET_PLATFORM')) {
            return Process::result(output: "ubuntu_24-04\namd64\n");
        }

        return Process::result(output: "bootstrapped\n");
    });
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
            && $payload['platform'] === 'ubuntu_24-04'
            && $payload['architecture'] === 'amd64'
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
        ->toBe('complete')
        ->and($prepareTimeout)
        ->toBe(900);
});

it('routes host-provisioned templates through client SSH bootstrap', function (string $template): void {
    fakeGatewayProgressStreamClient(gatewayProgressFrame('complete', [
        'exit_code' => 0,
        'data' => JsonEnvelope::success(['node' => ['name' => "{$template}-1"]]),
    ]));

    Http::fake([
        'https://gateway.test/api/nodes/bootstrap/resume' => Http::response(JsonEnvelope::success([
            'preflight_required' => true,
        ])),
        'https://gateway.test/api/nodes/bootstrap' => Http::response(JsonEnvelope::success([
            'bootstrap' => [
                'id' => "bootstrap-{$template}",
                'status' => 'pending',
                'host' => '192.0.2.40',
                'user' => 'root',
                'wireguard_address' => '10.6.0.6',
                'script' => 'template-bootstrap-script',
            ],
        ])),
    ]);

    Process::fake(function ($process) {
        if (str_contains((string) $process->input, 'ORBIT_TARGET_PLATFORM')) {
            return Process::result(output: "ubuntu_24-04\narm64\n");
        }

        return Process::result();
    });
    Process::preventStrayProcesses();

    [$exitCode] = runCommand($this, 'node:new', [
        'name' => "{$template}-1",
        '--template' => $template,
        '--host' => '192.0.2.40',
        '--tld' => "{$template}-test",
        '--json' => true,
    ]);

    Http::assertSent(
        fn (Request $request): bool => (
            $request->url() === 'https://gateway.test/api/nodes/bootstrap'
            && $request['template'] === $template
            && $request['platform'] === 'ubuntu_24-04'
            && $request['architecture'] === 'arm64'
        ),
    );
    assertGatewayStreamSent(
        fn (FakeGatewayStreamRequest $request): bool => str_contains(
            $request->url(),
            "/api/nodes/bootstrap/bootstrap-{$template}/complete",
        ),
    );

    expect($exitCode)->toBe(0);
})->with(['app-development', 'database', 'ingress', 's3', 'metrics', 'agent']);

it('does not ask the gateway to complete when client local SSH fails', function (): void {
    fakeGatewayProgressStreamClient(gatewayProgressFrame('complete', [
        'exit_code' => 0,
        'data' => JsonEnvelope::success(['node' => ['name' => 'app-dev-1']]),
    ]));

    Http::fake([
        'https://gateway.test/api/nodes/bootstrap/resume' => Http::response(JsonEnvelope::success([
            'preflight_required' => true,
        ])),
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

it('rejects an unsupported target platform before reserving gateway identity', function (): void {
    config()->set('orbit.gateway.url', 'https://gateway.test');
    Http::fake([
        'https://gateway.test/api/nodes/bootstrap/resume' => Http::response(JsonEnvelope::success([
            'preflight_required' => true,
        ])),
    ]);
    Process::fake([
        '*' => Process::result(output: "debian_13\namd64\n"),
    ]);
    Process::preventStrayProcesses();

    [$exitCode, $output] = runCommand($this, 'node:new', [
        'name' => 'database-1',
        '--roles' => 'database',
        '--host' => '192.0.2.30',
        '--tld' => 'database-test',
        '--json' => true,
    ]);

    $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)
        ->toBe(1)
        ->and($decoded['error']['code'])
        ->toBe('node.unsupported_platform')
        ->and($decoded['error']['meta']['platform'])
        ->toBe('debian_13');
    Http::assertSentCount(1);
    Http::assertSent(
        fn (Request $request): bool => $request->url() === 'https://gateway.test/api/nodes/bootstrap/resume',
    );
});

/** @mago-expect lint:cyclomatic-complexity */
it('verifies an explicit SSH host key locally before streaming the bootstrap', function (): void {
    fakeGatewayProgressStreamClient(gatewayProgressFrame('complete', [
        'exit_code' => 0,
        'data' => JsonEnvelope::success(['node' => ['name' => 'database-1']]),
    ]));

    Http::fake([
        'https://gateway.test/api/nodes/bootstrap/resume' => Http::response(JsonEnvelope::success([
            'preflight_required' => true,
        ])),
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
    $knownHostsContents = null;
    Process::fake(function ($process) use (&$knownHostsPath, &$knownHostsContents) {
        $command = $process->command;

        if (is_array($command) && $command[0] === 'ssh-keyscan') {
            return Process::result(output: implode("\n", [
                '# 192.0.2.30:22 SSH-2.0-OpenSSH_10.2',
                '192.0.2.30 ssh-rsa AAAARSA',
                '# 192.0.2.30:22 SSH-2.0-OpenSSH_10.2',
                '192.0.2.30 ssh-ed25519 AAAATEST',
                '',
            ]));
        }

        if (is_array($command) && $command[0] === 'ssh-keygen') {
            if (str_starts_with((string) $process->input, '#')) {
                return Process::result(exitCode: 1);
            }

            return str_contains((string) $process->input, 'AAAATEST')
                ? Process::result(output: "256 SHA256:verified 192.0.2.30 (ED25519)\n")
                : Process::result(output: "3072 SHA256:other 192.0.2.30 (RSA)\n");
        }

        if (
            is_array($command)
            && $command[0] === 'ssh'
            && str_contains((string) $process->input, 'ORBIT_TARGET_PLATFORM')
        ) {
            return Process::result(output: "ubuntu_24-04\namd64\n");
        }

        if (is_array($command) && $command[0] === 'ssh') {
            foreach ($command as $argument) {
                if (is_string($argument) && str_starts_with($argument, 'UserKnownHostsFile=')) {
                    $knownHostsPath = substr($argument, strlen('UserKnownHostsFile='));
                    $knownHostsContents = File::get($knownHostsPath);
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
        ->and($knownHostsContents)
        ->toBe("192.0.2.30 ssh-ed25519 AAAATEST\n")
        ->and(File::exists((string) $knownHostsPath))
        ->toBeFalse();
});

it('rejects an SSH host key mismatch locally without completing the bootstrap', function (): void {
    fakeGatewayProgressStreamClient(gatewayProgressFrame('complete', [
        'exit_code' => 0,
        'data' => JsonEnvelope::success(['node' => ['name' => 'database-1']]),
    ]));

    Http::fake([
        'https://gateway.test/api/nodes/bootstrap/resume' => Http::response(JsonEnvelope::success([
            'preflight_required' => true,
        ])),
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

it('resumes a completed bootstrap without touching SSH after the target is hardened', function (): void {
    fakeGatewayProgressStreamClient(gatewayProgressFrame('complete', [
        'exit_code' => 0,
        'data' => JsonEnvelope::success(['node' => ['name' => 'database-1']]),
    ]));

    Http::fake([
        'https://gateway.test/api/nodes/bootstrap/resume' => Http::response(JsonEnvelope::success([
            'preflight_required' => false,
            'bootstrap' => [
                'id' => 'bootstrap-completed',
                'status' => 'completed',
                'ssh_required' => false,
            ],
        ])),
    ]);
    Process::fake();
    Process::preventStrayProcesses();

    [$exitCode] = runCommand($this, 'node:new', [
        'name' => 'database-1',
        '--roles' => 'database',
        '--host' => '192.0.2.30',
        '--tld' => 'database-test',
        '--json' => true,
    ]);

    Http::assertSentCount(1);
    Http::assertSent(
        fn (Request $request): bool => (
            $request->url() === 'https://gateway.test/api/nodes/bootstrap/resume'
            && ! array_key_exists('platform', $request->data())
            && ! array_key_exists('architecture', $request->data())
        ),
    );
    Process::assertNothingRan();
    assertGatewayStreamSent(
        fn (FakeGatewayStreamRequest $request): bool => (
            $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/nodes/bootstrap/bootstrap-completed/complete'
        ),
    );

    expect($exitCode)->toBe(0);
});

it('uses the node gateway API error code for an incomplete bootstrap resume response', function (): void {
    config()->set('orbit.gateway.url', 'https://gateway.test');
    Http::fake([
        'https://gateway.test/api/nodes/bootstrap/resume' => Http::response(JsonEnvelope::success([])),
    ]);
    Process::fake();
    Process::preventStrayProcesses();

    [$exitCode, $output] = runCommand($this, 'node:new', [
        'name' => 'database-1',
        '--roles' => 'database',
        '--host' => '192.0.2.30',
        '--tld' => 'database-test',
        '--json' => true,
    ]);

    $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)
        ->toBe(1)
        ->and($decoded['error']['code'])
        ->toBe('node.gateway_api_error');
    Process::assertNothingRan();
});

it('uses the node gateway API error code for an incomplete bootstrap prepare response', function (): void {
    config()->set('orbit.gateway.url', 'https://gateway.test');
    Http::fake([
        'https://gateway.test/api/nodes/bootstrap/resume' => Http::response(JsonEnvelope::success([
            'preflight_required' => true,
        ])),
        'https://gateway.test/api/nodes/bootstrap' => Http::response(JsonEnvelope::success([])),
    ]);
    Process::fake([
        '*' => Process::result(output: "ubuntu_24-04\namd64\n"),
    ]);
    Process::preventStrayProcesses();

    [$exitCode, $output] = runCommand($this, 'node:new', [
        'name' => 'database-1',
        '--roles' => 'database',
        '--host' => '192.0.2.30',
        '--tld' => 'database-test',
        '--json' => true,
    ]);

    $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)
        ->toBe(1)
        ->and($decoded['error']['code'])
        ->toBe('node.gateway_api_error');
});

it('surfaces WireGuard gateway reachability failures during bootstrap preparation', function (): void {
    fakeGatewayDown('No route to host');

    [$exitCode, $output] = runCommand($this, 'node:new', [
        'name' => 'database-1',
        '--roles' => 'database',
        '--host' => '192.0.2.30',
        '--tld' => 'database-test',
        '--json' => true,
    ]);

    $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)
        ->toBe(1)
        ->and($decoded['error']['code'])
        ->toBe('gateway_unreachable_wireguard');
});
