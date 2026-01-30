<?php

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Models\Project;

test('project can be created', function () {
    Project::create([
        'name' => 'Test Project',
        'github_url' => 'https://github.com/test/project',
    ]);

    $this->assertDatabaseHas('projects', [
        'name' => 'Test Project',
        'github_url' => 'https://github.com/test/project',
    ]);
});

test('find by github url', function () {
    $project = Project::create([
        'name' => 'Test Project',
        'github_url' => 'https://github.com/test/project',
    ]);

    $found = Project::findByGithubUrl('https://github.com/test/project');

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($project->id);
});

test('find by github url returns null when not found', function () {
    $found = Project::findByGithubUrl('https://github.com/nonexistent/project');

    expect($found)->toBeNull();
});

test('find or create by github url creates new', function () {
    Project::findOrCreateByGithubUrl(
        'https://github.com/test/project',
        'Test Project'
    );

    $this->assertDatabaseHas('projects', [
        'name' => 'Test Project',
        'github_url' => 'https://github.com/test/project',
    ]);
});

test('find or create by github url finds existing', function () {
    $existing = Project::create([
        'name' => 'Existing Project',
        'github_url' => 'https://github.com/test/project',
    ]);

    $project = Project::findOrCreateByGithubUrl(
        'https://github.com/test/project',
        'Different Name'
    );

    expect($project->id)->toBe($existing->id)
        ->and($project->name)->toBe('Existing Project');
});
