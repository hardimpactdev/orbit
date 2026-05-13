<?php

declare(strict_types=1);

use App\Data\Vpn\VpnBackendClient;
use App\Models\Node;
use App\Services\Vpn\ArrayVpnBackend;
use App\Services\Vpn\VpnBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('logs vpn api activity with safe metadata', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active',
    ]);

    app()->instance(VpnBackend::class, new ArrayVpnBackend([
        new VpnBackendClient('client-1', 'laptop', '10.6.0.7', true, null),
    ]));

    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.6.0.2'])
        ->getJson('/api/vpn/clients?totp=123456')
        ->assertSuccessful()
        ->assertJsonPath('success.meta.count', 1);

    $entry = Activity::query()->first();

    expect($entry)->not->toBeNull();
    expect($entry->event)->toBe('api:GET /api/vpn/clients');
    expect($entry->properties->get('type'))->toBe('read');
    expect($entry->properties->get('method'))->toBe('GET');
    expect($entry->properties->get('path'))->toBe('api/vpn/clients');
    expect(json_encode($entry->properties->toArray(), JSON_THROW_ON_ERROR))->not->toContain('123456');
});
