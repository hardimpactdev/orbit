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

    it('renders human output as a node table', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'nodes' => [
                [
                    'name' => 'app-1',
                    'host' => '203.0.113.10',
                    'addresses' => ['wireguard' => '10.6.0.4'],
                    'platform' => 'ubuntu_24-04',
                    'status' => 'active',
                    'roles' => [
                        ['role' => 'app-dev', 'status' => 'active'],
                        ['role' => 'database', 'status' => 'error'],
                    ],
                ],
                [
                    'name' => 'operator-1',
                    'host' => '198.51.100.20',
                    'addresses' => ['wireguard' => '10.6.0.3'],
                    'platform' => 'ubuntu',
                    'status' => 'inactive',
                    'roles' => [],
                ],
                [
                    'name' => 'gateway',
                    'host' => '188.245.156.201',
                    'addresses' => ['wireguard' => '10.6.0.2'],
                    'platform' => 'ubuntu',
                    'status' => 'active',
                    'roles' => [
                        ['role' => 'gateway', 'status' => 'active'],
                        ['role' => 'router', 'status' => 'active'],
                        ['role' => 'vpn', 'status' => 'active'],
                    ],
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'node:list');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('ROLES')
            ->and($output)->toContain('NAME')
            ->and($output)->toContain('PEER IP')
            ->and($output)->toContain('PLATFORM')
            ->and($output)->not->toContain('STATUS')
            ->and($output)->toContain('●')
            ->and($output)->toContain('app-dev, database (error)')
            ->and($output)->toContain('app-1')
            ->and($output)->toContain('10.6.0.4')
            ->and($output)->not->toContain('203.0.113.10')
            ->and($output)->toContain('ubuntu')
            ->and($output)->toContain('operator-1')
            ->and($output)->toContain('10.6.0.3')
            ->and($output)->not->toContain('198.51.100.20')
            ->and($output)->toContain('gateway, vpn, router')
            ->and($output)->not->toContain('188.245.156.201')
            ->and($output)->not->toContain('nodes: [');
    });

    it('does not render a bootstrap host as peer ip when the WireGuard address is missing', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'nodes' => [
                [
                    'name' => 'app-1',
                    'host' => '203.0.113.10',
                    'addresses' => ['wireguard' => null],
                    'platform' => 'ubuntu',
                    'status' => 'active',
                    'roles' => [],
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'node:list');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('app-1')
            ->and($output)->toContain('unknown')
            ->and($output)->not->toContain('203.0.113.10');
    });

    it('renders human empty output when no nodes are visible', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'nodes' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'node:list');

        expect($exitCode)->toBe(0)
            ->and($output)->toBe('No nodes found.');
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
