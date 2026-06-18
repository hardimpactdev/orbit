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

    it('renders human set output naming the saved key and instance', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => 'billing',
            'instance' => 'development',
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
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toBe("Saved 'APP_DEBUG' for instance 'development'.")
            ->and($output)->not->toContain('{')
            ->and($output)->not->toContain('variable:');
    });

    it('renders human render output as an aligned effective env map', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => 'billing',
            'instance' => 'production',
            'variables' => [
                'APP_ENV' => ['value' => 'production', 'secret' => false, 'source' => 'instance'],
                'DB_PASSWORD' => ['value' => null, 'secret' => true, 'source' => 'database'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:env', [
            'action' => 'render',
            'app' => 'billing',
            '--instance' => 'production',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('APP_ENV=production')
            ->and($output)->toContain('DB_PASSWORD=')
            ->and($output)->not->toContain('{')
            ->and($output)->not->toContain('variables:')
            ->and($output)->not->toContain('"secret"')
            ->and($output)->not->toContain('source');
    });

    it('renders human render output with an empty effective env map', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => 'billing',
            'instance' => 'production',
            'variables' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:env', [
            'action' => 'render',
            'app' => 'billing',
            '--instance' => 'production',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toBe('No environment values found.')
            ->and($output)->not->toContain('{');
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

    it('renders human list output as a table of non-secret env variables', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => 'billing',
            'instance' => 'production',
            'variables' => [
                ['key' => 'APP_ENV', 'value' => 'production', 'secret' => false],
                ['key' => 'APP_DEBUG', 'value' => 'false', 'secret' => false],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:env', [
            'action' => 'list',
            'app' => 'billing',
            '--instance' => 'production',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('KEY')
            ->and($output)->toContain('VALUE')
            ->and($output)->toContain('APP_ENV')
            ->and($output)->toContain('production')
            ->and($output)->toContain('APP_DEBUG')
            ->and($output)->toContain('false')
            ->and($output)->not->toContain('variables: [')
            ->and($output)->not->toContain('"secret"');
    });

    it('renders human empty list output when no env variables exist', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => 'billing',
            'instance' => 'production',
            'variables' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:env', [
            'action' => 'list',
            'app' => 'billing',
            '--instance' => 'production',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toBe('No environment values found.');
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
