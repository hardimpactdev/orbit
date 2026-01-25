<?php

use App\Actions\Provision\SetPhpVersion;
use App\Data\Provision\ProvisionContext;
use App\Services\DatabaseService;
use App\Services\ProvisionLogger;

beforeEach(function () {
    $this->projectPath = createTestProject('test-php-version');
    $this->logger = new ProvisionLogger(slug: 'test-php-version');

    // Set up test database
    $this->dbPath = sys_get_temp_dir().'/orbit-tests/test-php.sqlite';
    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }

    // Bind the test database service
    app()->singleton(DatabaseService::class, fn () => new DatabaseService($this->dbPath));
});

afterEach(function () {
    deleteDirectory($this->projectPath);
    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }
});

it('uses provided PHP version', function () {
    $context = new ProvisionContext(
        slug: 'test-php-version',
        projectPath: $this->projectPath,
        phpVersion: '8.4',
    );

    $action = new SetPhpVersion;
    $result = $action->handle($context, $this->logger);

    expect($result->isSuccess())->toBeTrue();
    expect($result->data['phpVersion'])->toBe('8.4');

    $versionFile = "{$this->projectPath}/.php-version";
    expect(file_exists($versionFile))->toBeTrue();
    expect(trim(file_get_contents($versionFile)))->toBe('8.4');
});

it('detects PHP version from composer.json caret constraint', function () {
    $context = new ProvisionContext(
        slug: 'test-php-version',
        projectPath: $this->projectPath,
    );

    // Update composer.json with ^8.3
    file_put_contents("{$this->projectPath}/composer.json", json_encode([
        'require' => ['php' => '^8.3'],
    ], JSON_PRETTY_PRINT));

    $action = new SetPhpVersion;
    $result = $action->handle($context, $this->logger);

    expect($result->isSuccess())->toBeTrue();
    // ^8.3 allows 8.5, so should use latest
    expect($result->data['phpVersion'])->toBe('8.5');
});

it('detects PHP version from tilde constraint', function () {
    $context = new ProvisionContext(
        slug: 'test-php-version',
        projectPath: $this->projectPath,
    );

    // ~8.3.0 means >=8.3.0 <8.4.0
    file_put_contents("{$this->projectPath}/composer.json", json_encode([
        'require' => ['php' => '~8.3.0'],
    ], JSON_PRETTY_PRINT));

    $action = new SetPhpVersion;
    $result = $action->handle($context, $this->logger);

    expect($result->isSuccess())->toBeTrue();
    expect($result->data['phpVersion'])->toBe('8.3');
});

it('detects PHP version from upper bound constraint', function () {
    $context = new ProvisionContext(
        slug: 'test-php-version',
        projectPath: $this->projectPath,
    );

    // <8.5 means can't use 8.5
    file_put_contents("{$this->projectPath}/composer.json", json_encode([
        'require' => ['php' => '<8.5'],
    ], JSON_PRETTY_PRINT));

    $action = new SetPhpVersion;
    $result = $action->handle($context, $this->logger);

    expect($result->isSuccess())->toBeTrue();
    expect($result->data['phpVersion'])->toBe('8.4');
});

it('uses default version when no composer.json exists', function () {
    $context = new ProvisionContext(
        slug: 'test-php-version',
        projectPath: $this->projectPath,
    );

    unlink("{$this->projectPath}/composer.json");

    $action = new SetPhpVersion;
    $result = $action->handle($context, $this->logger);

    expect($result->isSuccess())->toBeTrue();
    expect($result->data['phpVersion'])->toBe('8.5');
});

it('uses default version when no PHP constraint in composer.json', function () {
    $context = new ProvisionContext(
        slug: 'test-php-version',
        projectPath: $this->projectPath,
    );

    file_put_contents("{$this->projectPath}/composer.json", json_encode([
        'require' => ['laravel/framework' => '^12.0'],
    ], JSON_PRETTY_PRINT));

    $action = new SetPhpVersion;
    $result = $action->handle($context, $this->logger);

    expect($result->isSuccess())->toBeTrue();
    expect($result->data['phpVersion'])->toBe('8.5');
});

it('writes .php-version file', function () {
    $context = new ProvisionContext(
        slug: 'test-php-version',
        projectPath: $this->projectPath,
        phpVersion: '8.3',
    );

    $action = new SetPhpVersion;
    $action->handle($context, $this->logger);

    $versionFile = "{$this->projectPath}/.php-version";
    expect(file_exists($versionFile))->toBeTrue();
    expect(trim(file_get_contents($versionFile)))->toBe('8.3');
});
