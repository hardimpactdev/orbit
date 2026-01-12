<?php

use App\Models\Environment;
use App\Services\LaunchpadCli\ConfigurationService;
use App\Services\LaunchpadCli\ProjectService;

beforeEach(function () {
    createEnvironment();
});

test('provision status endpoint returns not found for unknown project', function () {
    $environment = Environment::first();

    $this->mock(ProjectService::class, function ($mock) {
        $mock->shouldReceive('provisionStatus')
            ->with(Mockery::type(Environment::class), 'unknown-project')
            ->andReturn([
                'success' => true,
                'data' => [
                    'status' => 'not_found',
                    'error' => null,
                ],
            ]);
    });

    $response = $this->get("/environments/{$environment->id}/projects/unknown-project/provision-status");

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'data' => [
            'status' => 'not_found',
        ],
    ]);
});

test('provision status endpoint returns provisioning status', function () {
    $environment = Environment::first();

    $this->mock(ProjectService::class, function ($mock) {
        $mock->shouldReceive('provisionStatus')
            ->with(Mockery::type(Environment::class), 'my-project')
            ->andReturn([
                'success' => true,
                'data' => [
                    'status' => 'cloning',
                    'error' => null,
                ],
            ]);
    });

    $response = $this->get("/environments/{$environment->id}/projects/my-project/provision-status");

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'data' => [
            'status' => 'cloning',
        ],
    ]);
});

test('provision status endpoint returns ready when complete', function () {
    $environment = Environment::first();

    $this->mock(ProjectService::class, function ($mock) {
        $mock->shouldReceive('provisionStatus')
            ->with(Mockery::type(Environment::class), 'my-project')
            ->andReturn([
                'success' => true,
                'data' => [
                    'status' => 'ready',
                    'error' => null,
                ],
            ]);
    });

    $response = $this->get("/environments/{$environment->id}/projects/my-project/provision-status");

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'data' => [
            'status' => 'ready',
        ],
    ]);
});

test('provision status endpoint returns failed with error', function () {
    $environment = Environment::first();

    $this->mock(ProjectService::class, function ($mock) {
        $mock->shouldReceive('provisionStatus')
            ->with(Mockery::type(Environment::class), 'my-project')
            ->andReturn([
                'success' => true,
                'data' => [
                    'status' => 'failed',
                    'error' => 'Failed to clone repository',
                ],
            ]);
    });

    $response = $this->get("/environments/{$environment->id}/projects/my-project/provision-status");

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'data' => [
            'status' => 'failed',
            'error' => 'Failed to clone repository',
        ],
    ]);
});

test('reverb config endpoint returns config when enabled', function () {
    $environment = Environment::first();

    $this->mock(ConfigurationService::class, function ($mock) {
        $mock->shouldReceive('getReverbConfig')
            ->with(Mockery::type(Environment::class))
            ->andReturn([
                'success' => true,
                'data' => [
                    'enabled' => true,
                    'host' => 'reverb.ccc',
                    'port' => 443,
                    'scheme' => 'https',
                    'app_key' => 'launchpad-key',
                ],
            ]);
    });

    $response = $this->get("/environments/{$environment->id}/reverb-config");

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'data' => [
            'enabled' => true,
            'host' => 'reverb.ccc',
            'port' => 443,
        ],
    ]);
});

test('reverb config endpoint returns disabled when not configured', function () {
    $environment = Environment::first();

    $this->mock(ConfigurationService::class, function ($mock) {
        $mock->shouldReceive('getReverbConfig')
            ->with(Mockery::type(Environment::class))
            ->andReturn([
                'success' => true,
                'data' => [
                    'enabled' => false,
                ],
            ]);
    });

    $response = $this->get("/environments/{$environment->id}/reverb-config");

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'data' => [
            'enabled' => false,
        ],
    ]);
});

test('create project page loads', function () {
    $environment = Environment::first();

    $response = $this->get("/environments/{$environment->id}/projects/create");

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('environments/projects/Create'));
});

test('projects page loads', function () {
    $environment = Environment::first();

    $response = $this->get("/environments/{$environment->id}/projects");

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('environments/Projects'));
});

test('projects page includes provisioning slug from flash', function () {
    $environment = Environment::first();

    $response = $this->withSession(['flash' => ['provisioning' => 'my-new-project']])
        ->get("/environments/{$environment->id}/projects");

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('environments/Projects'));
});
