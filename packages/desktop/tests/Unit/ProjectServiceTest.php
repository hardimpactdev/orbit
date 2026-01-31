<?php

use HardImpact\Orbit\Core\Http\Integrations\Orbit\OrbitConnector;
use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\ProjectCliService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\ConnectorService;
use HardImpact\Orbit\Core\Services\SshService;

beforeEach(function () {
    $this->sshService = Mockery::mock(SshService::class);
    $this->connectorService = Mockery::mock(ConnectorService::class);
    $this->commandService = Mockery::mock(CommandService::class);
    $this->configService = Mockery::mock(ConfigurationService::class);

    // Default return for any execute call - return successful empty response
    $this->sshService->shouldReceive('execute')
        ->byDefault()
        ->andReturn(['success' => true, 'output' => '']);

    $this->environment = Environment::create([
        'name' => 'Test Environment',
        'host' => 'ai',
        'user' => 'orbit',
        'port' => 22,
        'is_local' => false,
        'is_default' => true,
        'status' => 'active',
        'tld' => 'ccc',
    ]);

    $this->service = new ProjectCliService(
        $this->connectorService,
        $this->commandService,
        $this->sshService,
        $this->configService
    );
});

afterEach(function () {
    // Clear the mock client after each test
    OrbitConnector::clearMockClient();
});

describe('createProject', function () {
    test('creates project via HTTP API successfully', function () {
        $this->connectorService->shouldReceive('sendRequest')
            ->once()
            ->andReturn([
                'success' => true,
                'status' => 'queued',
                'slug' => 'my-project',
                'message' => 'Project creation has been queued.',
            ]);

        $result = $this->service->createProject($this->environment, [
            'name' => 'My Project',
            'visibility' => 'private',
        ]);

        expect($result['success'])->toBeTrue()
            ->and($result['data']['slug'])->toBe('my-project')
            ->and($result['data']['status'])->toBe('provisioning');
    });

    test('creates project with template option', function () {
        $this->connectorService->shouldReceive('sendRequest')
            ->once()
            ->with(Mockery::type(Environment::class), Mockery::on(function ($request) {
                $body = $request->body()->all();

                return ($body['template'] ?? null) === 'laravel/laravel'
                    && ! isset($body['clone_url']);
            }))
            ->andReturn(['success' => true, 'slug' => 'my-project']);

        $result = $this->service->createProject($this->environment, [
            'name' => 'My Project',
            'visibility' => 'private',
            'template' => 'laravel/laravel',
            'is_template' => true,
        ]);

        expect($result['success'])->toBeTrue();
    });

    test('creates project with clone URL option', function () {
        $this->connectorService->shouldReceive('sendRequest')
            ->once()
            ->with(Mockery::type(Environment::class), Mockery::on(function ($request) {
                $body = $request->body()->all();

                return ($body['clone_url'] ?? null) === 'git@github.com:owner/repo.git'
                    && ! isset($body['template']);
            }))
            ->andReturn(['success' => true, 'slug' => 'my-project']);

        $result = $this->service->createProject($this->environment, [
            'name' => 'My Project',
            'visibility' => 'private',
            'template' => 'owner/repo',
            'is_template' => false,
        ]);

        expect($result['success'])->toBeTrue();
    });

    test('creates project with fork option', function () {
        $this->connectorService->shouldReceive('sendRequest')
            ->once()
            ->with(Mockery::type(Environment::class), Mockery::on(function ($request) {
                $body = $request->body()->all();

                return ($body['fork'] ?? null) === true;
            }))
            ->andReturn(['success' => true, 'slug' => 'my-project']);

        $result = $this->service->createProject($this->environment, [
            'name' => 'My Project',
            'visibility' => 'private',
            'template' => 'owner/repo',
            'is_template' => false,
            'fork' => true,
        ]);

        expect($result['success'])->toBeTrue();
    });

    test('creates project with all driver options', function () {
        $this->connectorService->shouldReceive('sendRequest')
            ->once()
            ->with(Mockery::type(Environment::class), Mockery::on(function ($request) {
                $body = $request->body()->all();

                return ($body['db_driver'] ?? null) === 'pgsql'
                    && ($body['session_driver'] ?? null) === 'redis'
                    && ($body['cache_driver'] ?? null) === 'redis'
                    && ($body['queue_driver'] ?? null) === 'redis'
                    && ($body['php_version'] ?? null) === '8.4';
            }))
            ->andReturn(['success' => true, 'slug' => 'my-project']);

        $result = $this->service->createProject($this->environment, [
            'name' => 'My Project',
            'visibility' => 'private',
            'db_driver' => 'pgsql',
            'session_driver' => 'redis',
            'cache_driver' => 'redis',
            'queue_driver' => 'redis',
            'php_version' => '8.4',
        ]);

        expect($result['success'])->toBeTrue();
    });

    test('creates project with directory option', function () {
        $this->connectorService->shouldReceive('sendRequest')
            ->once()
            ->with(Mockery::type(Environment::class), Mockery::on(function ($request) {
                $body = $request->body()->all();

                return ($body['path'] ?? null) === '/custom/path';
            }))
            ->andReturn(['success' => true, 'slug' => 'my-project']);

        $result = $this->service->createProject($this->environment, [
            'name' => 'My Project',
            'visibility' => 'private',
            'directory' => '/custom/path',
        ]);

        expect($result['success'])->toBeTrue();
    });

    test('returns error when API returns error response', function () {
        $this->connectorService->shouldReceive('sendRequest')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Project already exists',
            ]);

        $result = $this->service->createProject($this->environment, [
            'name' => 'My Project',
            'visibility' => 'private',
        ]);

        expect($result['success'])->toBeFalse()
            ->and($result['error'])->toBe('Project already exists');
    });
});
