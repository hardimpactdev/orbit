<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('node:list', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards filters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'nodes' => [
                ['name' => 'app-1', 'role' => 'app-dev'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'node:list', [
            '--role' => 'app-dev',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/nodes')
            && str_contains($request->url(), 'role=app-dev')
            && ! str_contains($request->url(), 'environment='));

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['nodes'][0]['name'])->toBe('app-1');
    });

    it('renders human output containing node fields', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'nodes' => [
                ['name' => 'gateway-1', 'role' => 'gateway'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'node:list');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('nodes');
    });

    it('surfaces gateway_unavailable on gateway HTTP errors', function (): void {
        fakeGateway(['message' => 'Bad gateway'], 502);

        [$exitCode, $output] = runCommand($this, 'node:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });

    it('preserves structured gateway validation failures for retired filters', function (): void {
        fakeGateway(fakeErrorEnvelope('validation_failed', "Invalid value for --role: 'app'.", [
            'field' => 'role',
            'value' => 'app',
        ]), 422);

        [$exitCode, $output] = runCommand($this, 'node:list', [
            '--role' => 'app',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('role')
            ->and($decoded['error']['meta']['value'])->toBe('app');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('Operation timed out');

        [$exitCode, $output] = runCommand($this, 'node:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});
