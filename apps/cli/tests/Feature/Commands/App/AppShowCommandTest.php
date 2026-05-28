<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('app:show', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards the app path', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'orbit-docs', 'node' => 'app-1'],
            'details' => ['domain' => 'orbit-docs.test'],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:show', [
            'app' => 'orbit-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/apps/orbit-docs'));

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['app']['name'])->toBe('orbit-docs');
    });

    it('fails validation when no app can be resolved', function (): void {
        [$exitCode, $output] = runCommand($this, 'app:show', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('app');
    });

    it('renders human output containing app fields', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'orbit-docs', 'node' => 'app-1'],
            'details' => ['domain' => 'orbit-docs.test'],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:show', ['app' => 'orbit-docs']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('app');
    });

    it('surfaces gateway_unavailable on gateway HTTP errors', function (): void {
        fakeGateway(fakeErrorEnvelope('app.not_found', 'App not found.'), 404);

        [$exitCode, $output] = runCommand($this, 'app:show', [
            'app' => 'missing-app',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('No route to host');

        [$exitCode, $output] = runCommand($this, 'app:show', [
            'app' => 'orbit-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});
