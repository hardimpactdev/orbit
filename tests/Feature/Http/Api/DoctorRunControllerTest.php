<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Platform\PlatformDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(PlatformDetector::class, new class extends PlatformDetector
    {
        public function detectLocal(): string
        {
            return 'linux';
        }
    });
});

const DOCTOR_RUN_CALLER_WG_IP = '10.6.0.95';

function createDoctorRunCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'role' => 'gateway',
        'host' => DOCTOR_RUN_CALLER_WG_IP,
        'wireguard_address' => DOCTOR_RUN_CALLER_WG_IP,
        'platform' => 'linux',
        'is_local' => true,
    ], $overrides));
}

describe('DoctorRunController', function (): void {
    it('runs verify mode and returns a doctor report', function (): void {
        createDoctorRunCallerNode();

        $response = $this->call('POST', '/api/doctor/run', [
            'families' => ['node'],
            'mode' => 'verify',
        ], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', true)
            ->assertJsonPath('success.data.doctor.scope.families', ['node']);
    });

    it('accepts the proxy family scope', function (): void {
        createDoctorRunCallerNode();

        $response = $this->call('POST', '/api/doctor/run', [
            'families' => ['proxy'],
            'mode' => 'verify',
        ], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', true)
            ->assertJsonPath('success.data.doctor.scope.families', ['proxy']);
    });

    it('rejects unauthenticated requests', function (): void {
        $response = $this->postJson('/api/doctor/run', ['families' => ['node']]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });
});
