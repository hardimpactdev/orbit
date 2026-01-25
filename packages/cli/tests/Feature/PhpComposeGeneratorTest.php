<?php

use App\Services\ConfigManager;
use App\Services\PhpComposeGenerator;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/orbit-compose-test-'.uniqid();
    mkdir($this->tempDir.'/php', 0755, true);

    $this->configManager = Mockery::mock(ConfigManager::class);
    $this->configManager->shouldReceive('getConfigPath')->andReturn($this->tempDir);
    $this->configManager->shouldReceive('getWebAppPath')->andReturn('');
});

afterEach(function () {
    File::deleteDirectory($this->tempDir);
});

it('generates docker-compose with volume mounts', function () {
    $this->configManager->shouldReceive('getPaths')->andReturn([
        '/home/user/Projects',
        '/home/user/Work',
    ]);

    $generator = new PhpComposeGenerator($this->configManager);
    $generator->generate();

    $compose = File::get($this->tempDir.'/php/docker-compose.yml');

    expect($compose)->toContain('php-83:');
    expect($compose)->toContain('php-84:');
    expect($compose)->toContain('build:');
    expect($compose)->toContain('Dockerfile.php83');
    expect($compose)->toContain('Dockerfile.php84');
    expect($compose)->toContain('image: orbit-php:8.3');
    expect($compose)->toContain('image: orbit-php:8.4');
    expect($compose)->toContain('orbit-php-83');
    expect($compose)->toContain('orbit-php-84');
    expect($compose)->toContain('/home/user/Projects:/home/user/Projects');
    expect($compose)->toContain('/home/user/Work:/home/user/Work');
    expect($compose)->toContain('networks:');
    expect($compose)->toContain('orbit');
});

it('expands tilde paths', function () {
    $_SERVER['HOME'] = '/home/testuser';

    $this->configManager->shouldReceive('getPaths')->andReturn([
        '~/Projects',
    ]);

    $generator = new PhpComposeGenerator($this->configManager);
    $generator->generate();

    $compose = File::get($this->tempDir.'/php/docker-compose.yml');

    expect($compose)->toContain('/home/testuser/Projects:/home/testuser/Projects');
});

it('generates compose with no paths', function () {
    $this->configManager->shouldReceive('getPaths')->andReturn([]);

    $generator = new PhpComposeGenerator($this->configManager);
    $generator->generate();

    $compose = File::get($this->tempDir.'/php/docker-compose.yml');

    expect($compose)->toContain('php-83:');
    expect($compose)->toContain('php-84:');
    expect($compose)->toContain('./php.ini:/usr/local/etc/php/php.ini:ro');
});
