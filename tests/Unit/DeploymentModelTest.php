<?php

use HardImpact\Orbit\Models\Deployment;
use HardImpact\Orbit\Models\Environment;
use HardImpact\Orbit\Models\Project;

beforeEach(function () {
    $this->project = Project::create([
        'name' => 'Test Project',
        'github_url' => 'https://github.com/test/project',
    ]);

    $this->environment = Environment::create([
        'name' => 'Test Server',
        'host' => 'localhost',
        'user' => 'test',
        'port' => 22,
        'is_local' => true,
    ]);
});

test('deployment can be created', function () {
    Deployment::create([
        'project_id' => $this->project->id,
        'environment_id' => $this->environment->id,
        'status' => 'active',
        'local_path' => '/path/to/project',
    ]);

    $this->assertDatabaseHas('deployments', [
        'project_id' => $this->project->id,
        'environment_id' => $this->environment->id,
        'status' => 'active',
    ]);
});

test('deployment belongs to project', function () {
    $deployment = Deployment::create([
        'project_id' => $this->project->id,
        'environment_id' => $this->environment->id,
        'status' => 'active',
    ]);

    expect($deployment->project->id)->toBe($this->project->id);
});

test('deployment belongs to environment', function () {
    $deployment = Deployment::create([
        'project_id' => $this->project->id,
        'environment_id' => $this->environment->id,
        'status' => 'active',
    ]);

    expect($deployment->environment->id)->toBe($this->environment->id);
});

test('status helper methods', function () {
    $deployment = Deployment::create([
        'project_id' => $this->project->id,
        'environment_id' => $this->environment->id,
        'status' => 'pending',
    ]);

    expect($deployment->isPending())->toBeTrue()
        ->and($deployment->isActive())->toBeFalse()
        ->and($deployment->isDeploying())->toBeFalse()
        ->and($deployment->hasError())->toBeFalse();

    $deployment->update(['status' => 'active']);
    expect($deployment->isActive())->toBeTrue();

    $deployment->update(['status' => 'deploying']);
    expect($deployment->isDeploying())->toBeTrue();

    $deployment->update(['status' => 'error']);
    expect($deployment->hasError())->toBeTrue();
});
