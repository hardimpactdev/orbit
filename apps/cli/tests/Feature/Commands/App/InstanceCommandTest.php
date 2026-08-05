<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('instance commands', function (): void {
    it('adds Laravel Cloud instances through the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'instance' => [
                'app' => 'billing',
                'name' => 'production-cloud',
                'driver' => 'laravel-cloud',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:add', [
            'instance' => 'billing.production-cloud',
            '--driver' => 'laravel-cloud',
            '--cloud-app' => 'app_123',
            '--cloud-environment' => 'env_123',
            '--domain' => 'platform11.nl',
            '--php-extension' => ['redis', 'intl'],
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/apps/billing/instances'
                && $data['name'] === 'production-cloud'
                && $data['driver'] === 'laravel-cloud'
                && $data['cloud_application'] === 'app_123'
                && $data['cloud_environment'] === 'env_123'
                && $data['domain'] === 'platform11.nl'
                && $data['php_extensions'] === ['redis', 'intl']
            );
        });

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['instance']['name'])->toBe('production-cloud');
    });

    it('renders human add output with the created instance name driver and extensions', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'instance' => [
                'app' => 'billing',
                'name' => 'production',
                'driver' => 'orbit',
                'driver_config' => ['node' => 'app-1'],
                'runtime' => [
                    'mode' => 'classic',
                    'php_version' => '8.5',
                    'required_php_extensions' => ['intl', 'redis'],
                ],
            ],
            'cloud_compatibility' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:add', [
            'instance' => 'billing.production',
            '--node' => 'app-1',
            '--php-extension' => ['intl', 'redis'],
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("Added instance 'production' to app 'billing'.")
            ->and($output)
            ->toContain('  driver: orbit')
            ->and($output)
            ->toContain('  extensions: intl, redis')
            ->and($output)
            ->not->toContain('{')->and($output)
            ->not->toContain('instance: {')->and($output)
            ->not->toContain('"driver_config"')->and($output)
            ->not->toContain('cloud_compatibility');
    });

    it('renders human add output without an extensions line when none are required', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'instance' => [
                'app' => 'billing',
                'name' => 'production',
                'driver' => 'orbit',
                'driver_config' => ['node' => 'app-1'],
                'runtime' => [
                    'mode' => 'classic',
                    'php_version' => '8.5',
                    'required_php_extensions' => [],
                ],
            ],
            'cloud_compatibility' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:add', [
            'instance' => 'billing.production',
            '--node' => 'app-1',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("Added instance 'production' to app 'billing'.")
            ->and($output)
            ->toContain('  driver: orbit')
            ->and($output)
            ->not->toContain('extensions:')->and($output)
            ->not->toContain('{');
    });

    it('renders human remove output with the removed instance name', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => [
                'action' => 'removed',
                'app' => 'billing',
                'instance' => 'production',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:remove', [
            'instance' => 'billing.production',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toBe("Removed instance 'production'.")
            ->and($output)
            ->not->toContain('{')->and($output)
            ->not->toContain('result:');
    });

    it('confirms interactive instance removal when force is absent', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => [
                'action' => 'removed',
                'app' => 'billing',
                'instance' => 'production',
            ],
        ]));

        $this
            ->artisan('instance:remove', [
                'instance' => 'billing.production',
            ])
            ->expectsConfirmation(
                "Remove instance 'billing.production'? The app and sibling instances will remain.",
                'yes',
            )
            ->expectsOutput("Removed instance 'production'.")
            ->assertSuccessful();
    });

    it('filters the global instance list by app', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'instances' => [],
        ], [
            'count' => 0,
        ]));

        [$exitCode] = runCommand($this, 'instance:list', [
            '--app' => 'billing',
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'GET'
                && $request->url() === 'https://gateway.test/api/instances?app=billing'
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('registers instance:list --app as a value-taking option in the command signature', function (): void {
        $command = app(\Illuminate\Contracts\Console\Kernel::class)->all()['instance:list'] ?? null;

        expect($command)->not->toBeNull();

        $option = $command->getDefinition()->getOption('app');

        // Prove value-taking optional option (not a bare boolean flag).
        expect($option->acceptValue())
            ->toBeTrue()
            ->and($option->isValueRequired())
            ->toBeFalse()
            ->and($option->isValueOptional())
            ->toBeTrue()
            ->and($option->getDescription())
            ->toBe('Limit results to one app')
            ->and($command->getDefinition()->hasOption('project'))
            ->toBeFalse();
    });

    it('renders human list output as a table of instances', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'instances' => [
                [
                    'app' => 'billing',
                    'name' => 'production',
                    'driver' => 'orbit',
                    'driver_config' => ['node' => 'app-1'],
                    'runtime' => [
                        'mode' => 'worker',
                        'php_version' => '8.5',
                        'required_php_extensions' => ['intl', 'redis'],
                    ],
                    'latest_deployment_status' => 'succeeded',
                ],
                [
                    'app' => 'billing',
                    'name' => 'cloud',
                    'driver' => 'laravel-cloud',
                    'driver_config' => [],
                    'runtime' => [
                        'mode' => 'classic',
                        'php_version' => null,
                        'required_php_extensions' => [],
                    ],
                    'latest_deployment_status' => null,
                ],
            ],
        ], ['count' => 2]));

        [$exitCode, $output] = runCommand($this, 'instance:list');

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('APP')
            ->and($output)
            ->not->toContain('PROJECT')->and($output)->toContain('NAME')->and($output)->toContain('DRIVER')->and(
                $output,
            )->toContain('MODE')->and($output)->toContain('PHP')->and($output)->toContain('EXTENSIONS')->and(
                $output,
            )->toContain('DEPLOYMENT')->and($output)->toContain('production')->and($output)->toContain('orbit')->and(
                $output,
            )->toContain('worker')->and($output)->toContain('8.5')->and($output)->toContain('intl, redis')->and(
                $output,
            )->toContain('succeeded')->and($output)->toContain('cloud')->and($output)->toContain('laravel-cloud')->and(
                $output,
            )->toContain('classic')->and($output)->toContain('—')->and($output)
            ->not->toContain('instances: [')->and($output)
            ->not->toContain('"runtime"');
    });

    it('renders human empty list output when no instances exist', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'instances' => [],
        ], ['count' => 0]));

        [$exitCode, $output] = runCommand($this, 'instance:list');

        expect($exitCode)->toBe(0)->and($output)->toBe('No instances found.');
    });

    it('renders human show output as an instance detail summary', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'instance' => [
                'app' => 'billing',
                'name' => 'production',
                'driver' => 'orbit',
                'driver_config' => ['node' => 'app-1', 'document_root' => '/srv/billing/public'],
                'runtime' => [
                    'runtime' => 'php',
                    'php_version' => '8.5',
                    'mode' => 'worker',
                    'required_php_extensions' => ['intl', 'redis'],
                ],
                'latest_deployment_status' => 'succeeded',
            ],
            'cloud_compatibility' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:show', [
            'instance' => 'billing.production',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Instance: production')
            ->and($output)
            ->toContain('App')
            ->and($output)
            ->toContain('billing')
            ->and($output)
            ->toContain('Driver')
            ->and($output)
            ->toContain('orbit')
            ->and($output)
            ->toContain('Mode')
            ->and($output)
            ->toContain('worker')
            ->and($output)
            ->toContain('PHP')
            ->and($output)
            ->toContain('8.5')
            ->and($output)
            ->toContain('Extensions')
            ->and($output)
            ->toContain('intl, redis')
            ->and($output)
            ->toContain('Deployment')
            ->and($output)
            ->toContain('succeeded')
            ->and($output)
            ->not->toContain('instance: {')->and($output)
            ->not->toContain('"driver_config"');
    });

    it('rejects an instance selector without its app', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'instance:show', [
            'instance' => 'production',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('instance');
    });

    it('requires force before removing an instance non-interactively', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'instance:remove', [
            'instance' => 'billing.production',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('force');
    });
});
