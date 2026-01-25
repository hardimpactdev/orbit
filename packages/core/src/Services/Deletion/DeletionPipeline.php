<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Deletion;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use HardImpact\Orbit\Core\Data\DeletionContext;
use HardImpact\Orbit\Core\Data\StepResult;
use HardImpact\Orbit\Core\Services\Deletion\Actions\DeleteProjectFiles;
use HardImpact\Orbit\Core\Services\Deletion\Actions\DropPostgresDatabase;
use HardImpact\Orbit\Core\Services\Deletion\Actions\RegenerateCaddyConfig;

/**
 * Pipeline for running deletion actions in sequence.
 *
 * Orchestrates the complete project deletion process including:
 * - Database cleanup
 * - Project file deletion
 * - Caddy configuration regeneration
 *
 * Note: Project model deletion is handled by the caller (CLI or Job)
 * to allow for transaction control.
 */
class DeletionPipeline
{
    /**
     * Run the full deletion pipeline.
     */
    public function run(DeletionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        $logger->info("Starting deletion pipeline for: {$context->slug}");

        // Step 1: Drop database (unless keepDatabase flag is set)
        if (! $context->keepDatabase) {
            $logger->broadcast('dropping_database');
            $result = app(DropPostgresDatabase::class)->handle($context, $logger);
            // Database errors are non-fatal - log warning and continue
            if ($result->isFailed()) {
                $logger->warn("Database drop failed (continuing): {$result->error}");
            }
        } else {
            $logger->info('Skipping database deletion (--keep-db flag)');
        }

        // Step 2: Delete project files
        $logger->broadcast('removing_files');
        $result = app(DeleteProjectFiles::class)->handle($context, $logger);
        if ($result->isFailed()) {
            return $result;
        }

        // Step 3: Regenerate Caddy config (remove site from Caddyfile)
        $logger->broadcast('regenerating_caddy');
        $result = app(RegenerateCaddyConfig::class)->handle($context, $logger);
        // Caddy errors are non-fatal - log warning but don't fail pipeline
        if ($result->isFailed()) {
            $logger->warn("Caddy regeneration failed (continuing): {$result->error}");
        }

        // Future: Step 4 - Delete GitHub repository (when keepRepository=false)
        // if (!$context->keepRepository && $context->githubRepo) {
        //     $result = app(DeleteGitHubRepository::class)->handle($context, $logger);
        // }

        $logger->info('Deletion pipeline completed');

        return StepResult::success();
    }
}
