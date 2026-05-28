<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('app:list', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards filters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'apps' => [
                ['name' => 'orbit-docs', 'node' => 'app-1', 'environment' => 'development'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:list', [
            '--node' => 'app-1',
            '--environment' => 'development',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/apps')
            && str_contains($request->url(), 'node=app-1')
            && str_contains($request->url(), 'environment=development'));

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['apps'][0]['name'])->toBe('orbit-docs');
    });

    it('renders human output containing app fields', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'apps' => [
                ['name' => 'orbit-docs', 'node' => 'app-1'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:list');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('apps');
    });

    it('surfaces gateway_unavailable on gateway HTTP errors', function (): void {
        fakeGateway(fakeErrorEnvelope('internal_error', 'Server failure.'), 500);

        [$exitCode, $output] = runCommand($this, 'app:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('Operation timed out');

        [$exitCode, $output] = runCommand($this, 'app:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});
