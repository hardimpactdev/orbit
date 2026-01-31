<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Controllers;

use HardImpact\Orbit\Core\Jobs\CreateProjectJob;
use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Models\Project;
use HardImpact\Orbit\Core\Models\TemplateFavorite;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\ProjectCliService;
use HardImpact\Orbit\Core\Services\SshService;
use HardImpact\Orbit\Core\Services\TemplateAnalyzer\EnvParser;
use HardImpact\Orbit\App\Http\Controllers\Concerns\HandlesGitHubIntegration;
use HardImpact\Orbit\App\Http\Controllers\Concerns\ProvidesRemoteApiUrl;
use Illuminate\Http\Request;

/**
 * Controller for project operations within a specific environment.
 *
 * Routes are prefixed with /environments/{environment}/ and the environment
 * is explicitly passed as a route parameter.
 *
 * @see ProjectController for operations using the "active environment" pattern
 */
class EnvironmentProjectController extends Controller
{
    use HandlesGitHubIntegration;
    use ProvidesRemoteApiUrl;

    public function __construct(
        protected ProjectCliService $project,
        protected ConfigurationService $config,
        protected SshService $ssh,
        protected EnvParser $envParser,
    ) {}

    /**
     * Get the SSH service instance (required by HandlesGitHubIntegration).
     */
    protected function getSshService(): SshService
    {
        return $this->ssh;
    }

    /**
     * Projects page (Inertia view).
     */
    public function index(Environment $environment): \Inertia\Response
    {
        $editor = $environment->getEditor();
        $remoteApiUrl = $this->getRemoteApiUrl($environment);
        $reverb = $this->config->getReverbConfig($environment);

        return \Inertia\Inertia::render('environments/Projects', [
            'environment' => $environment,
            'editor' => $editor,
            'remoteApiUrl' => $remoteApiUrl,
            'reverb' => $reverb['success'] ? [
                'enabled' => $reverb['enabled'] ?? false,
                'host' => $reverb['host'] ?? null,
                'port' => $reverb['port'] ?? null,
                'scheme' => $reverb['scheme'] ?? null,
                'app_key' => $reverb['app_key'] ?? null,
            ] : [
                'enabled' => false,
            ],
        ]);
    }

    /**
     * Projects API (JSON).
     */
    public function indexApi(Environment $environment)
    {
        return response()->json($this->project->projectList($environment));
    }

    /**
     * Sync projects from CLI to database.
     */
    public function syncApi(Environment $environment)
    {
        $result = $this->project->syncAllProjectsFromCli($environment);

        if (! $result['success']) {
            return response()->json($result, 500);
        }

        // Return fresh project list after sync
        return response()->json($this->project->projectList($environment));
    }

    /**
     * Show the create project form.
     */
    public function create(Environment $environment): \Inertia\Response
    {
        $recentTemplates = TemplateFavorite::orderByDesc('last_used_at')
            ->limit(5)
            ->get();

        return \Inertia\Inertia::render('environments/projects/ProjectCreate', [
            'environment' => $environment,
            'recentTemplates' => $recentTemplates,
        ]);
    }

