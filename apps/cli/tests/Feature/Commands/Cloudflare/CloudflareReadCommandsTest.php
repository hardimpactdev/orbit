<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->cloudflareConfigPath = orbit_test_config_path(prefix: 'orbit-cloudflare-read-');
    unlink_orbit_test_file($this->cloudflareConfigPath);

    $store = new OrbitConfigStore(overridePath: $this->cloudflareConfigPath);
    $store->enableExtension('cloudflare');

    app()->instance(OrbitConfigStore::class, $store);
});

afterEach(function (): void {
    unlink_orbit_test_file($this->cloudflareConfigPath);
});

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

        [$exitCode, $output] = runCommand($this, command: 'cf-zone:list', params: ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/cloudflare/zones'),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['zones'][0]['name'])
            ->toBe('example.com')
            ->and($decoded['success']['meta']['count'])
            ->toBe(1);
    });

    it('renders human output as a table with uppercase headers and zone fields', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'zones' => [
                [
                    'id' => 'zone-1',
                    'name' => 'example.com',
                    'status' => 'active',
                ],
                [
                    'id' => 'zone-2',
                    'name' => 'pending.test',
                    'status' => null,
                ],
            ],
        ], ['count' => 2]));

        [$exitCode, $output] = runCommand($this, command: 'cf-zone:list');

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('ZONE ID')
            ->and($output)
            ->toContain('DOMAIN')
            ->and($output)
            ->toContain('STATUS')
            ->and($output)
            ->toContain('zone-1')
            ->and($output)
            ->toContain('example.com')
            ->and($output)
            ->toContain('active')
            ->and($output)
            ->toContain('pending.test')
            ->and($output)
            ->toContain('—')
            ->and($output)
            ->not->toContain('zones: [');
    });

    it('renders the documented empty state when no zones are visible', function (): void {
        fakeGateway(fakeSuccessEnvelope(['zones' => []], ['count' => 0]));

        [$exitCode, $output] = runCommand($this, command: 'cf-zone:list');

        expect($exitCode)->toBe(0)->and($output)->toBe('No Cloudflare zones found.');
    });

    it('passes through Cloudflare gateway error codes', function (): void {
        fakeGateway(fakeErrorEnvelope('cloudflare_unavailable', 'Cloudflare is unavailable.', [
            'reason' => 'token_missing',
        ]), 503);

        [$exitCode, $output] = runCommand($this, command: 'cf-zone:list', params: ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('cloudflare_unavailable')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('token_missing');
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

        [$exitCode, $output] = runCommand($this, command: 'cf-dns:list', params: [
            'zone' => 'example.com',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/cloudflare/zones/example.com/dns'),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['records'][0]['id'])
            ->toBe('record-1')
            ->and($decoded['success']['meta'])
            ->toMatchArray([
                'zone' => 'example.com',
                'count' => 1,
            ]);
    });

    it('renders human output as a table with uppercase headers and proxied yes/no', function (): void {
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
                [
                    'id' => 'record-2',
                    'zone' => 'example.com',
                    'type' => 'CNAME',
                    'name' => 'www.example.com',
                    'content' => 'example.com',
                    'proxied' => false,
                    'status' => 'observed',
                ],
            ],
        ], ['zone' => 'example.com', 'count' => 2]));

        [$exitCode, $output] = runCommand($this, command: 'cf-dns:list', params: ['zone' => 'example.com']);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('RECORD ID')
            ->and($output)
            ->toContain('TYPE')
            ->and($output)
            ->toContain('NAME')
            ->and($output)
            ->toContain('CONTENT')
            ->and($output)
            ->toContain('PROXIED')
            ->and($output)
            ->toContain('record-1')
            ->and($output)
            ->toContain('docs.example.com')
            ->and($output)
            ->toContain('203.0.113.10')
            ->and($output)
            ->toContain('yes')
            ->and($output)
            ->toContain('www.example.com')
            ->and($output)
            ->toContain('no')
            ->and($output)
            ->not->toContain('records: [');
    });

    it('renders the documented empty state with the requested zone when no records exist', function (): void {
        fakeGateway(fakeSuccessEnvelope(['records' => []], ['zone' => 'example.com', 'count' => 0]));

        [$exitCode, $output] = runCommand($this, command: 'cf-dns:list', params: ['zone' => 'example.com']);

        expect($exitCode)->toBe(0)->and($output)->toBe('No Cloudflare DNS records found for example.com.');
    });

    it('requires the zone argument in JSON mode before calling the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope(['records' => []]));

        [$exitCode, $output] = runCommand($this, command: 'cf-dns:list', params: ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('zone');
    });

    it('passes through authorization failures from the gateway', function (): void {
        fakeGateway(fakeErrorEnvelope('authorization_failed', 'Missing Cloudflare DNS permission.', [
            'missing_permission' => 'cf:dns:list',
        ]), 403);

        [$exitCode, $output] = runCommand($this, command: 'cf-dns:list', params: [
            'zone' => 'example.com',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('authorization_failed')
            ->and($decoded['error']['meta']['missing_permission'])
            ->toBe('cf:dns:list');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('Network is unreachable');

        [$exitCode, $output] = runCommand($this, command: 'cf-dns:list', params: [
            'zone' => 'example.com',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});
