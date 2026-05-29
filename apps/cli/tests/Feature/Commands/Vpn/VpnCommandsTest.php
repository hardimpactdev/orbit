<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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
            ->expectsOutputToContain('password_changed')
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
