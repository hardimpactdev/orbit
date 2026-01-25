<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Deletion\Actions;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use HardImpact\Orbit\Core\Data\DeletionContext;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

/**
 * Action to drop a PostgreSQL database.
 *
 * Terminates active connections before dropping the database
 * to ensure clean removal.
 */
final readonly class DropPostgresDatabase
{
    public function handle(DeletionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        // Skip if not using PostgreSQL
        if (! $context->usesPostgres()) {
            if ($context->dbConnection) {
                $logger->info("Project uses {$context->dbConnection}, not PostgreSQL - skipping database drop");
            } else {
                $logger->info('No database connection configured - skipping database drop');
            }

            return StepResult::success(['skipped' => true]);
        }

        $database = $context->dbName;
        if (! $database) {
            $logger->info('No database name found - skipping database drop');

            return StepResult::success(['skipped' => true]);
        }

        $logger->info("Dropping PostgreSQL database: {$database}");

        // Check if PostgreSQL container is running
        $containerCheck = Process::run(
            "docker ps --filter name=orbit-postgres --format '{{.Names}}' 2>&1"
        );

        if (! str_contains($containerCheck->output(), 'orbit-postgres')) {
            $logger->warn('PostgreSQL container not running - skipping database drop');

            return StepResult::success(['skipped' => true, 'reason' => 'container_not_running']);
        }

        // Check if database exists
        $checkResult = Process::run(
            "docker exec orbit-postgres psql -U orbit -tAc \"SELECT 1 FROM pg_database WHERE datname='{$database}'\" 2>&1"
        );

        if (! str_contains($checkResult->output(), '1')) {
            $logger->info("Database '{$database}' does not exist - nothing to drop");

            return StepResult::success(['skipped' => true, 'reason' => 'database_not_exists']);
        }

        // Terminate active connections to the database
        $logger->info('Terminating active database connections...');
        Process::run(
            "docker exec orbit-postgres psql -U orbit -c \"SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$database}' AND pid <> pg_backend_pid();\" 2>&1"
        );

        // Drop the database
        $result = Process::run(
            "docker exec orbit-postgres psql -U orbit -c \"DROP DATABASE IF EXISTS \\\"{$database}\\\";\" 2>&1"
        );

        if ($result->successful()) {
            $logger->info("Database '{$database}' dropped successfully");

            return StepResult::success(['database' => $database]);
        }

        return StepResult::failed('Failed to drop database: '.trim($result->output()));
    }
}
