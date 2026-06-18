<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Run a registered command non-interactively so consent and required-argument
 * gating returns the documented human prose instead of echoing an interactive
 * prompt. Mirrors the DoctorFix non-interactive renderer tests.
 *
 * @param  array<string, mixed>  $params
 * @return array{0: int, 1: string}
 */
function runVpnCommandNonInteractive(string $command, array $params = []): array
{
    $instance = Artisan::all()[$command];
    $instance->setLaravel(app());

    $input = new ArrayInput($params);
    $input->setInteractive(false);
    $output = new BufferedOutput;

    $exitCode = $instance->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}

describe('vpn commands', function (): void {
    it('lists vpn clients through the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'clients' => [
                [
                    'id' => 'client-1',
                    'name' => 'laptop',
                    'address' => '10.6.0.7',
                    'enabled' => true,
                    'latest_handshake_at' => null,
                    'kind' => 'admin',
                ],
            ],
        ], ['count' => 1]));

        [$exitCode, $output] = runCommand($this, 'vpn-client:list', [
            '--totp' => '123456',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://gateway.test/api/vpn/clients?totp=123456');

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['clients'][0]['name'])->toBe('laptop')
            ->and($decoded['success']['meta']['count'])->toBe(1);
    });

    it('renders human vpn client output as a table with uppercase headers and classified fields', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'clients' => [
                [
                    'id' => 'client-1',
                    'name' => 'laptop',
                    'address' => '10.6.0.7',
                    'enabled' => true,
                    'latest_handshake_at' => '2026-06-17T09:00:00+00:00',
                    'kind' => 'admin',
                ],
                [
                    'id' => 'client-2',
                    'name' => 'app-1',
                    'address' => '10.6.0.8',
                    'enabled' => false,
                    'latest_handshake_at' => null,
                    'kind' => 'node',
                ],
            ],
        ], ['count' => 2]));

        [$exitCode, $output] = runCommand($this, 'vpn-client:list', ['--totp' => '123456']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('NAME')
            ->and($output)->toContain('ADDRESS')
            ->and($output)->toContain('ENABLED')
            ->and($output)->toContain('KIND')
            ->and($output)->toContain('LATEST HANDSHAKE')
            ->and($output)->toContain('laptop')
            ->and($output)->toContain('10.6.0.7')
            ->and($output)->toContain('yes')
            ->and($output)->toContain('admin')
            ->and($output)->toContain('2026-06-17T09:00:00+00:00')
            ->and($output)->toContain('app-1')
            ->and($output)->toContain('no')
            ->and($output)->toContain('node')
            ->and($output)->toContain('never')
            ->and($output)->not->toContain('clients: [');
    });

    it('renders the documented empty state when no vpn clients are configured', function (): void {
        fakeGateway(fakeSuccessEnvelope(['clients' => []], ['count' => 0]));

        [$exitCode, $output] = runCommand($this, 'vpn-client:list', ['--totp' => '123456']);

        expect($exitCode)->toBe(0)
            ->and($output)->toBe('No VPN clients configured.');
    });

    it('creates vpn clients through the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'client' => [
                'id' => 'client-1',
                'name' => 'laptop',
                'address' => '10.6.0.7',
                'enabled' => true,
                'kind' => 'admin',
                'config' => '[Interface]',
            ],
        ], [
            'config_included' => true,
        ]));

        [$exitCode, $output] = runCommand($this, 'vpn-client:new', [
            'name' => 'laptop',
            '--config' => true,
            '--totp' => '123456',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/vpn/clients'
            && $request->data() === [
                'name' => 'laptop',
                'config' => true,
                'totp' => '123456',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['client']['config'])->toContain('[Interface]')
            ->and($decoded['success']['meta']['config_included'])->toBeTrue();
    });

    it('prompts for a missing vpn client name in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'client' => [
                'id' => 'client-1',
                'name' => 'laptop',
                'address' => '10.6.0.7',
                'enabled' => true,
                'kind' => 'admin',
            ],
        ]));

        $this->artisan('vpn-client:new')
            ->expectsQuestion('VPN client name', 'laptop')
            ->expectsOutputToContain('client')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/vpn/clients'
            && $request->data() === [
                'name' => 'laptop',
                'config' => false,
            ]);
    });

    it('validates vpn client names before contacting the gateway in json mode', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'vpn-client:new', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta'])->toMatchArray([
                'field' => 'name',
                'reason' => 'missing',
            ]);
    });

    it('toggles vpn clients through the gateway', function (string $command, string $endpoint, bool $enabled, string $actionKey): void {
        fakeGateway(fakeSuccessEnvelope([
            'client' => [
                'name' => 'laptop',
                'enabled' => $enabled,
                'action' => $actionKey,
                "already_{$actionKey}" => false,
            ],
        ]));

        [$exitCode, $output] = runCommand($this, $command, [
            'name' => 'laptop',
            '--totp' => '123456',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === "https://gateway.test/api/vpn/clients/laptop/{$endpoint}"
            && $request->data() === ['totp' => '123456']);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['client']['enabled'])->toBe($enabled);
    })->with([
        'enable' => ['vpn-client:enable', 'enable', true, 'enabled'],
        'disable' => ['vpn-client:disable', 'disable', false, 'disabled'],
    ]);

    it('prompts for a missing vpn client name before toggling in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'client' => [
                'name' => 'laptop',
                'enabled' => true,
                'action' => 'enabled',
                'already_enabled' => false,
            ],
        ]));

        $this->artisan('vpn-client:enable')
            ->expectsQuestion('VPN client name', 'laptop')
            ->expectsOutputToContain('client')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/vpn/clients/laptop/enable'
            && $request->data() === []);
    });

    it('removes vpn clients only with force', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'client' => [
                'name' => 'laptop',
                'action' => 'removed',
            ],
        ]));

        [$missingForceExitCode, $missingForceOutput] = runCommand($this, 'vpn-client:remove', [
            'name' => 'laptop',
            '--json' => true,
        ]);

        $missingForce = json_decode($missingForceOutput, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($missingForceExitCode)->toBe(1)
            ->and($missingForce['error']['code'])->toBe('validation_failed')
            ->and($missingForce['error']['meta'])->toMatchArray([
                'field' => 'force',
                'reason' => 'destructive_consent_required',
            ]);

        fakeGateway(fakeSuccessEnvelope([
            'client' => [
                'name' => 'laptop',
                'action' => 'removed',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'vpn-client:remove', [
            'name' => 'laptop',
            '--force' => true,
            '--totp' => '123456',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://gateway.test/api/vpn/clients/laptop'
            && $request->data() === [
                'force' => true,
                'totp' => '123456',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['client']['action'])->toBe('removed');
    });

    it('prompts for vpn client name before removing in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'client' => [
                'name' => 'laptop',
                'action' => 'removed',
            ],
        ]));

        $this->artisan('vpn-client:remove', ['--force' => true])
            ->expectsQuestion('VPN client name', 'laptop')
            ->expectsOutputToContain('removed')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://gateway.test/api/vpn/clients/laptop'
            && $request->data() === ['force' => true]);
    });

    it('rotates the vpn web ui password through the gateway without printing the secret', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'vpn' => [
                'password_changed' => true,
                'sessions_invalidated' => true,
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'vpn-web-ui:change-password', [
            'password' => 'new-secret-password',
            '--force' => true,
            '--totp' => '123456',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/vpn/web-ui/password'
            && $request->data() === [
                'password' => 'new-secret-password',
                'force' => true,
                'totp' => '123456',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['vpn']['password_changed'])->toBeTrue()
            ->and($output)->not->toContain('new-secret-password');
    });

    it('prompts for vpn web ui password and destructive confirmation in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'vpn' => [
                'password_changed' => true,
                'sessions_invalidated' => true,
            ],
        ]));

        $this->artisan('vpn-web-ui:change-password')
            ->expectsQuestion('New VPN web UI password', 'new-secret-password')
            ->expectsConfirmation('Use --force to rotate the VPN web UI password.', 'yes')
            ->expectsOutputToContain('VPN web UI password rotated')
            ->doesntExpectOutputToContain('new-secret-password')
            ->doesntExpectOutputToContain('password_changed')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/vpn/web-ui/password'
            && $request->data() === [
                'password' => 'new-secret-password',
                'force' => true,
            ]);
    });

    it('validates vpn web ui password input before contacting the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$missingPasswordExitCode, $missingPasswordOutput] = runCommand($this, 'vpn-web-ui:change-password', [
            '--force' => true,
            '--json' => true,
        ]);
        [$shortPasswordExitCode, $shortPasswordOutput] = runCommand($this, 'vpn-web-ui:change-password', [
            'password' => 'too-short',
            '--force' => true,
            '--json' => true,
        ]);
        [$missingForceExitCode, $missingForceOutput] = runCommand($this, 'vpn-web-ui:change-password', [
            'password' => 'new-secret-password',
            '--json' => true,
        ]);

        $missingPassword = json_decode($missingPasswordOutput, associative: true, flags: JSON_THROW_ON_ERROR);
        $shortPassword = json_decode($shortPasswordOutput, associative: true, flags: JSON_THROW_ON_ERROR);
        $missingForce = json_decode($missingForceOutput, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($missingPasswordExitCode)->toBe(1)
            ->and($missingPassword['error']['meta']['field'])->toBe('password')
            ->and($shortPasswordExitCode)->toBe(1)
            ->and($shortPassword['error']['meta']['field'])->toBe('password')
            ->and($missingForceExitCode)->toBe(1)
            ->and($missingForce['error']['meta'])->toMatchArray([
                'field' => 'force',
                'reason' => 'destructive_consent_required',
            ]);
    });

    it('preserves gateway error envelopes for vpn commands', function (): void {
        fakeGateway(fakeErrorEnvelope('vpn_runtime_unavailable', 'No active VPN role node is available for VPN administration.'), 400);

        [$exitCode, $output] = runCommand($this, 'vpn-client:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('vpn_runtime_unavailable');
    });
});

