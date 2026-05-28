<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('cf-zone:list', function (): void {
    it('returns a canonical success envelope in JSON mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'zones' => [
                [
                    'id' => 'zone-1',
                    'name' => 'example.com',
                    'status' => 'active',
                ],
            ],
        ], ['count' => 1]));

        [$exitCode, $output] = runCommand($this, 'cf-zone:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/cloudflare/zones'));

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['zones'][0]['name'])->toBe('example.com')
            ->and($decoded['success']['meta']['count'])->toBe(1);
    });

    it('renders human output containing zone fields', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'zones' => [
                [
                    'id' => 'zone-1',
                    'name' => 'example.com',
                    'status' => 'active',
                ],
            ],
        ], ['count' => 1]));

        [$exitCode, $output] = runCommand($this, 'cf-zone:list');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('zones')
            ->and($output)->toContain('example.com');
    });

    it('passes through Cloudflare gateway error codes', function (): void {
        fakeGateway(fakeErrorEnvelope('cloudflare_unavailable', 'Cloudflare is unavailable.', [
            'reason' => 'token_missing',
        ]), 503);

        [$exitCode, $output] = runCommand($this, 'cf-zone:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('cloudflare_unavailable')
            ->and($decoded['error']['meta']['reason'])->toBe('token_missing');
    });
});

describe('cf-dns:list', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards the zone path', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'records' => [
                [
                    'id' => 'record-1',
                    'zone' => 'example.com',
                    'type' => 'A',
                    'name' => 'docs.example.com',
                    'content' => '203.0.113.10',
                    'proxied' => true,
                    'status' => 'observed',
                ],
            ],
        ], [
            'zone' => 'example.com',
            'count' => 1,
        ]));

        [$exitCode, $output] = runCommand($this, 'cf-dns:list', [
            'zone' => 'example.com',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/cloudflare/zones/example.com/dns'));

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['records'][0]['id'])->toBe('record-1')
            ->and($decoded['success']['meta'])->toMatchArray([
                'zone' => 'example.com',
                'count' => 1,
            ]);
    });

    it('renders human output containing record fields', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'records' => [
                [
                    'id' => 'record-1',
                    'type' => 'A',
                    'name' => 'docs.example.com',
                    'content' => '203.0.113.10',
                ],
            ],
        ], ['zone' => 'example.com', 'count' => 1]));

        [$exitCode, $output] = runCommand($this, 'cf-dns:list', ['zone' => 'example.com']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('records')
            ->and($output)->toContain('docs.example.com');
    });

    it('requires the zone argument in JSON mode before calling the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope(['records' => []]));

        [$exitCode, $output] = runCommand($this, 'cf-dns:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('zone');
    });

    it('passes through authorization failures from the gateway', function (): void {
        fakeGateway(fakeErrorEnvelope('authorization_failed', 'Missing Cloudflare DNS permission.', [
            'missing_permission' => 'cf:dns:list',
        ]), 403);

        [$exitCode, $output] = runCommand($this, 'cf-dns:list', [
            'zone' => 'example.com',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('authorization_failed')
            ->and($decoded['error']['meta']['missing_permission'])->toBe('cf:dns:list');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('Network is unreachable');

        [$exitCode, $output] = runCommand($this, 'cf-dns:list', [
            'zone' => 'example.com',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});
