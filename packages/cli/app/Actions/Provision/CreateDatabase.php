<?php

declare(strict_types=1);

namespace App\Actions\Provision;

use App\Data\Provision\ProvisionContext;
use App\Data\Provision\StepResult;
use App\Services\ProvisionLogger;
use Illuminate\Support\Facades\Process;

final readonly class CreateDatabase
{
    public function handle(ProvisionContext $context, ProvisionLogger $logger): StepResult
    {
        return match ($context->dbDriver) {
            'pgsql' => $this->createPostgresDatabase($context, $logger),
            'mysql' => $this->createMysqlDatabase($context, $logger),
            default => $this->skipDatabaseCreation($context->dbDriver, $logger),
        };
    }

    private function skipDatabaseCreation(string $driver, ProvisionLogger $logger): StepResult
    {
        $logger->log("Skipping database creation - driver '{$driver}' doesn't require it");

        return StepResult::success();
    }

    private function createPostgresDatabase(ProvisionContext $context, ProvisionLogger $logger): StepResult
    {
        $logger->info("Creating PostgreSQL database: {$context->slug}");

        // Check if PostgreSQL container is running
        $containerCheck = Process::run(
            "docker ps --filter name=orbit-postgres --format '{{.Names}}' 2>&1"
        );

        if (! str_contains($containerCheck->output(), 'orbit-postgres')) {
            $logger->warn('PostgreSQL container not running, skipping database creation');

            return StepResult::success();
        }

        $slug = $context->slug;

        // Check if database already exists
        $checkResult = Process::run(
            "docker exec orbit-postgres psql -U orbit -tAc \"SELECT 1 FROM pg_database WHERE datname='{$slug}'\" 2>&1"
        );

        if (str_contains($checkResult->output(), '1')) {
            $logger->info('Database already exists');

            return StepResult::success();
        }

        // Create database
        $result = Process::run(
            "docker exec orbit-postgres psql -U orbit -c \"CREATE DATABASE \\\"{$slug}\\\";\" 2>&1"
        );

        if ($result->successful()) {
            $logger->info('PostgreSQL database created successfully');

            return StepResult::success();
        }

        // Log the error but don't fail - migrations will fail more clearly if db doesn't exist
        $logger->warn('Failed to create database: '.trim($result->output()));

        return StepResult::success();
    }

    private function createMysqlDatabase(ProvisionContext $context, ProvisionLogger $logger): StepResult
    {
        $logger->info("Creating MySQL database: {$context->slug}");

        // Check if MySQL container is running
        $containerCheck = Process::run(
            "docker ps --filter name=orbit-mysql --format '{{.Names}}' 2>&1"
        );

        if (! str_contains($containerCheck->output(), 'orbit-mysql')) {
            $logger->warn('MySQL container not running, skipping database creation');

            return StepResult::success();
        }

        $slug = $context->slug;

        // Check if database already exists
        $checkResult = Process::run(
            "docker exec orbit-mysql mysql -uorbit -psecret -e \"SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='{$slug}'\" 2>&1"
        );

        if (str_contains($checkResult->output(), $slug)) {
            $logger->info('Database already exists');

            return StepResult::success();
        }

        // Create database
        $result = Process::run(
            "docker exec orbit-mysql mysql -uorbit -psecret -e \"CREATE DATABASE \`{$slug}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\" 2>&1"
        );

        if ($result->successful() || str_contains($result->output(), 'database exists')) {
            $logger->info('MySQL database created successfully');

            return StepResult::success();
        }

        // Log the error but don't fail - migrations will fail more clearly if db doesn't exist
        $logger->warn('Failed to create database: '.trim($result->output()));

        return StepResult::success();
    }
}
