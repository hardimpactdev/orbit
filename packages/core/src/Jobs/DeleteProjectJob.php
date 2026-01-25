<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Jobs;

use HardImpact\Orbit\Core\Data\DeletionContext;
use HardImpact\Orbit\Core\Models\Project;
use HardImpact\Orbit\Core\Services\Deletion\DeletionLogger;
use HardImpact\Orbit\Core\Services\Deletion\DeletionPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job for deleting a project via queue (web-initiated).
 *
 * This job handles the complete project deletion process:
 * - Database cleanup
 * - Project files removal
 * - Caddy configuration regeneration
 * - Project record deletion
 *
 * Status updates are broadcast via native Laravel events to Reverb.
 */
final class DeleteProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1; // Don't retry - deletion is not idempotent

    public function __construct(
        public int $projectId,
        public bool $keepDatabase = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(DeletionPipeline $pipeline): void
    {
        $project = Project::findOrFail($this->projectId);

        // Initialize logger with native Laravel broadcasting
        $logger = new DeletionLogger($project->slug, $this->projectId);

        Log::info("DeleteProjectJob: Starting deletion for {$project->slug}");
        $logger->broadcast('deleting');

        try {
            // Build deletion context from project
            $context = DeletionContext::fromProject($project, $this->keepDatabase)
                ->withDatabaseFromEnv();

            // Run the deletion pipeline
            $result = $pipeline->run($context, $logger);

            if ($result->isFailed()) {
                throw new \RuntimeException($result->error ?? 'Deletion pipeline failed');
            }

            // Delete project record from database
            $project->delete();

            $logger->broadcast('deleted');
            Log::info("DeleteProjectJob: Project {$project->slug} deleted successfully");

        } catch (\Throwable $e) {
            $logger->broadcast('delete_failed', $e->getMessage());
            Log::error("DeleteProjectJob: Project {$project->slug} deletion failed", [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get the tags for the job (for Horizon).
     */
    public function tags(): array
    {
        return [
            'delete-project',
            "project-id:{$this->projectId}",
        ];
    }
}
