<?php

use App\Actions\Provision\ConfigureEnvironment;
use App\Data\Provision\ProvisionContext;
use App\Services\ProvisionLogger;
use App\Services\ServiceManager;

beforeEach(function () {
    $this->projectPath = createTestProject('test-env');
    $this->logger = new ProvisionLogger(slug: 'test-env');
});

afterEach(function () {
    deleteDirectory($this->projectPath);
});

it('copies .env.example to .env when .env does not exist', function () {
    $context = new ProvisionContext(
        slug: 'test-env',
        projectPath: $this->projectPath,
    );

    expect(file_exists("{$this->projectPath}/.env"))->toBeFalse();

    $action = new ConfigureEnvironment;
    $result = $action->handle($context, $this->logger);

    expect($result->isSuccess())->toBeTrue();
    expect(file_exists("{$this->projectPath}/.env"))->toBeTrue();
});

it('sets APP_NAME from display name', function () {
    $context = new ProvisionContext(
        slug: 'test-env',
        projectPath: $this->projectPath,
        displayName: 'My Test App',
    );

    $action = new ConfigureEnvironment;
    $result = $action->handle($context, $this->logger);

    expect($result->isSuccess())->toBeTrue();

    $env = file_get_contents("{$this->projectPath}/.env");
    expect($env)->toContain('APP_NAME="My Test App"');
});

it('sets APP_NAME from slug when display name is not provided', function () {
    $context = new ProvisionContext(
        slug: 'my-cool-project',
        projectPath: $this->projectPath,
    );

    $action = new ConfigureEnvironment;
    $result = $action->handle($context, $this->logger);

    expect($result->isSuccess())->toBeTrue();

    $env = file_get_contents("{$this->projectPath}/.env");
    expect($env)->toContain('APP_NAME="My Cool Project"');
});

it('sets APP_URL with correct TLD', function () {
    $context = new ProvisionContext(
        slug: 'test-env',
        projectPath: $this->projectPath,
        tld: 'test',
    );

    $action = new ConfigureEnvironment;
    $result = $action->handle($context, $this->logger);

    $env = file_get_contents("{$this->projectPath}/.env");
    expect($env)->toContain('APP_URL=https://test-env.test');
});

it('configures PostgreSQL database driver', function () {
    $context = new ProvisionContext(
        slug: 'test-env',
        projectPath: $this->projectPath,
        dbDriver: 'pgsql',
    );

    $action = new ConfigureEnvironment;
    $result = $action->handle($context, $this->logger);

    $env = file_get_contents("{$this->projectPath}/.env");
    expect($env)->toContain('DB_CONNECTION=pgsql');
    expect($env)->toContain('DB_HOST=127.0.0.1');
    expect($env)->toContain('DB_DATABASE=test-env');
});

it('configures SQLite database driver and creates database file', function () {
    $context = new ProvisionContext(
        slug: 'test-env',
        projectPath: $this->projectPath,
        dbDriver: 'sqlite',
    );

    $action = new ConfigureEnvironment;
    $result = $action->handle($context, $this->logger);

    $env = file_get_contents("{$this->projectPath}/.env");
    expect($env)->toContain('DB_CONNECTION=sqlite');
    expect(file_exists("{$this->projectPath}/database/database.sqlite"))->toBeTrue();
});

it('configures session driver', function () {
    $context = new ProvisionContext(
        slug: 'test-env',
        projectPath: $this->projectPath,
        sessionDriver: 'redis',
    );

    $action = new ConfigureEnvironment;
    $result = $action->handle($context, $this->logger);

    $env = file_get_contents("{$this->projectPath}/.env");
    expect($env)->toContain('SESSION_DRIVER=redis');
});

it('configures cache driver', function () {
    $context = new ProvisionContext(
        slug: 'test-env',
        projectPath: $this->projectPath,
        cacheDriver: 'redis',
    );

    $action = new ConfigureEnvironment;
    $result = $action->handle($context, $this->logger);

    $env = file_get_contents("{$this->projectPath}/.env");
    expect($env)->toContain('CACHE_STORE=redis');
});

it('configures queue driver', function () {
    $context = new ProvisionContext(
        slug: 'test-env',
        projectPath: $this->projectPath,
        queueDriver: 'redis',
    );

    $action = new ConfigureEnvironment;
    $result = $action->handle($context, $this->logger);

    $env = file_get_contents("{$this->projectPath}/.env");
    expect($env)->toContain('QUEUE_CONNECTION=redis');
});

it('configures Redis connection when any driver uses redis', function () {
    $context = new ProvisionContext(
        slug: 'test-env',
        projectPath: $this->projectPath,
        cacheDriver: 'redis',
    );

    $action = new ConfigureEnvironment;
    $result = $action->handle($context, $this->logger);

    $env = file_get_contents("{$this->projectPath}/.env");
    expect($env)->toContain('REDIS_HOST=127.0.0.1');
    expect($env)->toContain('REDIS_PORT=6379');
});

it('does not configure Redis when no driver uses it', function () {
    $context = new ProvisionContext(
        slug: 'test-env',
        projectPath: $this->projectPath,
        sessionDriver: 'file',
        cacheDriver: 'file',
        queueDriver: 'sync',
    );

    $action = new ConfigureEnvironment;
    $result = $action->handle($context, $this->logger);

    $env = file_get_contents("{$this->projectPath}/.env");
    expect($env)->not->toContain('REDIS_HOST=127.0.0.1');
});

it('returns success when no .env file exists', function () {
    // Remove .env.example
    unlink("{$this->projectPath}/.env.example");

    $context = new ProvisionContext(
        slug: 'test-env',
        projectPath: $this->projectPath,
    );

    $action = new ConfigureEnvironment;
    $result = $action->handle($context, $this->logger);

    expect($result->isSuccess())->toBeTrue();
});

it('uses PostgreSQL credentials from ServiceManager when provided', function () {
    $context = new ProvisionContext(
        slug: 'test-env',
        projectPath: $this->projectPath,
        dbDriver: 'pgsql',
    );

    // Mock ServiceManager with custom credentials
    $serviceManager = Mockery::mock(ServiceManager::class);
    $serviceManager->shouldReceive('getService')
        ->with('postgres')
        ->andReturn([
            'POSTGRES_USER' => 'custom_user',
            'POSTGRES_PASSWORD' => 'custom_pass',
            'port' => 5433,
        ]);

    $action = new ConfigureEnvironment;
    $result = $action->handle($context, $this->logger, $serviceManager);

    $env = file_get_contents("{$this->projectPath}/.env");
    expect($env)->toContain('DB_USERNAME=custom_user');
    expect($env)->toContain('DB_PASSWORD=custom_pass');
    expect($env)->toContain('DB_PORT=5433');
});

it('uses default PostgreSQL credentials when ServiceManager not provided', function () {
    $context = new ProvisionContext(
        slug: 'test-env',
        projectPath: $this->projectPath,
        dbDriver: 'pgsql',
    );

    $action = new ConfigureEnvironment;
    $result = $action->handle($context, $this->logger);

    $env = file_get_contents("{$this->projectPath}/.env");
    expect($env)->toContain('DB_USERNAME=orbit');
    expect($env)->toContain('DB_PASSWORD=secret');
    expect($env)->toContain('DB_PORT=5432');
});