describe('vpn human renderers', function (): void {
    it('renders vpn-client:new human output as a progress tree with the address summary', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'client' => [
                'id' => 'client-1',
                'name' => 'laptop',
                'address' => '10.6.0.7',
                'enabled' => true,
                'kind' => 'admin',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'vpn-client:new', [
            'name' => 'laptop',
            '--totp' => '123456',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Creating VPN client')
            ->and($output)->toContain('Create VPN client')
            ->and($output)->toContain('VPN client `laptop` created')
            ->and($output)->toContain('Address: 10.6.0.7')
            ->and($output)->not->toContain('Render client config')
            ->and($output)->not->toContain('client:')
            ->and($output)->not->toContain('{');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/vpn/clients'
            && $request->data() === [
                'name' => 'laptop',
                'config' => false,
                'totp' => '123456',
            ]);
    });

    it('renders the optional config block and the render config step when --config is present', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'client' => [
                'id' => 'client-1',
                'name' => 'laptop',
                'address' => '10.6.0.7',
                'enabled' => true,
                'kind' => 'admin',
                'config' => "[Interface]\nPrivateKey = redacted",
            ],
        ], ['config_included' => true]));

        [$exitCode, $output] = runCommand($this, 'vpn-client:new', [
            'name' => 'laptop',
            '--config' => true,
            '--totp' => '123456',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Creating VPN client')
            ->and($output)->toContain('Render client config')
            ->and($output)->toContain('VPN client `laptop` created')
            ->and($output)->toContain('Address: 10.6.0.7')
            ->and($output)->toContain('[Interface]')
            ->and($output)->not->toContain('config:');
    });

    it('renders vpn-client:new gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('vpn_runtime_unavailable', 'No active VPN role node is available for VPN administration.'), 400);

        [$exitCode, $output] = runCommand($this, 'vpn-client:new', [
            'name' => 'laptop',
            '--totp' => '123456',
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('No active VPN role node is available')
            ->and($output)->not->toContain('"error"');
    });

    it('renders vpn-client:new validation failures as prose without a tree', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runVpnCommandNonInteractive('vpn-client:new');

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('VPN client name is required.')
            ->and($output)->not->toContain('Creating VPN client')
            ->and($output)->not->toContain('"error"');
    });

    it('renders vpn-client:enable human output as a progress tree with enabled prose', function (string $command, string $endpoint, string $action): void {
        fakeGateway(fakeSuccessEnvelope([
            'client' => [
                'name' => 'laptop',
                'enabled' => $action === 'enabled',
                'action' => $action,
                "already_{$action}" => false,
            ],
        ]));

        [$exitCode, $output] = runCommand($this, $command, [
            'name' => 'laptop',
            '--totp' => '123456',
        ]);

        $title = $action === 'enabled' ? 'Enabling VPN client' : 'Disabling VPN client';
        $step = $action === 'enabled' ? 'Enable VPN client' : 'Disable VPN client';

        expect($exitCode)->toBe(0)
            ->and($output)->toContain($title)
            ->and($output)->toContain($step)
            ->and($output)->toContain("VPN client `laptop` {$action}")
            ->and($output)->not->toContain('enabled:')
            ->and($output)->not->toContain('{');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === "https://gateway.test/api/vpn/clients/laptop/{$endpoint}"
            && $request->data() === ['totp' => '123456']);
    })->with([
        'enable' => ['vpn-client:enable', 'enable', 'enabled'],
        'disable' => ['vpn-client:disable', 'disable', 'disabled'],
    ]);

    it('renders the already-toggled prose when the client is in the desired state', function (string $command, string $action): void {
        fakeGateway(fakeSuccessEnvelope([
            'client' => [
                'name' => 'laptop',
                'enabled' => $action === 'enabled',
                'action' => $action,
                "already_{$action}" => true,
            ],
        ]));

        [$exitCode, $output] = runCommand($this, $command, [
            'name' => 'laptop',
            '--totp' => '123456',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("VPN client `laptop` already {$action}")
            ->and($output)->not->toContain('{');
    })->with([
        'enable' => ['vpn-client:enable', 'enabled'],
        'disable' => ['vpn-client:disable', 'disabled'],
    ]);

    it('renders vpn-client:enable gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('vpn_active_node_peer', 'VPN client `app-1` is an active Orbit node peer.'), 422);

        [$exitCode, $output] = runCommand($this, 'vpn-client:enable', [
            'name' => 'app-1',
            '--totp' => '123456',
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('active Orbit node peer')
            ->and($output)->not->toContain('"error"');
    });

    it('renders vpn-client:remove human output as a progress tree with removed prose', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'client' => [
                'name' => 'laptop',
                'action' => 'removed',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'vpn-client:remove', [
            'name' => 'laptop',
            '--force' => true,
            '--totp' => '123456',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Removing VPN client')
            ->and($output)->toContain('Remove VPN client')
            ->and($output)->toContain('VPN client `laptop` removed')
            ->and($output)->not->toContain('action:')
            ->and($output)->not->toContain('{');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://gateway.test/api/vpn/clients/laptop'
            && $request->data() === [
                'force' => true,
                'totp' => '123456',
            ]);
    });

    it('renders missing --force consent as prose without a tree for vpn-client:remove', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runVpnCommandNonInteractive('vpn-client:remove', [
            'name' => 'laptop',
        ]);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Use --force to remove this VPN client.')
            ->and($output)->not->toContain('Removing VPN client')
            ->and($output)->not->toContain('"error"');
    });

    it('renders vpn-client:remove gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('vpn_active_node_peer', 'VPN client `app-1` is an active Orbit node peer. Use `orbit node:remove app-1`.'), 422);

        [$exitCode, $output] = runCommand($this, 'vpn-client:remove', [
            'name' => 'app-1',
            '--force' => true,
            '--totp' => '123456',
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('active Orbit node peer')
            ->and($output)->not->toContain('"error"');
    });

    it('renders vpn-web-ui:change-password human output as a progress tree without leaking the secret', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'vpn' => [
                'password_changed' => true,
                'sessions_invalidated' => true,
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'vpn-web-ui:change-password', [
            'password' => 'new-secret-password',
            '--force' => true,
            '--totp' => '123456',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Rotating VPN web UI password')
            ->and($output)->toContain('Apply and verify credential rotation')
            ->and($output)->toContain('VPN web UI password rotated')
            ->and($output)->not->toContain('new-secret-password')
            ->and($output)->not->toContain('password_changed')
            ->and($output)->not->toContain('{');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/vpn/web-ui/password'
            && $request->data() === [
                'password' => 'new-secret-password',
                'force' => true,
                'totp' => '123456',
            ]);
    });

    it('renders vpn-web-ui:change-password validation failures as prose without a tree', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$shortExitCode, $shortOutput] = runVpnCommandNonInteractive('vpn-web-ui:change-password', [
            'password' => 'too-short',
            '--force' => true,
        ]);
        [$forceExitCode, $forceOutput] = runVpnCommandNonInteractive('vpn-web-ui:change-password', [
            'password' => 'new-secret-password',
        ]);

        Http::assertNothingSent();

        expect($shortExitCode)->toBe(1)
            ->and($shortOutput)->toContain('Password must be at least 12 characters.')
            ->and($shortOutput)->not->toContain('Rotating VPN web UI password')
            ->and($forceExitCode)->toBe(1)
            ->and($forceOutput)->toContain('Use --force to rotate the VPN web UI password.')
            ->and($forceOutput)->not->toContain('Rotating VPN web UI password')
            ->and($forceOutput)->not->toContain('new-secret-password');
    });

    it('renders vpn-web-ui:change-password gateway failures as prose without leaking the secret', function (): void {
        fakeGateway(fakeErrorEnvelope('vpn_credential_rotation_failed', 'VPN credential rotation failed.'), 422);

        [$exitCode, $output] = runCommand($this, 'vpn-web-ui:change-password', [
            'password' => 'new-secret-password',
            '--force' => true,
            '--totp' => '123456',
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('VPN credential rotation failed.')
            ->and($output)->not->toContain('new-secret-password')
            ->and($output)->not->toContain('"error"');
    });
});