    /**
     * Store a newly created project.
     */
    public function store(Request $request, Environment $environment)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'org' => 'nullable|string|max:255', // GitHub org/user to create repo under
            'template' => 'nullable|string|max:500',
            'is_template' => 'boolean', // Whether the repo is a GitHub template (vs regular repo to clone)
            'fork' => 'boolean', // Whether to fork (true) or import as new repo (false) when cloning non-matching repo
            'visibility' => 'nullable|in:private,public',
            // PHP version
            'php_version' => 'nullable|in:8.3,8.4,8.5',
            // Driver options
            'db_driver' => 'nullable|in:sqlite,pgsql',
            'session_driver' => 'nullable|in:file,database,redis',
            'cache_driver' => 'nullable|in:file,database,redis',
            'queue_driver' => 'nullable|in:sync,database,redis',
        ]);

        // Note: GitHub user lookup and repo existence checks are done on the frontend
        // in real-time as the user types. The submit button is disabled until validation passes.
        // This avoids duplicate SSH/API calls that would add ~1s latency.

        $isTemplate = ! empty($validated['is_template']);
        $providedRepo = $validated['template'] ?? null;

        // Determine if this is an import scenario for fork logic below
        $isImportScenario = ! empty($providedRepo) && ! $isTemplate;

        // Save template as favorite if provided, including driver preferences
        if (! empty($validated['template'])) {
            $template = TemplateFavorite::firstOrCreate(
                ['repo_url' => $validated['template']],
                ['display_name' => $this->extractTemplateName($validated['template'])]
            );
            $template->recordUsage([
                'db_driver' => $validated['db_driver'] ?? null,
                'session_driver' => $validated['session_driver'] ?? null,
                'cache_driver' => $validated['cache_driver'] ?? null,
                'queue_driver' => $validated['queue_driver'] ?? null,
            ]);
        }

        // Build options for the job
        // @see /docs/flows/project-creation.md
        $projectOptions = [
            'name' => $validated['name'],
            'org' => $validated['org'] ?? null,
            'template' => $validated['template'] ?? null,
            'is_template' => $validated['is_template'] ?? false,
            'fork' => $isImportScenario ? ($validated['fork'] ?? false) : false,
            'visibility' => $validated['visibility'] ?? 'private',
            'php_version' => $validated['php_version'] ?? null,
            'db_driver' => $validated['db_driver'] ?? null,
            'session_driver' => $validated['session_driver'] ?? null,
            'cache_driver' => $validated['cache_driver'] ?? null,
            'queue_driver' => $validated['queue_driver'] ?? null,
        ];

        $projectSlug = \Illuminate\Support\Str::slug($validated['name']);
        $config = $this->config->getConfig($environment);
        $paths = $config['success'] ? ($config['data']['paths'] ?? []) : [];
        $basePath = $paths[0] ?? '~/projects';
        $projectPath = rtrim((string) $basePath, '/').'/'.$projectSlug;

        // Create project in database
        $project = Project::create([
            'environment_id' => $environment->id,
            'name' => $validated['name'],
            'display_name' => $validated['name'],
            'slug' => $projectSlug,
            'path' => $projectPath,
            'php_version' => $validated['php_version'] ?? config('orbit-ui.php.default', '8.4'),
            'github_repo' => $validated['template'] ?? null,
            'has_public_folder' => false,
            'status' => Project::STATUS_QUEUED,
        ]);

        // 2. Dispatch job
        CreateProjectJob::dispatch($project->id, $projectOptions);

        // API requests get 200 OK
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Project creation queued',
                'slug' => $projectSlug,
                'project' => $project,
            ]);
        }

        // Web requests get redirect with provisioning slug for WebSocket tracking
        return redirect()->route('environments.projects', ['environment' => $environment->id])
            ->with([
                'provisioning' => $projectSlug,
                'success' => "Project '{$validated['name']}' is being created...",
            ]);
    }

    /**
     * Delete a project from the environment.
     */
    public function destroy(Request $request, Environment $environment, string $projectName)
    {
        $keepDb = $request->boolean('keep_db', false);

        $result = $this->project->deleteProject($environment, $projectName, force: true, keepDb: $keepDb);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to delete project',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => "Project '{$projectName}' deleted successfully",
        ]);
    }

    /**
     * Rebuild a project (re-run composer install, npm install, build, migrations).
     */
    public function rebuild(Request $request, Environment $environment, string $projectName)
    {
        $result = $this->project->rebuild($environment, $projectName);

        return response()->json($result);
    }

    /**
     * Get the provisioning status of a project.
     */
    public function provisionStatus(Environment $environment, string $projectSlug)
    {
        $result = $this->project->provisionStatus($environment, $projectSlug);

        return response()->json($result);
    }

    /**
     * Get the GitHub user for an environment.
     */
    public function githubUser(Environment $environment)
    {
        $user = $this->project->getGitHubUser($environment);

        return response()->json([
            'success' => $user !== null,
            'user' => $user,
        ]);
    }

    /**
     * Get GitHub organizations for an environment.
     */
    public function githubOrgs(Environment $environment)
    {
        $result = $this->project->getGitHubOrgs($environment);

        return response()->json($result);
    }

    /**
     * Check if a GitHub repository already exists.
     * Used for real-time validation while user types project name.
     */
    public function githubRepoExists(Environment $environment, Request $request)
    {
        $request->validate([
            'repo' => 'required|string',
        ]);

        $repo = $request->input('repo');
        $result = $this->project->checkGitHubRepoExists($environment, $repo);

        return response()->json([
            'success' => true,
            'exists' => $result['exists'],
            'error' => $result['error'] ?? null,
        ]);
    }

    /**
     * Analyze a GitHub template to detect project type and extract driver defaults.
     *
     * Uses gh CLI on the environment to access both public and private repos.
     */
    public function templateDefaults(Request $request, Environment $environment)
    {
        $validated = $request->validate([
            'template' => 'required|string|max:500',
        ]);

        $repo = $this->extractRepoFromTemplate($validated['template']);
        if (! $repo) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid template format. Use owner/repo or a GitHub URL.',
            ]);
        }

        // Check if repo is a template and get repo metadata
        $repoInfo = $this->getRepoInfoViaGh($environment, $repo);
        if ($repoInfo === null) {
            return response()->json([
                'success' => false,
                'error' => 'Could not access repository. Check if it exists and you have access.',
            ]);
        }

        $isTemplate = $repoInfo['is_template'] ?? false;
        $defaultBranch = $repoInfo['default_branch'] ?? 'main';

        // Fetch .env.example via gh CLI on the environment
        $envContent = $this->fetchFileViaGh($environment, $repo, '.env.example');

        // Parse the .env.example if it exists
        $drivers = [
            'db_driver' => null,
            'session_driver' => null,
            'cache_driver' => null,
            'queue_driver' => null,
        ];

        if ($envContent !== null) {
            $envVars = $this->envParser->parse($envContent);

            $drivers = [
                'db_driver' => $this->normalizeDriver($envVars['DB_CONNECTION'] ?? null),
                'session_driver' => $this->normalizeDriver($envVars['SESSION_DRIVER'] ?? null),
                'cache_driver' => $this->normalizeDriver($envVars['CACHE_STORE'] ?? $envVars['CACHE_DRIVER'] ?? null),
                'queue_driver' => $this->normalizeDriver($envVars['QUEUE_CONNECTION'] ?? null),
            ];
        }

        // Fetch composer.json to detect framework and PHP version
        $composerContent = $this->fetchFileViaGh($environment, $repo, 'composer.json');
        $metadata = [
            'framework' => 'unknown',
            'is_template' => $isTemplate,
            'default_branch' => $defaultBranch,
            'repo' => $repo,
            'min_php_version' => null,
            'recommended_php_version' => '8.5', // Default to latest
        ];

        if ($composerContent !== null) {
            $composer = json_decode($composerContent, true);
            if (is_array($composer)) {
                if (isset($composer['require']['laravel/framework'])) {
                    $metadata['framework'] = 'laravel';
                }

                // Extract PHP version info from composer.json
                if (isset($composer['require']['php'])) {
                    $phpConstraint = $composer['require']['php'];
                    $metadata['min_php_version'] = $this->extractMinPhpVersion($phpConstraint);
                    $metadata['recommended_php_version'] = $this->getRecommendedPhpVersion($phpConstraint);
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'type' => $metadata['framework'],
                'is_template' => $isTemplate,
                'drivers' => $drivers,
                'metadata' => $metadata,
            ],
        ]);
    }
}
