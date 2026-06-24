<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('node:manage', function (): void {
    it('installs the gateway key locally before posting management metadata', function (): void {
        $home = sys_get_temp_dir().'/orbit-node-manage-'.bin2hex(random_bytes(4));
        $previousHome = getenv('HOME');
        $previousUser = getenv('USER');
        $previousLogname = getenv('LOGNAME');
        $publicKey = 'ssh-ed25519 AAAAC3NzaManagedGatewayKey orbit-gateway';

        mkdir($home, 0777, true);
        putenv("HOME={$home}");
        putenv('USER=nicky');
        putenv('LOGNAME=nicky');
        $_ENV['HOME'] = $home;
        $_SERVER['HOME'] = $home;

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);

        Http::fake([
            'https://gateway.test/*' => Http::sequence()
                ->push(fakeSuccessEnvelope([
                    'self' => [
                        'name' => 'mini',
                        'status' => 'active',
                        'platform' => 'unknown',
                        'roles' => [],
                        'addresses' => ['wireguard' => '10.44.0.24'],
                    ],
                    'gateway' => ['name' => 'gateway-1'],
                ]))
                ->push(fakeSuccessEnvelope([
                    'management_ssh_key' => [
                        'public_key' => $publicKey,
                    ],
                ]))
                ->push(fakeSuccessEnvelope([
                    'management' => [
                        'node' => 'mini',
                        'user' => 'nicky',
                        'platform' => 'macos_15-5',
                        'ssh_host' => '10.44.0.24',
                        'authorized_key_installed' => true,
                        'host_key_pinned' => true,
                        'ssh_verified' => true,
                    ],
                ])),
        ]);

        try {
            [$exitCode, $output] = runCommand($this, 'node:manage', [
                '--user' => 'nicky',
                '--json' => true,
            ]);
        } finally {
            if (is_string($previousHome)) {
                putenv("HOME={$previousHome}");
                $_ENV['HOME'] = $previousHome;
                $_SERVER['HOME'] = $previousHome;
            }

            is_string($previousUser) ? putenv("USER={$previousUser}") : putenv('USER');
            is_string($previousLogname) ? putenv("LOGNAME={$previousLogname}") : putenv('LOGNAME');
        }

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        $authorizedKeys = file_get_contents("{$home}/.ssh/authorized_keys");

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'GET'
                && $request->url() === 'https://gateway.test/api/me'
            ),
        );
        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'GET'
                && $request->url() === 'https://gateway.test/api/nodes/self/manage-key'
            ),
        );
        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/nodes/self/manage'
                && $request['user'] === 'nicky'
                && is_string($request['platform'])
                && $request['platform'] !== ''
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($authorizedKeys)
            ->toContain($publicKey)
            ->and($decoded['success']['data']['management']['node'])
            ->toBe('mini');
    });

    it('rejects a selected ssh user that differs from the current local user', function (): void {
        $home = sys_get_temp_dir().'/orbit-node-manage-user-'.bin2hex(random_bytes(4));
        $previousHome = getenv('HOME');
        $previousUser = getenv('USER');
        $previousLogname = getenv('LOGNAME');

        mkdir($home, 0777, true);
        putenv("HOME={$home}");
        putenv('USER=nicky');
        putenv('LOGNAME=nicky');
        $_ENV['HOME'] = $home;
        $_SERVER['HOME'] = $home;

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);

        Http::fake([
            'https://gateway.test/*' => Http::sequence()
                ->push(fakeSuccessEnvelope([
                    'self' => [
                        'name' => 'mini',
                        'status' => 'active',
                        'platform' => 'unknown',
                        'roles' => [],
                        'addresses' => ['wireguard' => '10.44.0.24'],
                    ],
                    'gateway' => ['name' => 'gateway-1'],
                ]))
                ->push(fakeSuccessEnvelope([
                    'management_ssh_key' => [
                        'public_key' => 'ssh-ed25519 AAAAC3NzaManagedGatewayKey orbit-gateway',
                    ],
                ])),
        ]);

        try {
            [$exitCode, $output] = runCommand($this, 'node:manage', [
                '--user' => 'other-user',
                '--json' => true,
            ]);
        } finally {
            if (is_string($previousHome)) {
                putenv("HOME={$previousHome}");
                $_ENV['HOME'] = $previousHome;
                $_SERVER['HOME'] = $previousHome;
            }

            is_string($previousUser) ? putenv("USER={$previousUser}") : putenv('USER');
            is_string($previousLogname) ? putenv("LOGNAME={$previousLogname}") : putenv('LOGNAME');
        }

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSentCount(2);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('user')
            ->and(file_exists("{$home}/.ssh/authorized_keys"))
            ->toBeFalse();
    });

    it('rejects role-bearing identities before local authorized key writes', function (): void {
        $home = sys_get_temp_dir().'/orbit-node-manage-reject-'.bin2hex(random_bytes(4));
        $previousHome = getenv('HOME');

        mkdir($home, 0777, true);
        putenv("HOME={$home}");
        $_ENV['HOME'] = $home;
        $_SERVER['HOME'] = $home;

        fakeGateway(fakeSuccessEnvelope([
            'self' => [
                'name' => 'gateway-1',
                'status' => 'active',
                'platform' => 'ubuntu_24-04',
                'roles' => [
                    ['role' => 'gateway', 'status' => 'active', 'settings' => []],
                ],
                'addresses' => ['wireguard' => '10.44.0.1'],
            ],
            'gateway' => ['name' => 'gateway-1'],
        ]));

        try {
            [$exitCode, $output] = runCommand($this, 'node:manage', [
                '--user' => 'orbit',
                '--json' => true,
            ]);
        } finally {
            if (is_string($previousHome)) {
                putenv("HOME={$previousHome}");
                $_ENV['HOME'] = $previousHome;
                $_SERVER['HOME'] = $previousHome;
            }
        }

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSentCount(1);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('node.not_operator')
            ->and(file_exists("{$home}/.ssh/authorized_keys"))
            ->toBeFalse();
    });
});
