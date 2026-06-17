<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('app:env', function (): void {
    it('sets instance env values through the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'variable' => [
                'key' => 'APP_DEBUG',
                'value' => 'false',
                'secret' => false,
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:env', [
            'action' => 'set',
            'app' => 'billing',
            '--instance' => 'development',
            '--key' => 'APP_DEBUG',
            '--value' => 'false',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/apps/billing/instances/development/env'
            && $request->data() === [
                'key' => 'APP_DEBUG',
                'value' => 'false',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['variable']['key'])->toBe('APP_DEBUG');
    });

    it('renders app instance env with app selector supplied as --app', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'variables' => [
                'APP_ENV' => ['value' => 'production', 'secret' => false],
            ],
        ]));

        [$exitCode] = runCommand($this, 'app:env', [
            'action' => 'render',
            '--app' => 'billing',
            '--instance' => 'production',
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://gateway.test/api/apps/billing/instances/production/env/render');

        expect($exitCode)->toBe(0);
    });

    it('fails before gateway io when app selectors conflict', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'app:env', [
            'action' => 'list',
            'app' => 'billing',
            '--app' => 'crm',
            '--instance' => 'development',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('app');
    });

    it('does not allow secret writes in the first slice', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'app:env', [
            'action' => 'set',
            'app' => 'billing',
            '--instance' => 'development',
            '--key' => 'API_TOKEN',
            '--value' => 'secret',
            '--secret' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('secret');
    });
});
