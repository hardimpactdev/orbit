<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;

beforeEach(function (): void {
    // The realtime routes are Gateway-scoped, so the caller is the gateway peer.
    $this->node = $this->markAsGateway(Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
});

describe('GET /api/v1/realtime', function (): void {
    it('returns the Reverb connection details from the active websocket role', function (): void {
        [, $credentials] = activate_websocket_role();

        $this->getJson('/api/v1/realtime')
            ->assertOk()
            ->assertJsonPath('data.url', 'wss://reverb.orbit')
            ->assertJsonPath('data.key', $credentials->appKey)
            ->assertJsonPath('data.channel', 'orbit')
            ->assertJsonStructure(['meta' => ['request_id']]);
    });

    it('returns null connection details when no websocket role is active', function (): void {
        $this->getJson('/api/v1/realtime')
            ->assertOk()
            ->assertJsonPath('data.url', null)
            ->assertJsonPath('data.key', null)
            ->assertJsonPath('data.channel', 'orbit');
    });

    it('keeps config and auth available when only private DNS publication failed', function (): void {
        [$server, $credentials] = activate_websocket_role();
        $server->roles()->update(['status' => LifecycleStatus::Failed, 'failed_step' => 'converge:private-dns', 'error_code' => 'app-dev.dns_config_failed']);

        $this->getJson('/api/v1/realtime')->assertOk()
            ->assertJsonPath('data.url', 'wss://reverb.orbit')->assertJsonPath('data.key', $credentials->appKey);
        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-orbit'])
            ->assertOk()->assertJsonPath('auth', $credentials->appKey.':'.hash_hmac('sha256', '1.1:private-orbit', $credentials->appSecret));
    });

    it('never leaks the Reverb secret or the generated APP_KEY', function (): void {
        activate_websocket_role();

        $response = $this->getJson('/api/v1/realtime')->assertOk();

        expect($response->getContent())
            ->not->toContain('REVERB_APP_SECRET')
            ->not->toContain('base64:');
    });

    it('refuses an unauthenticated caller with 403', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

        $this->getJson('/api/v1/realtime')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'peer.identity_unknown');
    });
});
