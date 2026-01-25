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
        if ($context->dbDriver !== 'pgsql') {
            $logger->log('Skipping PostgreSQL database creation - not using pgsql driver');

            return StepResult::success();
        }

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
}
