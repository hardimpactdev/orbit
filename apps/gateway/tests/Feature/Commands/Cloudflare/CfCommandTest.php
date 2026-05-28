<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Cloudflare\CloudflareRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

const CF_TEST_ZONE_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

beforeEach(function (): void {
    config()->set('orbit.cloudflare.api_token', 'test-token');
    config()->set('orbit.cloudflare.api_email', null);

    Http::preventStrayRequests();
});

afterEach(function (): void {
    Http::allowStrayRequests();
    MockClient::destroyGlobal();
});

function createCloudflareLocalNode(string $role = 'gateway'): Node
{
    $node = Node::factory()->create([
        'name' => "local-{$role}",
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'platform' => 'ubuntu',
    ]);

    if ($role === 'gateway') {
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'gateway',
            'status' => 'active',
            'settings' => [],
        ]);
    }

    return $node;
}

/**
 * @return array<string, mixed>
 */
function cfJsonPayload(): array
{
    return json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
}

function cfZoneListResponse(): array
{
    return [
        'success' => true,
        'result' => [
            [
                'id' => CF_TEST_ZONE_ID,
                'name' => 'lindaretel.nl',
                'status' => 'active',
            ],
        ],
    ];
}

it('lists Cloudflare zones from a gateway caller', function (): void {
    createCloudflareLocalNode();

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones*' => Http::response(cfZoneListResponse()),
    ]);

    $exitCode = Artisan::call('cf-zone:list', ['--json' => true]);
    $payload = cfJsonPayload();

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['zones'][0])->toMatchArray([
            'id' => CF_TEST_ZONE_ID,
            'name' => 'lindaretel.nl',
            'status' => 'active',
        ])
        ->and($payload['success']['meta']['count'])->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-token'));
});

it('fails non-gateway callers without configured gateway settings before provider requests', function (): void {
    config(['orbit.is_gateway' => false]);

    createCloudflareLocalNode('app');

    $exitCode = Artisan::call('cf-zone:list', ['--json' => true]);
    $payload = cfJsonPayload();

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('gateway_unavailable');

    Http::assertNothingSent();
});

it('forwards control callers through the gateway API', function (): void {
    config(['orbit.is_gateway' => false]);

    createCloudflareLocalNode('control');
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    MockClient::global([
        CloudflareRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'zones' => [
                        [
                            'id' => CF_TEST_ZONE_ID,
                            'name' => 'lindaretel.nl',
                            'status' => 'active',
                        ],
                    ],
                ],
                'meta' => ['count' => 1],
            ],
        ], 200),
    ]);

    $exitCode = Artisan::call('cf-zone:list', ['--json' => true]);
    $payload = cfJsonPayload();

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['zones'][0]['name'])->toBe('lindaretel.nl');

    Http::assertNothingSent();
});

it('reports missing gateway Cloudflare credentials without provider requests', function (): void {
    createCloudflareLocalNode();
    config()->set('orbit.cloudflare.api_token', null);

    $exitCode = Artisan::call('cf-zone:list', ['--json' => true]);
    $payload = cfJsonPayload();

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('cloudflare_unavailable')
        ->and($payload['error']['meta']['reason'])->toBe('token_missing');

    Http::assertNothingSent();
});

it('lists DNS records for a resolved zone', function (): void {
    createCloudflareLocalNode();

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/'.CF_TEST_ZONE_ID.'/dns_records*' => Http::response([
            'success' => true,
            'result' => [
                [
                    'id' => 'record-1',
                    'type' => 'A',
                    'name' => 'lindaretel.nl',
                    'content' => '46.225.89.66',
                    'proxied' => true,
                ],
            ],
        ]),
        'https://api.cloudflare.com/client/v4/zones*' => Http::response(cfZoneListResponse()),
    ]);

    $exitCode = Artisan::call('cf-dns:list', ['zone' => 'lindaretel.nl', '--json' => true]);
    $payload = cfJsonPayload();

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['records'][0])->toMatchArray([
            'id' => 'record-1',
            'zone' => 'lindaretel.nl',
            'type' => 'A',
            'name' => 'lindaretel.nl',
            'content' => '46.225.89.66',
            'proxied' => true,
            'status' => 'observed',
        ])
        ->and($payload['success']['meta'])->toMatchArray([
            'zone' => 'lindaretel.nl',
            'count' => 1,
        ]);
});

