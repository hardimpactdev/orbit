<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Jobs;

use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Enums\RepoIntent;
use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Models\Project;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\Provision\ProvisionLogger;
use HardImpact\Orbit\Core\Services\Provision\ProvisionPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Job for creating a new project with native Laravel broadcasting.
 *
 * This job handles the complete project provisioning process:
 * - Repository operations (clone, fork, template)
 * - Dependency installation
 * - Environment configuration
 * - Database setup
 *
 * Status updates are broadcast via native Laravel events to Reverb.
 */
class CreateProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600; // 10 minutes for provisioning

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1; // Don't retry - provisioning is not idempotent

    protected string $slug;

    public function __construct(
        protected int $projectId,
        protected array $options,
    ) {
        $this->slug = Str::slug($options['name']);
    }

    /**
     * Execute the job.
     */
    public function handle(ProvisionPipeline $pipeline, ConfigurationService $configService): void
    {
        $project = Project::findOrFail($this->projectId);
        $environment = Environment::findOrFail($project->environment_id);

        // Initialize logger with native Laravel broadcasting
        $logger = new ProvisionLogger($this->slug, $this->projectId);

        $project->update([
            'status' => Project::STATUS_QUEUED,
            'job_id' => $this->job?->uuid() ?? $project->job_id,
            'error_message' => null,
        ]);

        $logger->broadcast('provisioning');
        Log::info("CreateProjectJob: Starting project creation for {$this->slug}");

        try {
            // Determine project path
            $projectPath = $this->determineProjectPath($project, $configService, $environment);

            // Create project directory if it doesn't exist
            if (! is_dir($projectPath)) {
                if (! mkdir($projectPath, 0755, true)) {
                    throw new \RuntimeException("Failed to create directory: {$projectPath}");
                }
            }

            // Update project with path
            $project->update(['path' => $projectPath]);

            // Build provision context
            $context = $this->buildContext($projectPath, $environment);

            // Determine repo intent
            $intent = RepoIntent::fromPayload($this->options);

            // Phase 1: Repository Operations
            $context = $this->handleRepositoryOperations($context, $intent, $pipeline, $logger);

            // Phase 2: Clone repository (for clone/fork/template flows)
            if ($context->cloneUrl) {
                $logger->broadcast('cloning');
                $result = $pipeline->cloneRepository($context, $logger);
                if ($result->isFailed()) {
                    throw new \RuntimeException($result->error ?? 'Clone failed');
                }
            }

            // Phase 3: Run provision pipeline
            $logger->broadcast('setting_up');
            $result = $pipeline->run($context, $logger);

            if ($result->isFailed()) {
                throw new \RuntimeException($result->error ?? 'Provisioning failed');
            }

            // Phase 4: Finalize
            $logger->broadcast('finalizing');

            // Detect project type and public folder
            $hasPublicFolder = is_dir("{$projectPath}/public");
            $projectType = $this->detectProjectType($projectPath);

            // Update project with final details
            $project->update([
                'status' => Project::STATUS_READY,
                'github_repo' => $context->githubRepo,
                'url' => "https://{$this->slug}.{$context->tld}",
                'domain' => "{$this->slug}.{$context->tld}",
                'has_public_folder' => $hasPublicFolder,
                'project_type' => $projectType,
                'error_message' => null,
            ]);

            // Broadcast ready BEFORE Caddy reload to ensure event is received
            // (Caddy reload may temporarily drop WebSocket connections)
            $logger->broadcast('ready');

            // Regenerate Caddyfile and reload Caddy (runs on host via systemd)
            if ($hasPublicFolder) {
                $this->regenerateCaddy($logger);
            }
            Log::info("CreateProjectJob: Project {$this->slug} created successfully");

        } catch (\Throwable $e) {
            $project->update([
                'status' => Project::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            $logger->broadcast('failed', $e->getMessage());
            Log::error("CreateProjectJob: Project {$this->slug} creation failed", [
                'error' => $e->getMessage(),
            ]);

            // Cleanup empty directory
            if (isset($projectPath) && is_dir($projectPath) && ! glob("{$projectPath}/*")) {
                @rmdir($projectPath);
            }

            throw $e;
        }
    }

    /**
     * Determine the project path for the project.
     */
    protected function determineProjectPath(Project $project, ConfigurationService $configService, Environment $environment): string
    {
        // If path already set on project, use it
        if ($project->path) {
            return $project->path;
        }

        // If directory option provided, use it
        if (! empty($this->options['directory'])) {
            return $this->expandPath($this->options['directory']);
        }

        // Get default path from config
        $config = $configService->getConfig($environment);
        $paths = $config['data']['paths'] ?? [];
        $basePath = $paths[0] ?? '~/projects';

        return $this->expandPath("{$basePath}/{$this->slug}");
    }

    /**
     * Build the provision context from options.
     */
    protected function buildContext(string $projectPath, Environment $environment): ProvisionContext
    {
        // Get TLD from environment
        $tld = $environment->tld ?? 'ccc';

        // Parse clone URL if provided
        $cloneUrl = $this->options['template'] ?? $this->options['clone_url'] ?? null;

        return new ProvisionContext(
            slug: $this->slug,
            projectPath: $projectPath,
            projectId: $this->projectId,
            cloneUrl: $cloneUrl,
            template: ($this->options['is_template'] ?? false) ? $cloneUrl : null,
            visibility: $this->options['visibility'] ?? 'private',
            phpVersion: $this->options['php_version'] ?? null,
            dbDriver: $this->options['db_driver'] ?? null,
            sessionDriver: $this->options['session_driver'] ?? null,
            cacheDriver: $this->options['cache_driver'] ?? null,
            queueDriver: $this->options['queue_driver'] ?? null,
            fork: $this->options['fork'] ?? false,
            displayName: $this->options['name'] ?? null,
            tld: $tld,
            organization: $this->options['org'] ?? null,
        );
    }

    /**
     * Handle repository operations (fork/template creation).
     */
    protected function handleRepositoryOperations(
        ProvisionContext $context,
        RepoIntent $intent,
        ProvisionPipeline $pipeline,
        ProvisionLogger $logger
    ): ProvisionContext {
        $github = $pipeline->getGitHubService();

        // Fork flow
        if ($intent === RepoIntent::Fork && $context->cloneUrl) {
            $result = $pipeline->forkRepository($context, $logger);
            if ($result->isFailed()) {
                throw new \RuntimeException($result->error ?? 'Fork failed');
            }

            return $context->withRepoInfo(
                $result->data['repo'] ?? null,
                $result->data['cloneUrl'] ?? null
            );
        }

        // Template flow
        if ($intent === RepoIntent::Template && $context->template) {
            $owner = $context->getGitHubOwner($github->getUsername());
            if (! $owner) {
                throw new \RuntimeException('Could not determine GitHub username for template');
            }

            $targetRepo = "{$owner}/{$context->slug}";
            $result = $pipeline->createFromTemplate($context, $logger, $targetRepo);

            if ($result->isFailed()) {
                throw new \RuntimeException($result->error ?? 'Template creation failed');
            }

            return $context->withRepoInfo(
                $result->data['repo'] ?? null,
                $result->data['cloneUrl'] ?? null
            );
        }

        return $context;
    }

    /**
     * Expand ~ to home directory.
     */
    protected function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            $home = $_SERVER['HOME'] ?? '/home/orbit';

            return $home.substr($path, 1);
        }

        return $path;
    }

    /**
     * Get the slug for this project.
     */
    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * Get the tags for the job (for Horizon).
     */
    public function tags(): array
    {
        return [
            'create-project',
            "project:{$this->slug}",
            "project-id:{$this->projectId}",
        ];
    }

    /**
     * Detect the project type based on file structure.
     */
    protected function detectProjectType(string $directory): string
    {
        $hasPublicFolder = is_dir("{$directory}/public");
        $hasArtisan = file_exists("{$directory}/artisan");
        $composerJson = "{$directory}/composer.json";

        if (file_exists($composerJson)) {
            $composer = json_decode(file_get_contents($composerJson), true);

            // Check if it's a Laravel package
            $type = $composer['type'] ?? null;
            if ($type === 'library' || $type === 'laravel-package') {
                return 'laravel-package';
            }

            // Check for package indicators in composer.json
            $extra = $composer['extra'] ?? [];
            if (isset($extra['laravel']['providers']) || isset($extra['laravel']['aliases'])) {
                return 'laravel-package';
            }

            // Check for Laravel Zero CLI apps
            if (isset($composer['require']['laravel-zero/framework'])) {
                return 'cli';
            }
        }

        // Laravel web application
        if ($hasPublicFolder && $hasArtisan) {
            return 'laravel-app';
        }

        // Laravel Zero or other CLI app
        if ($hasArtisan) {
            return 'cli';
        }

        // Generic PHP project with web interface
        if ($hasPublicFolder) {
            return 'web';
        }

        return 'unknown';
    }

    /**
     * Regenerate Caddyfile and reload Caddy.
     *
     * Caddy runs on the host via systemd, not in Docker.
     * Uses the CLI's caddy:reload command which regenerates the Caddyfile
     * and reloads Caddy in one step.
     */
    protected function regenerateCaddy(ProvisionLogger $logger): void
    {
        $logger->info('Regenerating Caddy configuration...');

        $cliPath = config('orbit.cli_path', '/usr/local/bin/orbit');
        $result = \Illuminate\Support\Facades\Process::timeout(30)
            ->run("{$cliPath} caddy:reload --json 2>/dev/null");

        if ($result->successful()) {
            $output = json_decode($result->output(), true);
            if ($output['success'] ?? false) {
                $logger->info('Caddy configuration reloaded');

                return;
            }
        }

        $logger->info('Warning: Could not reload Caddy - you may need to reload manually');
    }
}
