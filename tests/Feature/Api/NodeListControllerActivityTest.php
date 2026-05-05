<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const NODE_ACTIVITY_WG_IP = '10.6.0.99';

describe('NodeListController activity logging', function (): void {
    beforeEach(function (): void {
        DB::table('nodes')->insert([
            'name' => 'caller',
            'role' => 'control',
            'host' => NODE_ACTIVITY_WG_IP,
            'ssh_user' => 'test',
            'orbit_path' => '/home/test/orbit',
            'status' => 'active',
            'is_local' => false,
            'wireguard_address' => NODE_ACTIVITY_WG_IP,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nodes')->insert([
            'name' => 'gw',
            'role' => 'gateway',
            'host' => '10.6.0.1',
            'ssh_user' => 'test',
            'orbit_path' => '/home/test/orbit',
            'status' => 'active',
            'is_local' => true,
            'wireguard_address' => '10.6.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    it('logs activity when a Loggable controller handles a request', function (): void {
        $response = $this->call('GET', '/api/nodes', [], [], [], ['REMOTE_ADDR' => NODE_ACTIVITY_WG_IP]);

        $response->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->log_name)->toBe('api');
        expect($entry->event)->toBe('api:GET /nodes');
        expect($entry->properties->get('type'))->toBe('read');
        expect($entry->properties->get('method'))->toBe('GET');
        expect($entry->properties->get('path'))->toBe('api/nodes');
    });

    it('preserves X-Orbit-Request-Id in batch_uuid for logged activity', function (): void {
        $requestId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        $response = $this->call('GET', '/api/nodes', [], [], [], [
            'REMOTE_ADDR' => NODE_ACTIVITY_WG_IP,
            'HTTP_X_ORBIT_REQUEST_ID' => $requestId,
        ]);

        $response->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->batch_uuid)->toBe($requestId);
    });
});
