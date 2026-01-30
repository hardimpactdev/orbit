<?php

use App\Contracts\CaddyfileGeneratorInterface;
use App\Enums\ExitCode;
use App\Services\ConfigManager;
use App\Services\DatabaseService;
use App\Services\ProjectScanner;

beforeEach(function () {
    $this->configManager = Mockery::mock(ConfigManager::class);
    $this->projectScanner = Mockery::mock(ProjectScanner::class);
    $this->caddyfileGenerator = Mockery::mock(CaddyfileGeneratorInterface::class);
    $this->databaseService = Mockery::mock(DatabaseService::class);

    $this->app->instance(ConfigManager::class, $this->configManager);
    $this->app->instance(ProjectScanner::class, $this->projectScanner);
    $this->app->instance(CaddyfileGeneratorInterface::class, $this->caddyfileGenerator);
    $this->app->instance(DatabaseService::class, $this->databaseService);
});

it('sets php version for a site', function () {
    $this->projectScanner->shouldReceive('findProject')->with('mysite')->andReturn([
        'name' => 'mysite',
        'domain' => 'mysite.test',
        'path' => '/path/to/mysite',
        'php_version' => '8.3',
        'has_custom_php' => false,
        'has_public_folder' => true,
    ]);
    $this->databaseService->shouldReceive('setSitePhpVersion')->with('mysite', '/path/to/mysite', '8.4')->once();
    $this->caddyfileGenerator->shouldReceive('generate')->once();
    $this->caddyfileGenerator->shouldReceive('reload')->andReturn(true);

    $this->artisan('php mysite 8.4')
        ->expectsOutputToContain('Set mysite to PHP 8.4')
        ->assertExitCode(0);
});

it('resets php version to default', function () {
    $this->projectScanner->shouldReceive('findProject')->with('mysite')->andReturn([
        'name' => 'mysite',
        'domain' => 'mysite.test',
        'path' => '/path/to/mysite',
        'php_version' => '8.4',
        'has_custom_php' => true,
        'has_public_folder' => true,
    ]);
    $this->databaseService->shouldReceive('removeSiteOverride')->with('mysite')->once();
    $this->configManager->shouldReceive('removeSiteOverride')->with('mysite')->once();
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->caddyfileGenerator->shouldReceive('generate')->once();
    $this->caddyfileGenerator->shouldReceive('reload')->andReturn(true);

    $this->artisan('php mysite --reset')
        ->expectsOutputToContain('Reset mysite to default PHP version')
        ->assertExitCode(0);
});

it('fails when site not found', function () {
    $this->projectScanner->shouldReceive('findProject')->with('nonexistent')->andReturn(null);

    $this->artisan('php nonexistent 8.4')
        ->expectsOutputToContain("Project 'nonexistent' not found")
        ->assertExitCode(ExitCode::InvalidArguments->value);
});

it('fails with invalid php version', function () {
    $this->projectScanner->shouldReceive('findProject')->with('mysite')->andReturn([
        'name' => 'mysite',
        'domain' => 'mysite.test',
        'path' => '/path/to/mysite',
        'php_version' => '8.3',
        'has_custom_php' => false,
        'has_public_folder' => true,
    ]);

    $this->artisan('php mysite 7.4')
        ->expectsOutputToContain('Invalid PHP version')
        ->assertExitCode(ExitCode::InvalidArguments->value);
});

it('outputs json when --json flag is used', function () {
    $this->projectScanner->shouldReceive('findProject')->with('mysite')->andReturn([
        'name' => 'mysite',
        'domain' => 'mysite.test',
        'path' => '/path/to/mysite',
        'php_version' => '8.3',
        'has_custom_php' => false,
        'has_public_folder' => true,
    ]);
    $this->databaseService->shouldReceive('setSitePhpVersion')->with('mysite', '/path/to/mysite', '8.4')->once();
    $this->caddyfileGenerator->shouldReceive('generate')->once();
    $this->caddyfileGenerator->shouldReceive('reload')->andReturn(true);

    $this->artisan('php mysite 8.4 --json')
        ->assertExitCode(0);
});

it('skips caddy reload for projects without public folder', function () {
    $this->projectScanner->shouldReceive('findProject')->with('mypackage')->andReturn([
        'name' => 'mypackage',
        'path' => '/path/to/mypackage',
        'php_version' => '8.3',
        'has_custom_php' => false,
        'has_public_folder' => false,
    ]);
    $this->databaseService->shouldReceive('setSitePhpVersion')->with('mypackage', '/path/to/mypackage', '8.4')->once();
    // Note: caddyfileGenerator should NOT be called

    $this->artisan('php mypackage 8.4')
        ->expectsOutputToContain('Set mypackage to PHP 8.4')
        ->assertExitCode(0);
});
