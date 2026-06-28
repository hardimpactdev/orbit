<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Extensions\OrbitExtensionRegistry;
use Symfony\Component\Process\Process;

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
            ->toContain('solo:project:list', 'solo:process:list', 'solo:scratchpad:list', 'solo:todo:list')
            ->and($solo['permissions'])
            ->toContain(
                'solo:*',
                'solo:project:list',
                'solo:process:read',
                'solo:scratchpad:write',
                'solo:todo:comment',
            )
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

    it('hides registered solo commands from discovery until the local solo extension is enabled', function (): void {
        $defaultConfigPath = orbit_test_config_path(prefix: 'orbit-command-list-solo-default-');
        $enabledConfigPath = orbit_test_config_path(prefix: 'orbit-command-list-solo-enabled-');

        unlink_orbit_test_file($defaultConfigPath);
        unlink_orbit_test_file($enabledConfigPath);

        try {
            $soloCommands = app(OrbitExtensionRegistry::class)->require('solo')->commands;

            $defaultVisible = extension_command_visible_names(extension_command_list_with_config($defaultConfigPath));

            $store = new OrbitConfigStore(overridePath: $enabledConfigPath);
            $store->enableExtension('solo');

            $enabledVisible = extension_command_visible_names(extension_command_list_with_config($enabledConfigPath));

            foreach ($soloCommands as $command) {
                expect($defaultVisible)
                    ->not
                    ->toContain($command)
                    ->and($enabledVisible)
                    ->toContain($command);
            }
        } finally {
            unlink_orbit_test_file($defaultConfigPath);
            unlink_orbit_test_file($enabledConfigPath);
        }
    });

    it('shows concrete solo commands in command catalog discovery mode', function (): void {
        $configPath = orbit_test_config_path(prefix: 'orbit-command-list-show-all-');

        unlink_orbit_test_file($configPath);

        try {
            $visible = extension_command_visible_names(extension_command_list_with_environment($configPath, [
                'ORBIT_CLI_SHOW_ALL_EXTENSION_COMMANDS' => '1',
            ]));

            expect($visible)
                ->toContain('cf-zone:list', 'codex:app')
                ->toContain('solo:project:list', 'solo:process:list', 'solo:scratchpad:list', 'solo:todo:list')
                ->not->toContain('solo:status');
        } finally {
            unlink_orbit_test_file($configPath);
        }
    });

    it('returns extension disabled when invoking a registered solo command while local solo is disabled', function (): void {
        [$exitCode, $output] = runCommand($this, command: 'solo:project:list', params: [
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('extension_disabled')
            ->and($decoded['error']['meta'])
            ->toMatchArray([
                'extension' => 'solo',
                'scope' => 'local',
            ]);
    });

    it('returns a deferred failure when invoking an unimplemented registered solo command after local solo is enabled', function (): void {
        app(OrbitConfigStore::class)->enableExtension('solo');

        [$exitCode, $output] = runCommand($this, command: 'solo:status', params: [
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('solo_command_deferred')
            ->and($decoded['error']['meta'])
            ->toMatchArray([
                'scope' => 'local',
            ]);
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

/**
 * @return array<string, mixed>
 */
function extension_command_list_with_config(string $configPath): array
{
    return extension_command_list_with_environment($configPath);
}

/**
 * @param  array<string, string>  $environment
 * @return array<string, mixed>
 */
function extension_command_list_with_environment(string $configPath, array $environment = []): array
{
    $process = new Process([PHP_BINARY, 'orbit', 'list', '--format=json'], base_path(), [
        'ORBIT_CONFIG_PATH' => $configPath,
        ...$environment,
    ]);
    $process->run();

    expect($process->getExitCode())->toBe(0, 'orbit list --format=json failed: '.$process->getErrorOutput());

    return json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $commandList
 * @return list<string>
 */
function extension_command_visible_names(array $commandList): array
{
    return array_values(array_column(
        array_filter($commandList['commands'], fn (array $command): bool => ! ($command['hidden'] ?? false)),
        'name',
    ));
}
