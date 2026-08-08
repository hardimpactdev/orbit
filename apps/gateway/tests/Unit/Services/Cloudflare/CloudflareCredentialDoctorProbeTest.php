<?php

declare(strict_types=1);

use App\Enums\DriftKind;
use App\Models\GatewayExtension;
use App\Models\Node;
use App\Services\Cloudflare\CloudflareCredentialDoctorProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('orbit.cloudflare.api_token', 'test-token');
    config()->set('orbit.cloudflare.api_email', null);
    Http::preventStrayRequests();
});

afterEach(function (): void {
    Http::allowStrayRequests();
});

function cloudflareProbeNode(): Node
{
    return Node::factory()->create(['name' => 'gateway-1', 'status' => 'active']);
}

function enableCloudflareGatewayExtension(bool $enabled = true): void
{
    GatewayExtension::query()->updateOrCreate(
        ['slug' => 'cloudflare'],
        ['enabled' => $enabled, 'enabled_at' => $enabled ? now() : null],
    );
}

function fakeCloudflareZones(array $response, int $status = 200): void
{
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones*' => Http::response($response, $status),
    ]);
}

it('stays silent while the cloudflare gateway extension is disabled', function (): void {
    enableCloudflareGatewayExtension(false);

    expect(app(CloudflareCredentialDoctorProbe::class)->shouldProbe())
        ->toBeFalse()
        ->and(app(CloudflareCredentialDoctorProbe::class)->drift(cloudflareProbeNode()))
        ->toBe([]);

    Http::assertNothingSent();
});

it('reports a missing token once cloudflare is enabled', function (): void {
    enableCloudflareGatewayExtension();
    config()->set('orbit.cloudflare.api_token', null);

    $drift = app(CloudflareCredentialDoctorProbe::class)->drift(cloudflareProbeNode());

    expect($drift)
        ->toHaveCount(1)
        ->and($drift[0]->key)
        ->toBe('tool.cloudflare.credentials_missing')
        ->and($drift[0]->family)
        ->toBe('tool')
        ->and($drift[0]->kind)
        ->toBe(DriftKind::Missing)
        ->and($drift[0]->detail['env_var'])
        ->toBe('CLOUDFLARE_API_TOKEN')
        ->and($drift[0]->detail['required_scopes'])
        ->toContain('Zone:Read')
        ->and($drift[0]->detail['remediation'])
        ->toContain('CLOUDFLARE_API_TOKEN');

    Http::assertNothingSent();
});

// The live failure: a stored token the provider rejects, which used to leave
// doctor reporting a healthy fleet with every cf-* command broken.
it('reports a rejected token as tool-family drift', function (): void {
    enableCloudflareGatewayExtension();
    fakeCloudflareZones([
        'success' => false,
        'errors' => [['code' => 10000, 'message' => 'Invalid access token']],
    ], 403);

    $drift = app(CloudflareCredentialDoctorProbe::class)->drift(cloudflareProbeNode());

    expect($drift)
        ->toHaveCount(1)
        ->and($drift[0]->key)
        ->toBe('tool.cloudflare.token_rejected')
        ->and($drift[0]->kind)
        ->toBe(DriftKind::Divergent)
        ->and($drift[0]->detail['reason'])
        ->toBe('token_rejected')
        ->and($drift[0]->detail['provider_message'])
        ->toContain('Invalid access token')
        ->and($drift[0]->detail['remediation'])
        ->toContain('Rotate CLOUDFLARE_API_TOKEN');
});

it('reports nothing when the token verifies', function (): void {
    enableCloudflareGatewayExtension();
    fakeCloudflareZones(['success' => true, 'result' => [['id' => 'zone-1', 'name' => 'example.com']]]);

    expect(app(CloudflareCredentialDoctorProbe::class)->drift(cloudflareProbeNode()))->toBe([]);
});

// A provider outage is not a credential fault and must not be reported as one.
it('reports a provider outage as unverifiable rather than a credential fault', function (): void {
    enableCloudflareGatewayExtension();
    fakeCloudflareZones(['success' => false, 'errors' => [['message' => 'Internal error']]], 500);

    $drift = app(CloudflareCredentialDoctorProbe::class)->drift(cloudflareProbeNode());

    expect($drift)
        ->toHaveCount(1)
        ->and($drift[0]->key)
        ->toBe('tool.cloudflare.credentials_probe_failed')
        ->and($drift[0]->kind)
        ->toBe(DriftKind::Unverifiable)
        ->and($drift[0]->detail)
        ->not->toHaveKey('remediation');
});
