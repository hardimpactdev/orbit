<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Jobs\CreateProjectJob;
use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Models\Project;
use HardImpact\Orbit\Core\Services\Provision\GitHubService;

beforeEach(function () {
    $environment = Environment::factory()->local()->create([
        'tld' => 'test',
    ]);
    $project = Project::create([
        'environment_id' => $environment->id,
        'name' => 'test-project',
        'display_name' => 'Test Project',
        'slug' => 'test-project',
        'path' => '~/projects/test-project',
        'php_version' => '8.4',
        'has_public_folder' => false,
        'status' => 'queued',
    ]);

    test()->environment = $environment;
    test()->project = $project;
});

describe('CreateProjectJob', function () {
    describe('constructor', function () {
        it('generates slug from name option', function () {
            $job = new CreateProjectJob(
                projectId: test()->project->id,
                options: ['name' => 'My Test Site'],
            );

            expect($job->getSlug())->toBe('my-test-site');
        });
    });

    describe('tags', function () {
        it('returns correct horizon tags', function () {
            $job = new CreateProjectJob(
                projectId: test()->project->id,
                options: ['name' => 'My Test Site'],
            );

            $tags = $job->tags();

            $projectId = test()->project->id;
            expect($tags)->toBe([
                'create-project',
                'project:my-test-site',
                "project-id:{$projectId}",
            ]);
        });
    });

    describe('job configuration', function () {
        it('has 600 second timeout', function () {
            $job = new CreateProjectJob(
                projectId: test()->project->id,
                options: ['name' => 'test-site'],
            );

            expect($job->timeout)->toBe(600);
        });

        it('has 1 try (no retries)', function () {
            $job = new CreateProjectJob(
                projectId: test()->project->id,
                options: ['name' => 'test-site'],
            );

            expect($job->tries)->toBe(1);
        });
    });

    // Note: handle() tests require integration testing due to final classes (GitHubService)
    // The core functionality is tested via context building and site type detection tests

    describe('context building', function () {
        it('builds context with correct options', function () {
            $job = new CreateProjectJob(
                projectId: test()->project->id,
                options: [
                    'name' => 'test-site',
                    'template' => 'laravel/laravel',
                    'is_template' => true,
                    'visibility' => 'public',
                    'php_version' => '8.5',
                    'db_driver' => 'pgsql',
                    'session_driver' => 'redis',
                    'cache_driver' => 'redis',
                    'queue_driver' => 'redis',
                    'org' => 'my-org',
                ],
            );

            // Use reflection to test buildContext
            $reflection = new ReflectionClass($job);
            $method = $reflection->getMethod('buildContext');

            $context = $method->invoke($job, '/tmp/test', test()->environment);

            expect($context)->toBeInstanceOf(ProvisionContext::class);
            expect($context->slug)->toBe('test-site');
            expect($context->template)->toBe('laravel/laravel');
            expect($context->visibility)->toBe('public');
            expect($context->phpVersion)->toBe('8.5');
            expect($context->dbDriver)->toBe('pgsql');
            expect($context->sessionDriver)->toBe('redis');
            expect($context->cacheDriver)->toBe('redis');
            expect($context->queueDriver)->toBe('redis');
            expect($context->organization)->toBe('my-org');
        });

        it('handles clone URL correctly', function () {
            $job = new CreateProjectJob(
                projectId: test()->project->id,
                options: [
                    'name' => 'cloned-site',
                    'template' => 'owner/repo',
                    'is_template' => false,
                ],
            );

            $reflection = new ReflectionClass($job);
            $method = $reflection->getMethod('buildContext');

            $context = $method->invoke($job, '/tmp/cloned', test()->environment);

            expect($context->cloneUrl)->toBe('owner/repo');
            expect($context->template)->toBeNull(); // template is null when is_template=false
        });

        it('uses environment TLD', function () {
            $job = new CreateProjectJob(
                projectId: test()->project->id,
                options: ['name' => 'test-site'],
            );

            $reflection = new ReflectionClass($job);
            $method = $reflection->getMethod('buildContext');

            $context = $method->invoke($job, '/tmp/test', test()->environment);

            expect($context->tld)->toBe('test');
        });
    });

    describe('project type detection', function () {
        it('detects laravel-app correctly', function () {
            $job = new CreateProjectJob(
                projectId: test()->project->id,
                options: ['name' => 'test-project'],
            );

            $reflection = new ReflectionClass($job);
            $method = $reflection->getMethod('detectProjectType');

            // Create test project
            $projectDir = sys_get_temp_dir().'/laravel-app-'.uniqid();
            mkdir("{$projectDir}/public", 0755, true);
            touch("{$projectDir}/artisan");

            expect($method->invoke($job, $projectDir))->toBe('laravel-app');

            // Cleanup
            @unlink("{$projectDir}/artisan");
            @rmdir("{$projectDir}/public");
            @rmdir($projectDir);
        });

        it('detects cli app correctly', function () {
            $job = new CreateProjectJob(
                projectId: test()->project->id,
                options: ['name' => 'test-project'],
            );

            $reflection = new ReflectionClass($job);
            $method = $reflection->getMethod('detectProjectType');

            // Create test project
            $projectDir = sys_get_temp_dir().'/cli-app-'.uniqid();
            mkdir($projectDir, 0755, true);
            touch("{$projectDir}/artisan");
            file_put_contents("{$projectDir}/composer.json", json_encode([
                'require' => ['laravel-zero/framework' => '^12.0'],
            ]));

            expect($method->invoke($job, $projectDir))->toBe('cli');

            // Cleanup
            @unlink("{$projectDir}/artisan");
            @unlink("{$projectDir}/composer.json");
            @rmdir($projectDir);
        });

        it('detects laravel-package correctly', function () {
            $job = new CreateProjectJob(
                projectId: test()->project->id,
                options: ['name' => 'test-project'],
            );

            $reflection = new ReflectionClass($job);
            $method = $reflection->getMethod('detectProjectType');

            // Create test project
            $projectDir = sys_get_temp_dir().'/package-'.uniqid();
            mkdir($projectDir, 0755, true);
            file_put_contents("{$projectDir}/composer.json", json_encode([
                'type' => 'laravel-package',
            ]));

            expect($method->invoke($job, $projectDir))->toBe('laravel-package');

            // Cleanup
            @unlink("{$projectDir}/composer.json");
            @rmdir($projectDir);
        });
    });
});
