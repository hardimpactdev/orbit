<?php

use App\Services\ConfigManager;
use App\Services\DatabaseService;
use App\Services\ProjectScanner;

beforeEach(function () {
    $this->configManager = Mockery::mock(ConfigManager::class);
    $this->databaseService = Mockery::mock(DatabaseService::class);
    $this->databaseService->shouldReceive('getPhpVersion')->andReturn(null)->byDefault();
    $this->databaseService->shouldReceive('setSitePath')->byDefault();
    $this->databaseService->shouldReceive('getAllSiteSlugs')->andReturn([])->byDefault();
});

it('scans directories and returns all projects', function () {
    $tempDir = sys_get_temp_dir().'/orbit-test-'.uniqid();
    mkdir($tempDir.'/project1/public', 0755, true);
    mkdir($tempDir.'/project2/public', 0755, true);
    mkdir($tempDir.'/project3', 0755, true); // no public folder

    $this->configManager->shouldReceive('getPaths')->andReturn([$tempDir]);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getSiteOverrides')->andReturn([]);
    $this->configManager->shouldReceive('getSitePhpVersion')->andReturn(null);

    $scanner = new ProjectScanner($this->configManager, $this->databaseService);
    $projects = $scanner->scan();

    expect($projects)->toHaveCount(3);

    // project1 has public folder
    $project1 = collect($projects)->firstWhere('name', 'project1');
    expect($project1['has_public_folder'])->toBeTrue();
    expect($project1['domain'])->toBe('project1.test');

    // project3 has no public folder
    $project3 = collect($projects)->firstWhere('name', 'project3');
    expect($project3['has_public_folder'])->toBeFalse();
    expect($project3)->not->toHaveKey('domain');

    // Cleanup
    rmdir($tempDir.'/project1/public');
    rmdir($tempDir.'/project1');
    rmdir($tempDir.'/project2/public');
    rmdir($tempDir.'/project2');
    rmdir($tempDir.'/project3');
    rmdir($tempDir);
});

it('scanProjects returns only projects with public folder', function () {
    $tempDir = sys_get_temp_dir().'/orbit-test-'.uniqid();
    mkdir($tempDir.'/project1/public', 0755, true);
    mkdir($tempDir.'/project2', 0755, true); // no public folder

    $this->configManager->shouldReceive('getPaths')->andReturn([$tempDir]);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getSiteOverrides')->andReturn([]);
    $this->configManager->shouldReceive('getSitePhpVersion')->andReturn(null);

    $scanner = new ProjectScanner($this->configManager, $this->databaseService);
    $projects = $scanner->scanProjects();

    expect($projects)->toHaveCount(1);
    expect($projects[0]['name'])->toBe('project1');

    // Cleanup
    rmdir($tempDir.'/project1/public');
    rmdir($tempDir.'/project1');
    rmdir($tempDir.'/project2');
    rmdir($tempDir);
});

it('respects php-version file', function () {
    $tempDir = sys_get_temp_dir().'/orbit-test-'.uniqid();
    mkdir($tempDir.'/myproject/public', 0755, true);
    file_put_contents($tempDir.'/myproject/.php-version', '8.4');

    $this->configManager->shouldReceive('getPaths')->andReturn([$tempDir]);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getSiteOverrides')->andReturn([]);
    $this->configManager->shouldReceive('getSitePhpVersion')->andReturn(null);
    $this->databaseService->shouldReceive('setSitePhpVersion')->with('myproject', Mockery::any(), '8.4')->once();

    $scanner = new ProjectScanner($this->configManager, $this->databaseService);
    $projects = $scanner->scan();

    expect($projects)->toHaveCount(1);
    expect($projects[0]['php_version'])->toBe('8.4');
    expect($projects[0]['has_custom_php'])->toBeTrue();

    // Cleanup
    unlink($tempDir.'/myproject/.php-version');
    rmdir($tempDir.'/myproject/public');
    rmdir($tempDir.'/myproject');
    rmdir($tempDir);
});

it('respects database php version override', function () {
    $tempDir = sys_get_temp_dir().'/orbit-test-'.uniqid();
    mkdir($tempDir.'/myproject/public', 0755, true);

    $this->configManager->shouldReceive('getPaths')->andReturn([$tempDir]);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getSiteOverrides')->andReturn([]);
    $this->configManager->shouldReceive('getSitePhpVersion')->andReturn(null);

    $this->databaseService->shouldReceive('getPhpVersion')->with('myproject')->andReturn('8.4');

    $scanner = new ProjectScanner($this->configManager, $this->databaseService);
    $projects = $scanner->scan();

    expect($projects)->toHaveCount(1);
    expect($projects[0]['php_version'])->toBe('8.4');
    expect($projects[0]['has_custom_php'])->toBeTrue();

    // Cleanup
    rmdir($tempDir.'/myproject/public');
    rmdir($tempDir.'/myproject');
    rmdir($tempDir);
});

it('finds a project by name', function () {
    $tempDir = sys_get_temp_dir().'/orbit-test-'.uniqid();
    mkdir($tempDir.'/myproject/public', 0755, true);

    $this->configManager->shouldReceive('getPaths')->andReturn([$tempDir]);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getSiteOverrides')->andReturn([]);
    $this->configManager->shouldReceive('getSitePhpVersion')->andReturn(null);

    $scanner = new ProjectScanner($this->configManager, $this->databaseService);
    $project = $scanner->findProject('myproject');

    expect($project)->not->toBeNull();
    expect($project['name'])->toBe('myproject');

    // Cleanup
    rmdir($tempDir.'/myproject/public');
    rmdir($tempDir.'/myproject');
    rmdir($tempDir);
});

it('returns null for non-existent project', function () {
    $tempDir = sys_get_temp_dir().'/orbit-test-'.uniqid();
    mkdir($tempDir, 0755, true);

    $this->configManager->shouldReceive('getPaths')->andReturn([$tempDir]);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getSiteOverrides')->andReturn([]);

    $scanner = new ProjectScanner($this->configManager, $this->databaseService);
    $project = $scanner->findProject('nonexistent');

    expect($project)->toBeNull();

    // Cleanup
    rmdir($tempDir);
});

it('skips invalid directories', function () {
    $this->configManager->shouldReceive('getPaths')->andReturn(['/nonexistent/path']);
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getSiteOverrides')->andReturn([]);

    $scanner = new ProjectScanner($this->configManager, $this->databaseService);
    $projects = $scanner->scan();

    expect($projects)->toBeEmpty();
});
