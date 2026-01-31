<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Jobs\DeleteProjectJob;
use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Models\Project;

beforeEach(function () {
    $environment = Environment::factory()->local()->create([
        'tld' => 'test',
    ]);
    $project = Project::create([
        'environment_id' => $environment->id,
        'name' => 'delete-test-project',
        'display_name' => 'Delete Test Project',
        'slug' => 'delete-test-project',
        'path' => '/tmp/delete-test-project',
        'php_version' => '8.4',
        'has_public_folder' => true,
        'status' => 'ready',
        'url' => 'delete-test-project.test',
    ]);

    test()->environment = $environment;
    test()->project = $project;
});

describe('DeleteProjectJob', function () {
    describe('constructor', function () {
        it('accepts projectId and keepDatabase', function () {
            $job = new DeleteProjectJob(
                projectId: test()->project->id,
                keepDatabase: false,
            );

            expect($job->projectId)->toBe(test()->project->id);
            expect($job->keepDatabase)->toBeFalse();
        });

        it('defaults keepDatabase to false', function () {
            $job = new DeleteProjectJob(
                projectId: test()->project->id,
            );

            expect($job->keepDatabase)->toBeFalse();
        });

        it('can set keepDatabase to true', function () {
            $job = new DeleteProjectJob(
                projectId: test()->project->id,
                keepDatabase: true,
            );

            expect($job->keepDatabase)->toBeTrue();
        });
    });

    describe('tags', function () {
        it('returns correct horizon tags', function () {
            $job = new DeleteProjectJob(
                projectId: test()->project->id,
            );

            $tags = $job->tags();

            $projectId = test()->project->id;
            expect($tags)->toBe([
                'delete-project',
                "project-id:{$projectId}",
            ]);
        });
    });

    describe('job configuration', function () {
        it('has 120 second timeout', function () {
            $job = new DeleteProjectJob(
                projectId: test()->project->id,
            );

            expect($job->timeout)->toBe(120);
        });

        it('has 1 try (no retries)', function () {
            $job = new DeleteProjectJob(
                projectId: test()->project->id,
            );

            expect($job->tries)->toBe(1);
        });
    });

    // Note: handle() tests require integration testing as the job
    // interacts with filesystem and potentially Docker for database drops.
    // The core functionality is tested via DeletionPipeline unit tests.
});
