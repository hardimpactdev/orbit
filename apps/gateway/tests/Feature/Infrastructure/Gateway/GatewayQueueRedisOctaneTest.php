<?php

declare(strict_types=1);

use App\Models\DatabaseConnection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Octane\CurrentApplication;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Listeners\CreateConfigurationSandbox;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    DatabaseConnection::query()->create(['slug' => 'gateway-queue', 'driver' => 'redis', 'host' => '10.45.0.1', 'port' => 6379]);
    config(['orbit.gateway_queue.connection' => 'gateway-queue']);
    $this->worker = app();
    expect($this->worker->resolved('redis'))->toBeFalse();
});

afterEach(function (): void {
    CurrentApplication::set($this->worker);
});

/** An Octane request: a clone of the worker's application with its own configuration copy. */
function octaneRequest(Application $worker): Application
{
    $sandbox = clone $worker;
    CurrentApplication::set($sandbox);
    (new CreateConfigurationSandbox)->handle((object) ['sandbox' => $sandbox]);

    return $sandbox;
}

function redisHost(Application $app, string $connection): string
{
    return $app->make('redis')->connection($connection)->client()->getConnection()->getParameters()->host;
}

it('configures Redis on the worker when Horizon resolves it there during a request', function (): void {
    $request = octaneRequest($this->worker);

    // Horizon's queue connector resolves `redis` on the worker application while the facades point at the request.
    $redis = $request->make('queue')->connection('redis')->getRedis();
    expect($redis)->toBe($this->worker->make('redis'))
        ->and(redisHost($this->worker, 'gateway'))->toBe('10.45.0.1')
        ->and(redisHost($this->worker, 'horizon'))->toBe('10.45.0.1')
        ->and(redisHost(octaneRequest($this->worker), 'gateway'))->toBe('10.45.0.1')
        ->and(redisHost($request, 'gateway'))->toBe('10.45.0.1');
});

it('configures the worker once when it starts, so every request copy has the host', function (): void {
    event(new WorkerStarting($this->worker));

    expect($this->worker['config']->get('database.redis.gateway.host'))->toBe('10.45.0.1')
        ->and($this->worker['config']->get('database.redis.horizon.host'))->toBe('10.45.0.1')
        ->and($this->worker['config']->get('database.redis.horizon.options.prefix'))->toBe($this->worker['config']->get('horizon.prefix'))
        ->and($this->worker->resolved('redis'))->toBeFalse()
        ->and(redisHost(octaneRequest($this->worker), 'gateway'))->toBe('10.45.0.1');
});
