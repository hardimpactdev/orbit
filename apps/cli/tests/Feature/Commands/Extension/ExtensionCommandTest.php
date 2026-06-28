<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @mago-expect lint:halstead
 */
describe('extension commands', function (): void {
    beforeEach(function (): void {
        $this->tempPath = orbit_test_config_path(prefix: 'orbit-extension-cmd-test-');
        unlink_orbit_test_file($this->tempPath);
        app()->instance(OrbitConfigStore::class, new OrbitConfigStore(overridePath: $this->tempPath));
    });

    afterEach(function (): void {
        unlink_orbit_test_file($this->tempPath);
    });

    it('lists extensions with local and gateway state in JSON mode', function (): void {
        app(OrbitConfigStore::class)->enableExtension('solo');

        fakeGateway(fakeSuccessEnvelope([
            'extensions' => fake_gateway_extensions_snapshot(),
        ]));

        [$exitCode, $output] = runCommand($this, command: 'extension:list', params: [
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/extensions'),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['extensions'])
            ->toHaveCount(3)
            ->and(array_column($decoded['success']['data']['extensions'], 'slug'))
            ->toBe(['cloudflare', 'codex', 'solo']);

        $solo = collect($decoded['success']['data']['extensions'])->firstWhere('slug', 'solo');

        expect($solo)
            ->toHaveKeys([
                'slug',
                'label',
                'description',
                'commands',
                'permissions',
                'local_enabled',
                'gateway_enabled',
            ])
            ->and($solo['commands'])
            ->toBe([])
            ->and($solo['permissions'])
            ->toBe([])
            ->and($solo['local_enabled'])
            ->toBeTrue()
            ->and($solo['gateway_enabled'])
            ->toBeFalse();
    });

    it('lists extensions with null gateway state and a warning when gateway is down', function (): void {
        fakeGatewayDown();

        [$exitCode, $output] = runCommand($this, command: 'extension:list', params: [
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['meta']['warnings'])
            ->not->toBeEmpty();

        foreach ($decoded['success']['data']['extensions'] as $extension) {
            expect($extension['gateway_enabled'])->toBeNull();
        }
    });

    it('enables local state and posts gateway enable when solo is disabled on gateway', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(App\Services\GatewayApiClient::class);

        Http::fake([
            'https://gateway.test/api/extensions' => Http::response(
                fakeSuccessEnvelope([
                    'extensions' => fake_gateway_extensions_snapshot(),
                ]),
                200,
            ),
            'https://gateway.test/api/extensions/solo/enable' => Http::response(
                fakeSuccessEnvelope([
                    'extension' => [
                        'slug' => 'solo',
                        'enabled' => true,
                        'enabled_at' => '2026-06-28T00:00:00Z',
                    ],
                ]),
                200,
            ),
        ]);

        [$exitCode, $output] = runCommand($this, command: 'extension:enable', params: [
            'extension' => 'solo',
            '--json' => true,
            '--gateway' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/extensions/solo/enable'),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['extension']['slug'])
            ->toBe('solo')
            ->and(app(OrbitConfigStore::class)->isExtensionEnabled('solo'))
            ->toBeTrue();
    });

    it('requires an explicit gateway flag for non-interactive gateway-disabled enables', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'extensions' => fake_gateway_extensions_snapshot(),
        ]));

        [$exitCode, $output] = runCommand($this, command: 'extension:enable', params: [
            'extension' => 'solo',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSentCount(1);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('extension_gateway_enable_required')
            ->and($decoded['error']['meta']['extension'])
            ->toBe('solo')
            ->and(app(OrbitConfigStore::class)->isExtensionEnabled('solo'))
            ->toBeTrue();
    });

    it('enables gateway extension without altering local state', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'extension' => [
                'slug' => 'solo',
                'enabled' => true,
                'enabled_at' => '2026-06-28T00:00:00Z',
            ],
        ]));

        [$exitCode] = runCommand($this, command: 'extension:enable', params: [
            'extension' => 'solo',
            '--node' => 'gateway',
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/extensions/solo/enable'),
        );

        expect($exitCode)
            ->toBe(0)
            ->and(app(OrbitConfigStore::class)->isExtensionEnabled('solo'))
            ->toBeFalse();
    });

    it('disables local extension state', function (): void {
        app(OrbitConfigStore::class)->enableExtension('solo');

        [$exitCode, $output] = runCommand($this, command: 'extension:disable', params: [
            'extension' => 'solo',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['extension']['slug'])
            ->toBe('solo')
            ->and($decoded['success']['data']['extension']['local_enabled'])
            ->toBeFalse()
            ->and(app(OrbitConfigStore::class)->isExtensionEnabled('solo'))
            ->toBeFalse();
    });

    it('disables gateway extension without altering local state', function (): void {
        app(OrbitConfigStore::class)->enableExtension('solo');

        fakeGateway(fakeSuccessEnvelope([
            'extension' => [
                'slug' => 'solo',
                'enabled' => false,
                'enabled_at' => null,
            ],
        ]));

        [$exitCode] = runCommand($this, command: 'extension:disable', params: [
            'extension' => 'solo',
            '--node' => 'gateway',
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/extensions/solo/disable'),
        );

        expect($exitCode)
            ->toBe(0)
            ->and(app(OrbitConfigStore::class)->isExtensionEnabled('solo'))
            ->toBeTrue();
    });

    it('rejects unsupported node targets', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, command: 'extension:enable', params: [
            'extension' => 'solo',
            '--node' => 'mini',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('extension_node_target_unsupported')
            ->and($decoded['error']['meta']['node'])
            ->toBe('mini');
    });

    it('returns a canonical failure for unknown extensions', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, command: 'extension:enable', params: [
            'extension' => 'missing',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('extension_unknown')
            ->and($decoded['error']['meta']['extension'])
            ->toBe('missing')
            ->and($output)
            ->not->toContain('InvalidArgumentException')->and($output)
            ->not->toContain('Stack trace');
    });
});

/**
 * @return list<array<string, mixed>>
 */
function fake_gateway_extensions_snapshot(?string $solo_enabled_at = null): array
{
    $solo_enabled = $solo_enabled_at !== null;

    return [
        [
            'slug' => 'cloudflare',
            'label' => 'Cloudflare',
            'description' => 'Cloudflare DNS, cache, and SSL operations through the gateway.',
            'enabled' => false,
            'enabled_at' => null,
        ],
        [
            'slug' => 'codex',
            'label' => 'Codex',
            'description' => 'Codex App project registration on eligible operator nodes.',
            'enabled' => false,
            'enabled_at' => null,
        ],
        [
            'slug' => 'solo',
            'label' => 'Solo',
            'description' => 'Solo API command family through the Orbit gateway.',
            'enabled' => $solo_enabled,
            'enabled_at' => $solo_enabled_at,
        ],
    ];
}
