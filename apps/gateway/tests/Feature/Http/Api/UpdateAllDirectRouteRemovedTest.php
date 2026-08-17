<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

const UPDATE_ALL_DIRECT_ROUTE_CALLER_WG_IP = '10.6.0.99';

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        'docker run *' => Process::result(output: "runner\n"),
    ]);

    createTestGatewayNode([
        'name' => 'gateway',
        'host' => 'gateway',
        'orbit_path' => '/home/gateway/orbit',
        'status' => 'active',
        'wireguard_address' => UPDATE_ALL_DIRECT_ROUTE_CALLER_WG_IP,
    ]);
});

it('does not expose the retired direct POST /api/update/all lane', function (): void {
    $this
        ->call('POST', '/api/update/all', server: ['REMOTE_ADDR' => UPDATE_ALL_DIRECT_ROUTE_CALLER_WG_IP])
        ->assertNotFound();
});

it('keeps the durable POST /api/update/all/start lane', function (): void {
    expect(Route::has('api.update.all.start'))->toBeTrue();

    $this
        ->call('POST', '/api/update/all/start', server: ['REMOTE_ADDR' => UPDATE_ALL_DIRECT_ROUTE_CALLER_WG_IP])
        ->assertAccepted()
        ->assertJsonPath('success.data.operation_run.type', 'update:all');
});
