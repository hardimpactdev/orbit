<?php

use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;

test('environment can be created', function () {
    $environment = Environment::create([
        'name' => 'Test Server',
        'host' => 'ai',
        'user' => 'launchpad',
        'port' => 22,
        'is_local' => false,
        'status' => 'active',
    ]);

    $this->assertDatabaseHas('environments', [
        'name' => 'Test Server',
        'host' => 'ai',
    ]);
});

test('environment has status helper methods', function () {
    $environment = Environment::create([
        'name' => 'Test Server',
        'host' => 'localhost',
        'user' => 'test',
        'port' => 22,
        'is_local' => true,
        'status' => 'provisioning',
    ]);

    expect($environment->isProvisioning())->toBeTrue()
        ->and($environment->isActive())->toBeFalse()
        ->and($environment->hasError())->toBeFalse();

    $environment->update(['status' => 'active']);
    expect($environment->isActive())->toBeTrue();

    $environment->update(['status' => 'error']);
    expect($environment->hasError())->toBeTrue();
});

test('get ssh connection string for remote environment', function () {
    $environment = Environment::create([
        'name' => 'Remote Server',
        'host' => 'ai',
        'user' => 'launchpad',
        'port' => 22,
        'is_local' => false,
    ]);

    expect($environment->getSshConnectionString())->toBe('launchpad@ai');
});

test('get ssh connection string includes port when not 22', function () {
    $environment = Environment::create([
        'name' => 'Remote Server',
        'host' => 'ai',
        'user' => 'launchpad',
        'port' => 2222,
        'is_local' => false,
    ]);

    expect($environment->getSshConnectionString())->toBe('launchpad@ai -p 2222');
});

test('get ssh connection string returns local for local environment', function () {
    $environment = Environment::create([
        'name' => 'Local',
        'host' => 'localhost',
        'user' => 'test',
        'port' => 22,
        'is_local' => true,
    ]);

    expect($environment->getSshConnectionString())->toBe('local');
});

test('get default environment', function () {
    Environment::create([
        'name' => 'Not Default',
        'host' => 'host1',
        'user' => 'test',
        'port' => 22,
        'is_local' => false,
        'is_default' => false,
    ]);

    $default = Environment::create([
        'name' => 'Default',
        'host' => 'host2',
        'user' => 'test',
        'port' => 22,
        'is_local' => false,
        'is_default' => true,
    ]);

    expect(Environment::getDefault()->id)->toBe($default->id);
});

test('get default returns null when no default', function () {
    Environment::create([
        'name' => 'Not Default',
        'host' => 'host1',
        'user' => 'test',
        'port' => 22,
        'is_local' => false,
        'is_default' => false,
    ]);

    expect(Environment::getDefault())->toBeNull();
});

test('get local environment', function () {
    Environment::create([
        'name' => 'Remote',
        'host' => 'host1',
        'user' => 'test',
        'port' => 22,
        'is_local' => false,
    ]);

    $local = Environment::create([
        'name' => 'Local',
        'host' => 'localhost',
        'user' => 'test',
        'port' => 22,
        'is_local' => true,
    ]);

    expect(Environment::getLocal()->id)->toBe($local->id);
});

test('environment has deployments relationship', function () {
    $environment = Environment::create([
        'name' => 'Test Server',
        'host' => 'localhost',
        'user' => 'test',
        'port' => 22,
        'is_local' => true,
    ]);

    $project = Project::create([
        'name' => 'Test Project',
        'github_url' => 'https://github.com/test/project',
    ]);

    Deployment::create([
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'status' => 'active',
    ]);

    expect($environment->deployments)->toHaveCount(1);
});

test('metadata is cast to array', function () {
    $environment = Environment::create([
        'name' => 'Test Server',
        'host' => 'localhost',
        'user' => 'test',
        'port' => 22,
        'is_local' => true,
        'metadata' => ['key' => 'value'],
    ]);

    expect($environment->metadata)->toBe(['key' => 'value']);
});

test('provisioning log is cast to array', function () {
    $environment = Environment::create([
        'name' => 'Test Server',
        'host' => 'localhost',
        'user' => 'test',
        'port' => 22,
        'is_local' => true,
        'provisioning_log' => ['step1', 'step2'],
    ]);

    expect($environment->provisioning_log)->toBe(['step1', 'step2']);
});