it('adds an address DNS record after conflict checks', function (): void {
    createCloudflareLocalNode();

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, '/client/v4/zones') && ! str_contains($url, '/dns_records')) {
            return Http::response(cfZoneListResponse());
        }

        if ($request->method() === 'GET' && str_contains($url, '/dns_records')) {
            return Http::response(['success' => true, 'result' => []]);
        }

        if ($request->method() === 'POST' && str_contains($url, '/dns_records')) {
            return Http::response([
                'success' => true,
                'result' => [
                    'id' => 'record-new',
                    'type' => 'A',
                    'name' => 'lindaretel.nl',
                    'content' => '46.225.89.66',
                    'proxied' => true,
                ],
            ]);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'Unexpected request']]], 500);
    });

    $exitCode = Artisan::call('cf-dns:add', [
        'name' => 'lindaretel.nl',
        'content' => '46.225.89.66',
        '--zone' => 'lindaretel.nl',
        '--proxied' => true,
        '--json' => true,
    ]);
    $payload = cfJsonPayload();

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['record'])->toMatchArray([
            'id' => 'record-new',
            'zone' => 'lindaretel.nl',
            'type' => 'A',
            'name' => 'lindaretel.nl',
            'content' => '46.225.89.66',
            'proxied' => true,
            'status' => 'created',
        ])
        ->and($payload['success']['meta'])->toMatchArray([
            'action' => 'create',
            'already_present' => false,
        ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/dns_records')
        && $request['proxied'] === true);
});

it('requires non-interactive consent before removing DNS records', function (): void {
    createCloudflareLocalNode();

    $exitCode = Artisan::call('cf-dns:remove', [
        'record-id' => 'record-1',
        '--zone' => 'lindaretel.nl',
        '--json' => true,
    ]);
    $payload = cfJsonPayload();

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta'])->toMatchArray([
            'field' => 'force',
            'reason' => 'destructive_consent_required',
        ]);

    Http::assertNothingSent();
});

it('removes address DNS records with force', function (): void {
    createCloudflareLocalNode();

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/'.CF_TEST_ZONE_ID.'/dns_records/record-1' => Http::sequence()
            ->push([
                'success' => true,
                'result' => [
                    'id' => 'record-1',
                    'type' => 'A',
                    'name' => 'lindaretel.nl',
                    'content' => '46.225.89.66',
                    'proxied' => true,
                ],
            ])
            ->push(['success' => true, 'result' => ['id' => 'record-1']]),
        'https://api.cloudflare.com/client/v4/zones*' => Http::response(cfZoneListResponse()),
    ]);

    $exitCode = Artisan::call('cf-dns:remove', [
        'record-id' => 'record-1',
        '--zone' => 'lindaretel.nl',
        '--force' => true,
        '--json' => true,
    ]);
    $payload = cfJsonPayload();

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['record'])->toMatchArray([
            'id' => 'record-1',
            'zone' => 'lindaretel.nl',
            'type' => 'A',
            'status' => 'removed',
        ])
        ->and($payload['success']['meta']['action'])->toBe('remove');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/dns_records/record-1'));
});

