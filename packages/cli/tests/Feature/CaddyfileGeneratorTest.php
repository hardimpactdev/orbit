<?php

use App\Services\CaddyfileGenerator;
use App\Services\ConfigManager;
use App\Services\ProjectScanner;
use App\Services\ServiceManager;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/orbit-caddy-test-'.uniqid();
    mkdir($this->tempDir.'/caddy', 0755, true);
    mkdir($this->tempDir.'/php', 0755, true);

    $this->configManager = Mockery::mock(ConfigManager::class);
    $this->projectScanner = Mockery::mock(ProjectScanner::class);
    $this->serviceManager = Mockery::mock(ServiceManager::class);

    $this->configManager->shouldReceive('getConfigPath')->andReturn($this->tempDir);
    $this->configManager->shouldReceive('isServiceEnabled')->andReturn(false);
    $this->configManager->shouldReceive('getWebAppPath')->andReturn('');
    $this->configManager->shouldReceive('get')->andReturnUsing(function ($key, $default = null) {
        return $default;
    });

    // Default: Reverb disabled
    $this->serviceManager->shouldReceive('isEnabled')->with('reverb')->andReturn(false);
});

afterEach(function () {
    File::deleteDirectory($this->tempDir);
});

it('generates caddyfile with sites', function () {
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getPaths')->andReturn(['~/Projects']);

    $this->projectScanner->shouldReceive('scanProjects')->andReturn([
        [
            'name' => 'mysite',
            'domain' => 'mysite.test',
            'path' => '/home/user/Projects/mysite',
            'php_version' => '8.3',
            'has_custom_php' => false,
        ],
        [
            'name' => 'another',
            'domain' => 'another.test',
            'path' => '/home/user/Projects/another',
            'php_version' => '8.4',
            'has_custom_php' => true,
        ],
    ]);

    $generator = new CaddyfileGenerator($this->configManager, $this->projectScanner, null, null, $this->serviceManager);
    $generator->generate();

    $caddyfile = File::get($this->tempDir.'/caddy/Caddyfile');

    expect($caddyfile)->toContain('local_certs');
    expect($caddyfile)->toContain('mysite.test');
    expect($caddyfile)->toContain('another.test');
    // Caddyfile uses unix sockets for PHP-FPM
    expect($caddyfile)->toContain('php_fastcgi unix/');
    expect($caddyfile)->toContain('php83.sock');
    expect($caddyfile)->toContain('php84.sock');
});

it('generates caddyfile with php_fastcgi directives', function () {
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getPaths')->andReturn(['~/Projects']);

    $this->projectScanner->shouldReceive('scanProjects')->andReturn([
        [
            'name' => 'mysite',
            'domain' => 'mysite.test',
            'path' => '/home/user/Projects/mysite',
            'php_version' => '8.3',
            'has_custom_php' => false,
        ],
    ]);

    $generator = new CaddyfileGenerator($this->configManager, $this->projectScanner, null, null, $this->serviceManager);
    $generator->generate();

    $caddyfile = File::get($this->tempDir.'/caddy/Caddyfile');

    expect($caddyfile)->toContain('mysite.test');
    expect($caddyfile)->toContain('/public');
    expect($caddyfile)->toContain('php_fastcgi');
    expect($caddyfile)->toContain('file_server');
});

it('generates empty caddyfile when no sites exist', function () {
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getPaths')->andReturn([]);

    $this->projectScanner->shouldReceive('scanProjects')->andReturn([]);

    $generator = new CaddyfileGenerator($this->configManager, $this->projectScanner, null, null, $this->serviceManager);
    $generator->generate();

    $caddyfile = File::get($this->tempDir.'/caddy/Caddyfile');

    expect($caddyfile)->toContain('local_certs');
    // No site entries when no projects exist (Reverb is disabled in mock)
    expect($caddyfile)->not->toContain('mysite');
});
