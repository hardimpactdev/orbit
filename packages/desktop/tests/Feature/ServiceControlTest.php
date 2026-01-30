<?php

namespace Tests\Feature;

use HardImpact\Orbit\Models\Environment;
use HardImpact\Orbit\Models\SshKey;
use HardImpact\Orbit\Services\OrbitCli\ServiceControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ServiceControlTest extends TestCase
{
    use RefreshDatabase;

    protected Environment $environment;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a default SSH key
        SshKey::create([
            'name' => 'Default Key',
            'public_key' => 'ssh-rsa AAA...',
            'private_key' => '---BEGIN...',
            'is_default' => true,
        ]);

        // Create an environment
        $this->environment = Environment::create([
            'name' => 'Test Server',
            'host' => '1.2.3.4',
            'user' => 'orbit',
            'port' => 22,
            'is_local' => false,
            'status' => 'active',
        ]);
    }

    public function test_can_start_host_service(): void
    {
        $this->mock(ServiceControlService::class, function (MockInterface $mock) {
            $mock->shouldReceive('startHostService')
                ->with(\Mockery::on(fn ($e) => $e->id === $this->environment->id), 'caddy')
                ->once()
                ->andReturn(['success' => true]);
        });

        $response = $this->post(route('environments.host-services.start', [
            'environment' => $this->environment,
            'service' => 'caddy',
        ]));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_can_stop_host_service(): void
    {
        $this->mock(ServiceControlService::class, function (MockInterface $mock) {
            $mock->shouldReceive('stopHostService')
                ->with(\Mockery::on(fn ($e) => $e->id === $this->environment->id), 'php-8.4')
                ->once()
                ->andReturn(['success' => true]);
        });

        $response = $this->post(route('environments.host-services.stop', [
            'environment' => $this->environment,
            'service' => 'php-8.4',
        ]));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_can_restart_host_service(): void
    {
        $this->mock(ServiceControlService::class, function (MockInterface $mock) {
            $mock->shouldReceive('restartHostService')
                ->with(\Mockery::on(fn ($e) => $e->id === $this->environment->id), 'horizon')
                ->once()
                ->andReturn(['success' => true]);
        });

        $response = $this->post(route('environments.host-services.restart', [
            'environment' => $this->environment,
            'service' => 'horizon',
        ]));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_can_disable_service(): void
    {
        $this->mock(ServiceControlService::class, function (MockInterface $mock) {
            $mock->shouldReceive('disable')
                ->with(\Mockery::on(fn ($e) => $e->id === $this->environment->id), 'mysql')
                ->once()
                ->andReturn(['success' => true]);
        });

        $response = $this->delete(route('environments.services.disable', [
            'environment' => $this->environment,
            'service' => 'mysql',
        ]));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_dynamic_php_versions_in_config(): void
    {
        // Mock getConfig to return available_php_versions
        $this->mock(\HardImpact\Orbit\Services\OrbitCli\ConfigurationService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getConfig')
                ->with(\Mockery::on(fn ($e) => $e->id === $this->environment->id))
                ->once()
                ->andReturn([
                    'success' => true,
                    'data' => [
                        'available_php_versions' => ['8.3', '8.4', '8.5', '8.6'],
                        'tld' => 'test',
                    ],
                ]);
        });

        $response = $this->get("/api/environments/{$this->environment->id}/config");

        $response->assertStatus(200)
            ->assertJsonPath('data.available_php_versions', ['8.3', '8.4', '8.5', '8.6']);
    }
}
