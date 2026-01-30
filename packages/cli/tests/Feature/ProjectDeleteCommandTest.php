<?php

use App\Contracts\CaddyfileGeneratorInterface;
use App\Services\ConfigManager;
use HardImpact\Orbit\Core\Data\DeletionContext;
use HardImpact\Orbit\Core\Data\StepResult;
use HardImpact\Orbit\Core\Models\Project;
use HardImpact\Orbit\Core\Services\Deletion\DeletionPipeline;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->configManager = Mockery::mock(ConfigManager::class)->makePartial();
    $this->configManager->shouldReceive('getPaths')->andReturn(['/tmp/projects']);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getConfigPath')->andReturn('/tmp/.config/orbit');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.4');
    $this->configManager->shouldReceive('get')->with('sequence.url', 'http://localhost:8000')->andReturn('http://localhost:8000');
    $this->configManager->shouldReceive('get')->with('reverb.url', '')->andReturn('');
    $this->configManager->shouldReceive('get')->with('paths', [])->andReturn(['/tmp/projects']);
    $this->app->instance(ConfigManager::class, $this->configManager);

    $this->caddyfileGenerator = Mockery::mock(CaddyfileGeneratorInterface::class);
    $this->caddyfileGenerator->shouldReceive('generate')->andReturn(true);
    $this->caddyfileGenerator->shouldReceive('reload')->andReturn(true);
    $this->app->instance(CaddyfileGeneratorInterface::class, $this->caddyfileGenerator);

    // Mock the DeletionPipeline to always succeed
    $this->deletionPipeline = Mockery::mock(DeletionPipeline::class);
    $this->deletionPipeline->shouldReceive('run')
        ->withArgs(fn ($context, $logger) => $context instanceof DeletionContext)
        ->andReturn(StepResult::success());
    $this->app->instance(DeletionPipeline::class, $this->deletionPipeline);

    // Set HOME to temp for DeletionLogger
    $_SERVER['HOME'] = '/tmp';
    @mkdir('/tmp/.config/orbit/logs/deletion', 0755, true);

    // Setup in-memory database for Project model
    config(['database.default' => 'testing']);
    config(['database.connections.testing' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]]);

    // Create projects table
    Schema::connection('testing')->create('projects', function ($table) {
        $table->id();
        $table->unsignedBigInteger('environment_id')->nullable();
        $table->string('name');
        $table->string('display_name')->nullable();
        $table->string('slug');
        $table->string('path')->nullable();
        $table->string('php_version')->nullable();
        $table->string('github_repo')->nullable();
        $table->string('project_type')->nullable();
        $table->boolean('has_public_folder')->default(false);
        $table->string('domain')->nullable();
        $table->string('url')->nullable();
        $table->string('status')->default('queued');
        $table->text('error_message')->nullable();
        $table->string('job_id')->nullable();
        $table->timestamps();
    });
});

afterEach(function () {
    // Clean up log files
    @unlink('/tmp/.config/orbit/logs/deletion/test-project.log');
    @unlink('/tmp/.config/orbit/logs/deletion/nonexistent.log');
});

it('deletes project via MCP when given slug with --force', function () {
    Http::fake([
        'localhost:8000/mcp' => Http::response([
            'jsonrpc' => '2.0',
            'result' => [
                'content' => [['text' => '# Project Deleted']],
                'meta' => [
                    'id' => 1,
                    'name' => 'Test Project',
                    'slug' => 'test-project',
                ],
            ],
            'id' => 'test-id',
        ]),
    ]);

    // Just check that the command executes and makes MCP call
    $this->artisan('project:delete', ['slug' => 'test-project', '--force' => true, '--json' => true]);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://localhost:8000/mcp'
            && $request['params']['name'] === 'delete-project';
    });
});

it('handles MCP error response with warning and continues', function () {
    Http::fake([
        'localhost:8000/mcp' => Http::response([
            'jsonrpc' => '2.0',
            'error' => ['message' => 'Project not found'],
            'id' => 'test-id',
        ]),
    ]);

    // Command should succeed but with warnings - MCP errors are non-fatal
    $this->artisan('project:delete', ['slug' => 'nonexistent', '--force' => true, '--json' => true])
        ->assertExitCode(0);
});

it('handles connection error with warning and continues', function () {
    Http::fake([
        'localhost:8000/mcp' => Http::response(status: 500),
    ]);

    // Command should succeed but with warnings - connection errors are non-fatal
    $this->artisan('project:delete', ['slug' => 'test-project', '--force' => true, '--json' => true])
        ->assertExitCode(0);
});
