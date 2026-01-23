<?php

declare(strict_types=1);

namespace Tests\Feature;

use HardImpact\Orbit\Models\Environment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrbitInitCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_local_environment(): void
    {
        $this->assertDatabaseCount('environments', 0);

        $this->artisan('orbit:init')
            ->assertExitCode(0);

        $this->assertDatabaseCount('environments', 1);
        $this->assertDatabaseHas('environments', [
            'is_local' => true,
            'name' => 'Local',
        ]);
    }

    public function test_idempotent_does_not_create_duplicate(): void
    {
        // Run twice
        $this->artisan('orbit:init')->assertExitCode(0);
        $this->artisan('orbit:init')->assertExitCode(0);

        // Still only one environment
        $this->assertDatabaseCount('environments', 1);
    }

    public function test_skips_when_local_environment_exists(): void
    {
        createEnvironment(['is_local' => true]);

        $this->artisan('orbit:init')
            ->expectsOutput('Local environment already exists. Skipping.')
            ->assertExitCode(0);
    }

    public function test_creates_with_correct_defaults(): void
    {
        $this->artisan('orbit:init')->assertExitCode(0);

        $env = Environment::where('is_local', true)->first();

        $this->assertEquals('Local', $env->name);
        $this->assertEquals('localhost', $env->host);
        $this->assertTrue($env->is_default);
        $this->assertEquals('test', $env->tld);
    }

    public function test_reads_tld_from_config(): void
    {
        $configPath = rtrim(getenv('HOME'), '/').'/.config/orbit/config.json';

        \Illuminate\Support\Facades\File::shouldReceive('exists')
            ->with($configPath)
            ->andReturn(true);

        \Illuminate\Support\Facades\File::shouldReceive('get')
            ->with($configPath)
            ->andReturn(json_encode(['tld' => 'orbit']));

        $this->artisan('orbit:init')
            ->expectsOutput('Local environment initialized with TLD: orbit')
            ->assertExitCode(0);

        $this->assertDatabaseHas('environments', [
            'is_local' => true,
            'tld' => 'orbit',
        ]);
    }
}
