<?php
declare(strict_types=1);

use HardImpact\Orbit\Core\Data\DeletionContext;
use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Models\Project;
use HardImpact\Orbit\Core\Services\Deletion\Actions\DeleteProjectFiles;
use HardImpact\Orbit\Core\Services\Deletion\Actions\DropPostgresDatabase;
use HardImpact\Orbit\Core\Services\Deletion\DeletionLogger;
use HardImpact\Orbit\Core\Services\Deletion\DeletionPipeline;

beforeEach(function () {
    $environment = Environment::factory()->local()->create([
        'tld' => 'test',
    ]);
    test()->environment = $environment;
});

describe('DeletionContext', function () {
    it('creates context from project model', function () {
        $project = Project::create([
            'environment_id' => test()->environment->id,
            'name' => 'delete-test',
            'display_name' => 'Delete Test',
            'slug' => 'delete-test',
            'path' => '/tmp/delete-test',
            'php_version' => '8.4',
            'has_public_folder' => true,
            'url' => 'delete-test.test',
            'status' => 'ready',
        ]);

        $context = DeletionContext::fromProject($project, false);

        expect($context->slug)->toBe('delete-test');
        expect($context->projectPath)->toBe('/tmp/delete-test');
        expect($context->projectId)->toBe($project->id);
        expect($context->keepDatabase)->toBeFalse();
        expect($context->keepRepository)->toBeTrue();
        expect($context->tld)->toBe('test');
    });

    it('respects keepDatabase flag', function () {
        $project = Project::create([
            'environment_id' => test()->environment->id,
            'name' => 'keep-db-test',
            'slug' => 'keep-db-test',
            'path' => '/tmp/keep-db-test',
            'php_version' => '8.4',
            'url' => 'keep-db-test.test',
            'status' => 'ready',
        ]);

        $context = DeletionContext::fromProject($project, true);

        expect($context->keepDatabase)->toBeTrue();
    });

    it('loads database config from env file', function () {
        // Create a temp directory with .env file
        $projectDir = sys_get_temp_dir().'/env-test-'.uniqid();
        mkdir($projectDir, 0755, true);
        file_put_contents("{$projectDir}/.env", "DB_CONNECTION=pgsql\nDB_DATABASE=testdb\n");

        $context = new DeletionContext(
            slug: 'env-test',
            projectPath: $projectDir,
            projectId: null,
            keepDatabase: false,
        );

        $loadedContext = $context->withDatabaseFromEnv();

        expect($loadedContext->dbConnection)->toBe('pgsql');
        expect($loadedContext->dbName)->toBe('testdb');

        // Cleanup
        @unlink("{$projectDir}/.env");
        @rmdir($projectDir);
    });

    it('uses slug as database name when not in env', function () {
        // Create a temp directory with .env file without DB_DATABASE
        $projectDir = sys_get_temp_dir().'/env-test-'.uniqid();
        mkdir($projectDir, 0755, true);
        file_put_contents("{$projectDir}/.env", "DB_CONNECTION=pgsql\n");

        $context = new DeletionContext(
            slug: 'fallback-slug',
            projectPath: $projectDir,
            projectId: null,
            keepDatabase: false,
        );

        $loadedContext = $context->withDatabaseFromEnv();

        expect($loadedContext->dbName)->toBe('fallback-slug');

        // Cleanup
        @unlink("{$projectDir}/.env");
        @rmdir($projectDir);
    });

    it('detects postgres connections correctly', function () {
        $contextPgsql = new DeletionContext(
            slug: 'test',
            projectPath: '/tmp/test',
            dbConnection: 'pgsql',
        );
        expect($contextPgsql->usesPostgres())->toBeTrue();

        $contextPostgres = new DeletionContext(
            slug: 'test',
            projectPath: '/tmp/test',
            dbConnection: 'postgres',
        );
        expect($contextPostgres->usesPostgres())->toBeTrue();

        $contextMysql = new DeletionContext(
            slug: 'test',
            projectPath: '/tmp/test',
            dbConnection: 'mysql',
        );
        expect($contextMysql->usesPostgres())->toBeFalse();

        $contextNull = new DeletionContext(
            slug: 'test',
            projectPath: '/tmp/test',
            dbConnection: null,
        );
        expect($contextNull->usesPostgres())->toBeFalse();
    });
});

