<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('app:instance', function (): void {
    it('adds laravel cloud app instances through the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'instance' => [
                'app' => 'billing',
                'name' => 'production-cloud',
                'driver' => 'laravel-cloud',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:instance', [
            'action' => 'add',
            'app' => 'billing',
            '--instance' => 'production-cloud',
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

            return $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/apps/billing/instances'
                && $data['name'] === 'production-cloud'
                && $data['driver'] === 'laravel-cloud'
                && $data['cloud_application'] === 'app_123'
                && $data['cloud_environment'] === 'env_123'
                && $data['domain'] === 'platform11.nl'
                && $data['php_extensions'] === ['redis', 'intl'];
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['instance']['name'])->toBe('production-cloud');
    });

    it('supports --app as the app selector for scripts and agents', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'instances' => [],
        ], [
            'count' => 0,
        ]));

        [$exitCode] = runCommand($this, 'app:instance', [
            'action' => 'list',
            '--app' => 'billing',
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://gateway.test/api/apps/billing/instances');

        expect($exitCode)->toBe(0);
    });

    it('fails deterministic validation when positional app and --app differ', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'app:instance', [
            'action' => 'show',
            'app' => 'billing',
            '--app' => 'crm',
            '--instance' => 'production',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('app');
    });

    it('requires force before removing an instance non-interactively', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'app:instance', [
            'action' => 'remove',
            'app' => 'billing',
            '--instance' => 'production',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('force');
    });
});