it('flushes cache, manages cache rules, and updates SSL settings', function (): void {
    createCloudflareLocalNode();
    $node = Node::factory()->appDev()->create(['name' => 'app-1', 'platform' => 'ubuntu']);
    App::factory()->create([
        'name' => 'lindaretel',
        'node_id' => $node->id,
        'domain' => 'lindaretel.nl',
    ]);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/'.CF_TEST_ZONE_ID.'/purge_cache' => Http::response(['success' => true, 'result' => ['id' => 'purge-1']]),
        'https://api.cloudflare.com/client/v4/zones/'.CF_TEST_ZONE_ID.'/rulesets' => Http::sequence()
            ->push(['success' => true, 'result' => []])
            ->push(['success' => true, 'result' => ['id' => 'ruleset-1']])
            ->push(['success' => true, 'result' => [['id' => 'ruleset-1', 'phase' => 'http_request_cache_settings']]]),
        'https://api.cloudflare.com/client/v4/zones/'.CF_TEST_ZONE_ID.'/rulesets/ruleset-1' => Http::response(['success' => true, 'result' => ['id' => 'ruleset-1']]),
        'https://api.cloudflare.com/client/v4/zones/'.CF_TEST_ZONE_ID.'/settings/ssl' => Http::response(['success' => true, 'result' => ['value' => 'strict']]),
        'https://api.cloudflare.com/client/v4/zones*' => Http::response(cfZoneListResponse()),
    ]);

    $flushExit = Artisan::call('cf-cache:flush', ['--zone' => 'lindaretel', '--json' => true]);
    $flushPayload = cfJsonPayload();

    $ruleAddExit = Artisan::call('cf-cache-rule:add', ['app' => 'lindaretel', '--json' => true]);
    $ruleAddPayload = cfJsonPayload();

    $ruleRemoveExit = Artisan::call('cf-cache-rule:remove', ['app' => 'lindaretel', '--force' => true, '--json' => true]);
    $ruleRemovePayload = cfJsonPayload();

    $sslExit = Artisan::call('cf-ssl:enable', ['zone' => 'lindaretel.nl', '--json' => true]);
    $sslPayload = cfJsonPayload();

    expect($flushExit)->toBe(0)
        ->and($flushPayload['success']['data']['cache'])->toMatchArray([
            'zone' => 'lindaretel.nl',
            'app' => 'lindaretel',
            'action' => 'flush',
            'status' => 'flushed',
        ])
        ->and($ruleAddExit)->toBe(0)
        ->and($ruleAddPayload['success']['data']['rule'])->toMatchArray([
            'app' => 'lindaretel',
            'zone' => 'lindaretel.nl',
            'action' => 'add',
            'cache' => true,
            'browser_ttl' => 'respect_origin',
            'status' => 'ready',
        ])
        ->and($ruleRemoveExit)->toBe(0)
        ->and($ruleRemovePayload['success']['data']['rule'])->toMatchArray([
            'app' => 'lindaretel',
            'zone' => 'lindaretel.nl',
            'action' => 'remove',
            'status' => 'removed',
        ])
        ->and($sslExit)->toBe(0)
        ->and($sslPayload['success']['data']['ssl'])->toMatchArray([
            'zone' => 'lindaretel.nl',
            'mode' => 'strict',
            'action' => 'enable',
            'status' => 'set',
        ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && str_contains($request->url(), '/settings/ssl')
        && $request['value'] === 'strict');
});

it('disables SSL only with explicit force', function (): void {
    createCloudflareLocalNode();

    $missingConsent = Artisan::call('cf-ssl:disable', [
        'zone' => 'lindaretel.nl',
        '--json' => true,
    ]);
    $error = cfJsonPayload();

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/'.CF_TEST_ZONE_ID.'/settings/ssl' => Http::response(['success' => true, 'result' => ['value' => 'off']]),
        'https://api.cloudflare.com/client/v4/zones*' => Http::response(cfZoneListResponse()),
    ]);

    $disabled = Artisan::call('cf-ssl:disable', [
        'zone' => 'lindaretel.nl',
        '--force' => true,
        '--json' => true,
    ]);
    $payload = cfJsonPayload();

    expect($missingConsent)->toBe(1)
        ->and($error['error']['code'])->toBe('validation_failed')
        ->and($error['error']['meta']['reason'])->toBe('destructive_consent_required')
        ->and($disabled)->toBe(0)
        ->and($payload['success']['data']['ssl'])->toMatchArray([
            'zone' => 'lindaretel.nl',
            'mode' => 'off',
            'action' => 'disable',
            'status' => 'set',
        ]);
});
