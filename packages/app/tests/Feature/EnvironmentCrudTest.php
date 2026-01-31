<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\StatusService;
use HardImpact\Orbit\Core\Services\SetupService;

beforeEach(function () {
    // Clean database before each test
    Environment::query()->delete();

    // Mock SetupService to skip setup redirect in tests
    $this->mock(SetupService::class, function ($mock) {
        $mock->shouldReceive('needsSetup')->andReturn(false);
        $mock->shouldReceive('getStatus')->andReturn([
            'needs_setup' => false,
            'cli_installed' => true,
            'cli_version' => '1.0.0',
            'has_local_environment' => true,
            'has_services' => true,
            'has_tld' => true,
            'steps' => [],
        ]);
    });

    // Mock the StatusService to avoid actual CLI calls
    $this->mock(StatusService::class, function ($mock) {
        $mock->shouldReceive('checkInstallation')->andReturn([
            'success' => true,
            'installed' => true,
            'version' => '1.0.0',
        ]);
        $mock->shouldReceive('status')->andReturn([
            'success' => true,
            'data' => [
                'services' => [],
                'projects' => [],
            ],
        ]);
    });

    // Mock ConfigurationService
    $this->mock(ConfigurationService::class, function ($mock) {
        $mock->shouldReceive('getConfig')->andReturn([
            'success' => true,
            'data' => ['tld' => 'test'],
        ]);
        $mock->shouldReceive('getReverbConfig')->andReturn([
            'success' => true,
            'enabled' => false,
        ]);
    });
});

describe('index', function () {
    it('redirects to single environment when only one exists', function () {
        $environment = Environment::factory()->create();

        $response = $this->get(route('environments.index'));

        $response->assertRedirect(route('environments.show', $environment));
    });

    it('shows index page when multiple environments exist', function () {
        Environment::factory()->count(2)->create();

        $response = $this->get(route('environments.index'));

        $response->assertInertia(fn ($page) => $page->component('environments/Index'));
    });

    it('auto-creates local environment in desktop mode when none exist', function () {
        config()->set('orbit.mode', 'desktop');

        expect(Environment::count())->toBe(0);

        $response = $this->get(route('environments.index'));

        expect(Environment::count())->toBe(1);
        $environment = Environment::first();
        expect($environment->is_local)->toBeTrue();
        expect($environment->name)->toBe('Local');
        $response->assertRedirect(route('environments.show', $environment));
    });
});

describe('create', function () {
    it('shows create environment form', function () {
        $response = $this->get(route('environments.create'));

        $response->assertInertia(fn ($page) => $page
            ->component('environments/Create')
            ->has('currentUser')
            ->has('hasLocalEnvironment')
        );
    });

    it('indicates when local environment already exists', function () {
        Environment::factory()->local()->create();

        $response = $this->get(route('environments.create'));

        $response->assertInertia(fn ($page) => $page
            ->where('hasLocalEnvironment', true)
        );
    });
});

describe('store', function () {
    it('creates a new remote environment', function () {
        $data = [
            'name' => 'Production Server',
            'host' => '192.168.1.100',
            'user' => 'deploy',
            'port' => 22,
            'is_local' => false,
        ];

        $response = $this->post(route('environments.store'), $data);

        $response->assertRedirect();
        expect(Environment::where('name', 'Production Server')->exists())->toBeTrue();
    });

    it('creates a local environment when none exists', function () {
        $data = [
            'name' => 'Local',
            'host' => 'localhost',
            'user' => 'testuser',
            'port' => 22,
            'is_local' => true,
        ];

        $response = $this->post(route('environments.store'), $data);

        $response->assertRedirect();
        $environment = Environment::where('is_local', true)->first();
        expect($environment)->not->toBeNull();
        expect($environment->name)->toBe('Local');
    });

    it('prevents creating a second local environment', function () {
        Environment::factory()->local()->create();

        $data = [
            'name' => 'Another Local',
            'host' => 'localhost',
            'user' => 'testuser',
            'port' => 22,
            'is_local' => true,
        ];

        $response = $this->post(route('environments.store'), $data);

        $response->assertRedirect(route('environments.index'));
        expect(Environment::where('is_local', true)->count())->toBe(1);
    });

    it('validates required fields', function () {
        $response = $this->post(route('environments.store'), []);

        $response->assertSessionHasErrors(['name', 'host', 'user', 'port']);
    });

    it('validates port range', function () {
        $response = $this->post(route('environments.store'), [
            'name' => 'Test',
            'host' => 'test.com',
            'user' => 'user',
            'port' => 99999,
        ]);

        $response->assertSessionHasErrors(['port']);
    });
});

