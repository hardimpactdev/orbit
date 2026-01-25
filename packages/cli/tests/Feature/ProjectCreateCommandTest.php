<?php

use App\Commands\ProjectCreateCommand;

/**
 * Tests for ProjectCreateCommand.
 *
 * Note: Full integration tests for project:create require the database
 * to be set up with migrations. These tests focus on option definitions,
 * URL normalization, and project type detection which don't require the database.
 */
describe('option definitions', function () {
    /**
     * Verify all expected options are defined.
     */
    it('has --organization option defined (not --org)', function () {
        $command = $this->app->make(ProjectCreateCommand::class);
        $definition = $command->getDefinition();

        expect($definition->hasOption('organization'))->toBeTrue();
        expect($definition->hasOption('org'))->toBeFalse();
    });

    it('has all expected options', function () {
        $command = $this->app->make(ProjectCreateCommand::class);
        $definition = $command->getDefinition();

        // Options supported by the CLI
        $expectedOptions = [
            'template',
            'clone',
            'fork',
            'visibility',
            'organization',
            'php',
            'db-driver',
            'session-driver',
            'cache-driver',
            'queue-driver',
            'directory',
            'json',
        ];

        foreach ($expectedOptions as $option) {
            expect($definition->hasOption($option))
                ->toBeTrue("Missing option: --{$option}");
        }
    });

    it('has correct option defaults', function () {
        $command = $this->app->make(ProjectCreateCommand::class);
        $definition = $command->getDefinition();

        expect($definition->getOption('visibility')->getDefault())->toBe('private');
        expect($definition->getOption('fork')->getDefault())->toBeFalse();
    });

    it('no longer has --wait option (sync execution)', function () {
        $command = $this->app->make(ProjectCreateCommand::class);
        $definition = $command->getDefinition();

        // --wait is removed since command runs synchronously now
        expect($definition->hasOption('wait'))->toBeFalse();
    });

    it('has --directory option for custom path', function () {
        $command = $this->app->make(ProjectCreateCommand::class);
        $definition = $command->getDefinition();

        expect($definition->hasOption('directory'))->toBeTrue();
    });
});

describe('URL normalization', function () {
    it('normalizes https github URLs', function () {
        $command = $this->app->make(ProjectCreateCommand::class);
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('normalizeRepoUrl');

        expect($method->invoke($command, 'https://github.com/user/repo'))->toBe('user/repo');
        expect($method->invoke($command, 'https://github.com/user/repo.git'))->toBe('user/repo');
    });

    it('normalizes ssh github URLs', function () {
        $command = $this->app->make(ProjectCreateCommand::class);
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('normalizeRepoUrl');

        expect($method->invoke($command, 'git@github.com:user/repo.git'))->toBe('user/repo');
        expect($method->invoke($command, 'git@github.com:user/repo'))->toBe('user/repo');
    });

    it('passes through owner/repo format unchanged', function () {
        $command = $this->app->make(ProjectCreateCommand::class);
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('normalizeRepoUrl');

        expect($method->invoke($command, 'user/repo'))->toBe('user/repo');
    });

    it('handles null input', function () {
        $command = $this->app->make(ProjectCreateCommand::class);
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('normalizeRepoUrl');

        expect($method->invoke($command, null))->toBeNull();
    });
});

describe('project type detection', function () {
    beforeEach(function () {
        $this->tempDir = sys_get_temp_dir().'/orbit-projecttype-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);
    });

    afterEach(function () {
        \Illuminate\Support\Facades\File::deleteDirectory($this->tempDir);
    });

    it('detects laravel-app correctly', function () {
        $command = $this->app->make(ProjectCreateCommand::class);
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('detectProjectType');

        // Create test project with public folder and artisan
        $projectDir = $this->tempDir.'/laravel-app';
        mkdir("{$projectDir}/public", 0755, true);
        touch("{$projectDir}/artisan");

        expect($method->invoke($command, $projectDir))->toBe('laravel-app');
    });

    it('detects cli app correctly', function () {
        $command = $this->app->make(ProjectCreateCommand::class);
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('detectProjectType');

        // Create test CLI project
        $projectDir = $this->tempDir.'/cli-app';
        mkdir($projectDir, 0755, true);
        touch("{$projectDir}/artisan");
        file_put_contents("{$projectDir}/composer.json", json_encode([
            'require' => ['laravel-zero/framework' => '^12.0'],
        ]));

        expect($method->invoke($command, $projectDir))->toBe('cli');
    });

    it('detects laravel-package correctly', function () {
        $command = $this->app->make(ProjectCreateCommand::class);
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('detectProjectType');

        // Create test package
        $projectDir = $this->tempDir.'/package';
        mkdir($projectDir, 0755, true);
        file_put_contents("{$projectDir}/composer.json", json_encode([
            'type' => 'laravel-package',
        ]));

        expect($method->invoke($command, $projectDir))->toBe('laravel-package');
    });

    it('detects package by laravel extra config', function () {
        $command = $this->app->make(ProjectCreateCommand::class);
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('detectProjectType');

        // Create test package with laravel providers
        $projectDir = $this->tempDir.'/package-providers';
        mkdir($projectDir, 0755, true);
        file_put_contents("{$projectDir}/composer.json", json_encode([
            'extra' => [
                'laravel' => [
                    'providers' => ['Some\\Provider'],
                ],
            ],
        ]));

        expect($method->invoke($command, $projectDir))->toBe('laravel-package');
    });

    it('detects web project without artisan', function () {
        $command = $this->app->make(ProjectCreateCommand::class);
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('detectProjectType');

        // Create simple web project
        $projectDir = $this->tempDir.'/web';
        mkdir("{$projectDir}/public", 0755, true);

        expect($method->invoke($command, $projectDir))->toBe('web');
    });

    it('returns unknown for empty directory', function () {
        $command = $this->app->make(ProjectCreateCommand::class);
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('detectProjectType');

        // Empty project
        $projectDir = $this->tempDir.'/empty';
        mkdir($projectDir, 0755, true);

        expect($method->invoke($command, $projectDir))->toBe('unknown');
    });
});

describe('reserved names', function () {
    it('rejects reserved name "orbit"', function () {
        $this->artisan('project:create', ['name' => 'orbit', '--json' => true])
            ->assertExitCode(1);
    });
});