describe('DeleteProjectFiles', function () {
    it('deletes project directory successfully', function () {
        // Create a temp directory with some files
        $projectDir = sys_get_temp_dir().'/delete-test-'.uniqid();
        mkdir("{$projectDir}/subdir", 0755, true);
        file_put_contents("{$projectDir}/file.txt", 'test');
        file_put_contents("{$projectDir}/subdir/nested.txt", 'nested');

        $context = new DeletionContext(
            slug: 'delete-test',
            projectPath: $projectDir,
        );

        $logger = new DeletionLogger('delete-test');
        $action = new DeleteProjectFiles;
        $result = $action->handle($context, $logger);

        expect($result->isSuccess())->toBeTrue();
        expect(is_dir($projectDir))->toBeFalse();
    });

    it('succeeds when directory does not exist', function () {
        $context = new DeletionContext(
            slug: 'nonexistent',
            projectPath: '/tmp/nonexistent-dir-'.uniqid(),
        );

        $logger = new DeletionLogger('nonexistent');
        $action = new DeleteProjectFiles;
        $result = $action->handle($context, $logger);

        expect($result->isSuccess())->toBeTrue();
        expect($result->data['skipped'] ?? false)->toBeTrue();
    });

    it('succeeds when no project path specified', function () {
        $context = new DeletionContext(
            slug: 'no-path',
            projectPath: '',
        );

        $logger = new DeletionLogger('no-path');
        $action = new DeleteProjectFiles;
        $result = $action->handle($context, $logger);

        expect($result->isSuccess())->toBeTrue();
        expect($result->data['skipped'] ?? false)->toBeTrue();
    });
});

describe('DropPostgresDatabase', function () {
    it('skips when not using postgres', function () {
        $context = new DeletionContext(
            slug: 'mysql-site',
            projectPath: '/tmp/mysql-site',
            dbConnection: 'mysql',
            dbName: 'mysql_db',
        );

        $logger = new DeletionLogger('mysql-site');
        $action = new DropPostgresDatabase;
        $result = $action->handle($context, $logger);

        expect($result->isSuccess())->toBeTrue();
        expect($result->data['skipped'] ?? false)->toBeTrue();
    });

    it('skips when no database connection configured', function () {
        $context = new DeletionContext(
            slug: 'no-db',
            projectPath: '/tmp/no-db',
            dbConnection: null,
        );

        $logger = new DeletionLogger('no-db');
        $action = new DropPostgresDatabase;
        $result = $action->handle($context, $logger);

        expect($result->isSuccess())->toBeTrue();
        expect($result->data['skipped'] ?? false)->toBeTrue();
    });

    it('skips when no database name', function () {
        $context = new DeletionContext(
            slug: 'no-dbname',
            projectPath: '/tmp/no-dbname',
            dbConnection: 'pgsql',
            dbName: null,
        );

        $logger = new DeletionLogger('no-dbname');
        $action = new DropPostgresDatabase;
        $result = $action->handle($context, $logger);

        expect($result->isSuccess())->toBeTrue();
        expect($result->data['skipped'] ?? false)->toBeTrue();
    });
});

describe('DeletionPipeline', function () {
    it('runs all steps in sequence', function () {
        // Create a temp directory
        $projectDir = sys_get_temp_dir().'/pipeline-test-'.uniqid();
        mkdir($projectDir, 0755, true);
        file_put_contents("{$projectDir}/.env", "DB_CONNECTION=sqlite\n");

        $context = new DeletionContext(
            slug: 'pipeline-test',
            projectPath: $projectDir,
            keepDatabase: false,
        );
        $context = $context->withDatabaseFromEnv();

        $logger = new DeletionLogger('pipeline-test');
        $pipeline = new DeletionPipeline;
        $result = $pipeline->run($context, $logger);

        expect($result->isSuccess())->toBeTrue();
        // Directory should be deleted
        expect(is_dir($projectDir))->toBeFalse();
    });

    it('skips database deletion when keepDatabase is true', function () {
        // Create a temp directory
        $projectDir = sys_get_temp_dir().'/keep-db-pipeline-'.uniqid();
        mkdir($projectDir, 0755, true);

        $context = new DeletionContext(
            slug: 'keep-db-pipeline',
            projectPath: $projectDir,
            keepDatabase: true,
            dbConnection: 'pgsql',
            dbName: 'testdb',
        );

        $logger = new DeletionLogger('keep-db-pipeline');
        $pipeline = new DeletionPipeline;
        $result = $pipeline->run($context, $logger);

        expect($result->isSuccess())->toBeTrue();
    });
});

describe('DeletionLogger', function () {
    it('implements ProvisionLoggerContract', function () {
        $logger = new DeletionLogger('test-slug', 123);

        expect($logger)->toBeInstanceOf(\HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract::class);
        expect($logger->getSlug())->toBe('test-slug');
        expect($logger->getProjectId())->toBe(123);
    });
});