describe('show', function () {
    it('shows environment dashboard for active environment', function () {
        $environment = Environment::factory()->create();

        $response = $this->get(route('environments.show', $environment));

        $response->assertInertia(fn ($page) => $page
            ->component('environments/Show')
            ->has('environment')
            ->has('installation')
            ->has('editor')
        );
    });

    it('shows provisioning view for environment being provisioned', function () {
        $environment = Environment::factory()->provisioning()->create();

        $response = $this->get(route('environments.show', $environment));

        $response->assertInertia(fn ($page) => $page
            ->component('environments/Provisioning')
            ->has('environment')
        );
    });

    it('shows provisioning view for environment with error', function () {
        $environment = Environment::factory()->withError()->create();

        $response = $this->get(route('environments.show', $environment));

        $response->assertInertia(fn ($page) => $page
            ->component('environments/Provisioning')
            ->has('environment')
        );
    });
});

describe('edit', function () {
    it('shows edit form for environment', function () {
        $environment = Environment::factory()->create();

        $response = $this->get(route('environments.edit', $environment));

        $response->assertInertia(fn ($page) => $page
            ->component('environments/Edit')
            ->has('environment')
        );
    });
});

describe('update', function () {
    it('updates environment details', function () {
        $environment = Environment::factory()->create([
            'name' => 'Old Name',
        ]);

        $response = $this->put(route('environments.update', $environment), [
            'name' => 'New Name',
            'host' => $environment->host,
            'user' => $environment->user,
            'port' => $environment->port,
        ]);

        $response->assertRedirect(route('environments.index'));
        expect($environment->fresh()->name)->toBe('New Name');
    });

    it('prevents converting to local when local already exists', function () {
        Environment::factory()->local()->create();
        $environment = Environment::factory()->create(['is_local' => false]);

        $response = $this->put(route('environments.update', $environment), [
            'name' => $environment->name,
            'host' => $environment->host,
            'user' => $environment->user,
            'port' => $environment->port,
            'is_local' => true,
        ]);

        $response->assertRedirect(route('environments.edit', $environment));
        expect($environment->fresh()->is_local)->toBeFalse();
    });

    it('validates required fields on update', function () {
        $environment = Environment::factory()->create();

        $response = $this->put(route('environments.update', $environment), []);

        $response->assertSessionHasErrors(['name', 'host', 'user', 'port']);
    });
});

describe('destroy', function () {
    it('deletes an environment', function () {
        $environment = Environment::factory()->create();
        $id = $environment->id;

        $response = $this->delete(route('environments.destroy', $environment));

        $response->assertRedirect(route('environments.index'));
        expect(Environment::find($id))->toBeNull();
    });
});

describe('setDefault', function () {
    it('sets environment as default', function () {
        $environment1 = Environment::factory()->default()->create();
        $environment2 = Environment::factory()->create();

        $response = $this->post(route('environments.set-default', $environment2));

        $response->assertRedirect(route('environments.show', $environment2));
        expect($environment1->fresh()->is_default)->toBeFalse();
        expect($environment2->fresh()->is_default)->toBeTrue();
    });
});

describe('testConnection', function () {
    it('returns success for environment', function () {
        $environment = Environment::factory()->create();

        // API route: /api/environments/{environment}/test-connection
        $response = $this->post("/api/environments/{$environment->id}/test-connection");

        $response->assertJson(['success' => true]);
    });
});
