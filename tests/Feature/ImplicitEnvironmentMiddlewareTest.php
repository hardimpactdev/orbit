<?php

use HardImpact\Orbit\Models\Environment;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Config::set('orbit.multi_environment', false);
    
    Route::middleware([\HardImpact\Orbit\Http\Middleware\ImplicitEnvironment::class])
        ->get('/test-middleware/{environment?}', function (Environment $environment) {
            return response()->json(['id' => $environment->id]);
        });
});

test('it injects local environment when multi_environment is false', function () {
    $environment = createEnvironment(['is_local' => true]);

    $response = $this->get('/test-middleware');

    $response->assertStatus(200);
    $response->assertJson(['id' => $environment->id]);
});

test('it does not inject when multi_environment is true', function () {
    Config::set('orbit.multi_environment', true);
    createEnvironment(['is_local' => true]);

    $response = $this->getJson('/test-middleware');
    
    // When not injected and not in URL, id should be null
    $response->assertJson(['id' => null]);
});

test('it aborts 500 when no local environment exists and multi_environment is false', function () {
    $response = $this->getJson('/test-middleware');

    $response->assertStatus(500);
    $response->assertJson(['message' => 'No environment found. Run: php artisan orbit:init']);
});

test('it warns if multiple local environments exist', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with('Multiple is_local=true environments found (2). Using first.');

    createEnvironment(['is_local' => true, 'name' => 'Env 1']);
    createEnvironment(['is_local' => true, 'name' => 'Env 2']);

    $response = $this->get('/test-middleware');

    $response->assertStatus(200);
});
