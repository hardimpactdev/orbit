<?php

namespace App\Http\Controllers;

use App\Models\Environment;
use App\Models\Setting;
use App\Models\TemplateFavorite;
use App\Services\DnsResolverService;
use App\Services\DoctorService;
use App\Services\OrbitCli\ConfigurationService;
use App\Services\OrbitCli\OrchestratorService;
use App\Services\OrbitCli\PackageService;
use App\Services\OrbitCli\ProjectService;
use App\Services\OrbitCli\ServiceControlService;
use App\Services\OrbitCli\StatusService;
use App\Services\OrbitCli\WorkspaceService;
use App\Services\OrbitCli\WorktreeService;
use App\Services\MacPhpFpmConfigService;
use App\Services\SshService;
use Illuminate\Http\Request;

class EnvironmentController extends Controller
{
    public function __construct(
        protected SshService $ssh,
        protected StatusService $status,
        protected ServiceControlService $serviceControl,
        protected ConfigurationService $config,
        protected ProjectService $project,
        protected WorktreeService $worktree,
        protected OrchestratorService $orchestrator,
        protected WorkspaceService $workspace,
        protected PackageService $package,
        protected DnsResolverService $dnsResolver,
        protected DoctorService $doctor,
        protected MacPhpFpmConfigService $macPhpFpm,
    ) {}

    /**
     * Get the remote API URL for direct frontend calls.
     * For remote environments with a known TLD, returns https://orbit.{tld}/api
     * This allows the frontend to bypass the single-threaded NativePHP server.
     */
    protected function getRemoteApiUrl(Environment $environment): ?string
    {
        if (! $environment->is_local && $environment->tld) {
            return "https://orbit.{$environment->tld}/api";
        }

        return null;
    }

    public function index(): \Inertia\Response
    {
        $environments = Environment::all();
        $hasLocalEnvironment = Environment::where('is_local', true)->exists();

        return \Inertia\Inertia::render('environments/Index', [
            'environments' => $environments,
            'hasLocalEnvironment' => $hasLocalEnvironment,
        ]);
    }

    public function create(): \Inertia\Response
    {
        $sshKeys = \App\Models\SshKey::orderBy('is_default', 'desc')->orderBy('name')->get();
        $availableSshKeys = Setting::getAvailableSshKeys();
        $hasLocalEnvironment = Environment::where('is_local', true)->exists();

        return \Inertia\Inertia::render('environments/Create', [
            'currentUser' => get_current_user(),
            'sshKeys' => $sshKeys,
            'availableSshKeys' => $availableSshKeys,
            'hasLocalEnvironment' => $hasLocalEnvironment,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'user' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'is_local' => 'boolean',
        ]);

        // Prevent creating more than one local environment
        if (($validated['is_local'] ?? false) && Environment::where('is_local', true)->exists()) {
            return redirect()->route('environments.index')
                ->with('error', 'A local environment already exists.');
        }

        $environment = Environment::create($validated);

        return redirect()->route('environments.index')
            ->with('success', "Environment '{$environment->name}' added successfully.");
    }

    public function show(Environment $environment): \Inertia\Response
    {
        // If environment is being provisioned or has error, show provisioning view
        if ($environment->isProvisioning() || $environment->hasError()) {
            $sshPublicKey = Setting::getSshPublicKey();

            return \Inertia\Inertia::render('environments/Provisioning', [
                'environment' => $environment,
                'sshPublicKey' => $sshPublicKey,
            ]);
        }

        // Only check installation synchronously (fast), load status/sites via AJAX
        $installation = $this->status->checkInstallation($environment);
        $editor = Setting::getEditor();
        $remoteApiUrl = $this->getRemoteApiUrl($environment);

        return \Inertia\Inertia::render('environments/Show', [
            'environment' => $environment,
            'installation' => $installation,
            'editor' => $editor,
            'remoteApiUrl' => $remoteApiUrl,
        ]);
    }

    public function edit(Environment $environment): \Inertia\Response
    {
        return \Inertia\Inertia::render('environments/Edit', [
            'environment' => $environment,
        ]);
    }

    public function update(Request $request, Environment $environment)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'user' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'is_local' => 'boolean',
        ]);

        // Prevent converting to local if another local environment exists
        if (($validated['is_local'] ?? false) && ! $environment->is_local && Environment::where('is_local', true)->exists()) {
            return redirect()->route('environments.edit', $environment)
                ->with('error', 'A local environment already exists.');
        }

        $environment->update($validated);

        return redirect()->route('environments.index')
            ->with('success', "Environment '{$environment->name}' updated successfully.");
    }

    public function destroy(Environment $environment)
    {
        $name = $environment->name;

        // Get the environment's TLD before deletion for cleanup
        $tld = null;
        try {
            $config = $this->config->getConfig($environment);
            $tld = $config['success'] ? ($config['data']['tld'] ?? null) : null;
        } catch (\Exception $e) {
            // Ignore config fetch errors - environment might be unreachable
        }

        // Delete the environment
        $environment->delete();

        // Clean up DNS resolver if no other environments use this TLD
        if ($tld) {
            try {
                // Check if any remaining environments use this TLD
                // Pass 0 as excludeEnvironmentId since the environment is already deleted
                $otherEnvironmentsWithTld = $this->countEnvironmentsWithTld($tld, 0);
                if ($otherEnvironmentsWithTld === 0) {
                    $this->dnsResolver->removeResolver($tld);
                }
            } catch (\Exception $e) {
                // Ignore cleanup errors - non-critical
                \Illuminate\Support\Facades\Log::warning("DNS resolver cleanup failed: {$e->getMessage()}");
            }
        }

        return redirect()->route('environments.index')
            ->with('success', "Environment '{$name}' removed successfully.");
    }

    public function setDefault(Environment $environment)
    {
        // Clear default from all environments
        Environment::where('is_default', true)->update(['is_default' => false]);

        // Set this environment as default
        $environment->update(['is_default' => true]);

        return redirect()->route('environments.show', $environment);
    }

    public function testConnection(Environment $environment)
    {
        // For local environments, always succeed
        if ($environment->is_local) {
            $environment->update(['last_connected_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => 'Local connection',
            ]);
        }

        // For remote environments, use API instead of SSH (much faster)
        $result = $this->status->status($environment);

        if ($result['success']) {
            $environment->update(['last_connected_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => 'Connected successfully',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['error'] ?? 'Connection failed',
        ]);
    }

    public function status(Environment $environment)
    {
        if ($environment->is_local) {
            $this->macPhpFpm->ensureConfigured();
        }

        $result = $this->status->status($environment);

        return response()->json($result);
    }

    public function sites(Environment $environment)
    {
        $result = $this->status->sites($environment);

        return response()->json($result);
    }

    /**
     * Projects page (Inertia view).
     */
    public function projectsPage(Environment $environment): \Inertia\Response
    {
        $editor = Setting::getEditor();
        $remoteApiUrl = $this->getRemoteApiUrl($environment);

        return \Inertia\Inertia::render('environments/Projects', [
            'environment' => $environment,
            'editor' => $editor,
            'remoteApiUrl' => $remoteApiUrl,
        ]);
    }

    /**
     * Services page (Inertia view).
     */
    public function servicesPage(Environment $environment): \Inertia\Response
    {
        $remoteApiUrl = $this->getRemoteApiUrl($environment);
        $editor = Setting::getEditor();

        return \Inertia\Inertia::render('environments/Services', [
            'environment' => $environment,
            'remoteApiUrl' => $remoteApiUrl,
            'editor' => $editor,
            'localPhpIniPath' => $environment->is_local ? $this->macPhpFpm->getGlobalIniPath() : null,
            'homebrewPrefix' => $environment->is_local ? $this->macPhpFpm->getHomebrewPrefix() : null,
        ]);
    }

    /**
     * Projects API (JSON).
     */
    public function projectsApi(Environment $environment)
    {
        return response()->json($this->project->projectList($environment));
    }

    /**
     * Environment settings page.
     */
    public function settings(Environment $environment): \Inertia\Response
    {
        return \Inertia\Inertia::render('environments/Settings', [
            'environment' => $environment,
        ]);
    }

    /**
     * Update environment settings.
     */
    public function updateSettings(Request $request, Environment $environment)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'user' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
        ]);

        $environment->update($validated);

        return redirect()->back()->with('success', 'Environment settings updated.');
    }

    /**
     * Orchestrator page.
     */
    public function orchestrator(Environment $environment): \Inertia\Response
    {
        return \Inertia\Inertia::render('environments/Orchestrator', [
            'environment' => $environment,
        ]);
    }

    /**
     * Enable orchestrator for an environment.
     */
    public function enableOrchestrator(Request $request, Environment $environment)
    {
        $validated = $request->validate([
            'orchestrator_url' => 'required|url|max:255',
        ]);

        // Update environment with orchestrator URL
        $environment->update([
            'orchestrator_url' => $validated['orchestrator_url'],
        ]);

        // Update orbit CLI config with orchestrator URL
        $this->orchestrator->setOrchestratorUrl($environment, $validated['orchestrator_url']);

        return redirect()->back()->with('success', 'Orchestrator enabled successfully.');
    }

    /**
     * Disable orchestrator for an environment.
     */
    public function disableOrchestrator(Environment $environment)
    {
        // Clear orchestrator URL from environment
        $environment->update([
            'orchestrator_url' => null,
        ]);

        // Remove orchestrator URL from orbit CLI config
        $this->orchestrator->removeOrchestratorUrl($environment);

        return redirect()->back()->with('success', 'Orchestrator disconnected.');
    }

    /**
     * Install orchestrator for an environment.
     */
    public function installOrchestrator(Environment $environment)
    {
        $result = $this->orchestrator->installOrchestrator($environment);

        return response()->json($result);
    }

    /**
     * Detect existing orchestrator installations.
     */
    public function detectOrchestrator(Environment $environment)
    {
        $result = $this->orchestrator->detectOrchestrator($environment);

        return response()->json($result);
    }

    /**
     * Reconcile orchestrator configuration.
     */
    public function reconcileOrchestrator(Request $request, Environment $environment)
    {
        $validated = $request->validate([
            'path' => 'required|string',
        ]);

        $result = $this->orchestrator->reconcileOrchestrator($environment, $validated['path']);

        if ($result['success']) {
            return response()->json($result);
        }

        return response()->json($result, 400);
    }

    /**
     * Get orchestrator services configuration.
     */
    public function orchestratorServices(Environment $environment)
    {
        if (! $environment->orchestrator_url) {
            return response()->json([
                'success' => false,
                'error' => 'No orchestrator configured',
                'services' => [],
            ]);
        }

        try {
            $url = rtrim($environment->orchestrator_url, '/').'/api/services';

            // For remote environments, call orchestrator directly (DNS resolver is configured)
            // This avoids SSH overhead on every page load
            if (! $environment->is_local) {
                $response = \Illuminate\Support\Facades\Http::withoutVerifying()
                    ->timeout(5)
                    ->get($url);

                if ($response->successful()) {
                    $data = $response->json();

                    return response()->json([
                        'success' => true,
                        'services' => $data['services'] ?? [],
                    ]);
                }
            }

            // For local environments or if direct call fails, use SSH with --resolve
            $parsed = parse_url($environment->orchestrator_url);
            $host = $parsed['host'] ?? '';
            $resolveIp = $environment->is_local ? '127.0.0.1' : $environment->host;
            $resolve = "--resolve '{$host}:443:{$resolveIp}'";

            $result = $this->ssh->execute($environment, "curl -sk {$resolve} '{$url}'");

            if ($result['success'] && ! empty($result['output'])) {
                $data = json_decode((string) $result['output'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return response()->json([
                        'success' => true,
                        'services' => $data['services'] ?? [],
                    ]);
                }
            }

            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch services',
                'services' => [],
            ]);
        } catch (\Exception) {
            return response()->json([
                'success' => false,
                'error' => 'Could not connect to orchestrator',
                'services' => [],
            ]);
        }
    }

    /**
     * Get projects from orchestrator.
     */
    public function orchestratorProjects(Environment $environment)
    {
        if (! $environment->orchestrator_url) {
            return response()->json([
                'success' => false,
                'error' => 'No orchestrator configured',
                'projects' => [],
            ]);
        }

        try {
            // Use SSH to fetch from orchestrator with --resolve for custom TLD
            $url = rtrim($environment->orchestrator_url, '/').'/orchestrator/api/projects';
            $parsed = parse_url($environment->orchestrator_url);
            $host = $parsed['host'] ?? '';
            $resolveIp = $environment->is_local ? '127.0.0.1' : $environment->host;
            $resolve = "--resolve '{$host}:443:{$resolveIp}'";

            $result = $this->ssh->execute($environment, "curl -sk {$resolve} '{$url}'");

            if ($result['success'] && ! empty($result['output'])) {
                $data = json_decode((string) $result['output'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return response()->json([
                        'success' => true,
                        'projects' => $data['projects'] ?? $data['data'] ?? [],
                    ]);
                }
            }

            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to fetch projects',
                'projects' => [],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Could not connect to orchestrator: '.$e->getMessage(),
                'projects' => [],
            ]);
        }
    }

    public function start(Request $request, Environment $environment)
    {
        $site = $request->input('site');
        $result = $this->serviceControl->start($environment, $site);

        return response()->json($result);
    }

    public function stop(Request $request, Environment $environment)
    {
        $site = $request->input('site');
        $result = $this->serviceControl->stop($environment, $site);

        return response()->json($result);
    }

    public function restart(Request $request, Environment $environment)
    {
        $site = $request->input('site');
        $result = $this->serviceControl->restart($environment, $site);

        return response()->json($result);
    }

    /**
     * Start a single service.
     */
    public function startService(Environment $environment, string $service)
    {
        $result = $this->serviceControl->startService($environment, $service);

        return response()->json($result);
    }

    /**
     * Start a single host service.
     */
    public function startHostService(Environment $environment, string $service)
    {
        $result = $this->serviceControl->startHostService($environment, $service);

        return response()->json($result);
    }

    /**
     * Stop a single service.
     */
    public function stopService(Environment $environment, string $service)
    {
        $result = $this->serviceControl->stopService($environment, $service);

        return response()->json($result);
    }

    /**
     * Stop a single host service.
     */
    public function stopHostService(Environment $environment, string $service)
    {
        $result = $this->serviceControl->stopHostService($environment, $service);

        return response()->json($result);
    }

    /**
     * Restart a single service.
     */
    public function restartService(Environment $environment, string $service)
    {
        $result = $this->serviceControl->restartService($environment, $service);

        return response()->json($result);
    }

    /**
     * Restart a single host service.
     */
    public function restartHostService(Environment $environment, string $service)
    {
        $result = $this->serviceControl->restartHostService($environment, $service);

        return response()->json($result);
    }

    /**
     * Get logs for a single service.
     */
    public function serviceLogs(Environment $environment, string $service)
    {
        $result = $this->serviceControl->serviceLogs($environment, $service);

        return response()->json($result);
    }

    /**
     * Get logs for a host service (Caddy, PHP-FPM, Horizon).
     */
    public function hostServiceLogs(Environment $environment, string $service)
    {
        $result = $this->serviceControl->hostServiceLogs($environment, $service);

        return response()->json($result);
    }

    /**
     * Show available services.
     */
    public function availableServices(Environment $environment)
    {
        $result = $this->serviceControl->available($environment);

        return response()->json($result);
    }

    /**
     * Enable a service.
     */
    public function enableService(Request $request, Environment $environment, string $service)
    {
        $options = $request->input('options', []);
        $result = $this->serviceControl->enable($environment, $service, $options);

        return response()->json($result);
    }

    /**
     * Disable a service.
     */
    public function disableService(Environment $environment, string $service)
    {
        $result = $this->serviceControl->disable($environment, $service);

        return response()->json($result);
    }

    /**
     * Update service config.
     */
    public function configureService(Request $request, Environment $environment, string $service)
    {
        $config = $request->input('config', []);
        $result = $this->serviceControl->configure($environment, $service, $config);

        return response()->json($result);
    }

    /**
     * Get service details.
     */
    public function serviceInfo(Environment $environment, string $service)
    {
        $result = $this->serviceControl->info($environment, $service);

        return response()->json($result);
    }

    public function changePhp(Request $request, Environment $environment)
    {
        $validated = $request->validate([
            'site' => 'required|string',
            'version' => 'required|string',
        ]);

        $result = $this->config->php($environment, $validated['site'], $validated['version']);

        return response()->json($result);
    }

    public function resetPhp(Request $request, Environment $environment)
    {
        $validated = $request->validate([
            'site' => 'required|string',
        ]);

        $result = $this->config->phpReset($environment, $validated['site']);

        return response()->json($result);
    }

    public function getConfig(Environment $environment)
    {
        $result = $this->config->getConfig($environment);

        return response()->json($result);
    }

    /**
     * Get Reverb WebSocket configuration for real-time updates.
     */
    public function getReverbConfig(Environment $environment)
    {
        $result = $this->config->getReverbConfig($environment);

        return response()->json($result);
    }

    public function saveConfig(Request $request, Environment $environment)
    {
        try {
            // Get available PHP versions dynamically from the environment
            $config = $this->config->getConfig($environment);
            $availableVersions = $config['success'] && isset($config['data']['available_php_versions'])
                ? $config['data']['available_php_versions']
                : ['8.3', '8.4', '8.5'];

            $validated = $request->validate([
                'paths' => 'required|array',
                'paths.*' => 'required|string',
                'tld' => 'required|string|max:20',
                'default_php_version' => 'required|string|in:'.implode(',', $availableVersions),
            ]);

            // Reuse the config we already fetched for validation
            $currentConfig = $config;
            $oldTld = $currentConfig['success'] ? ($currentConfig['data']['tld'] ?? 'test') : null;
            $newTld = $validated['tld'];

            // Preserve existing site-specific settings (like PHP versions per site)
            $configToSave = $validated;
            if ($currentConfig['success'] && isset($currentConfig['data']['sites'])) {
                $configToSave['sites'] = $currentConfig['data']['sites'];
            }

            // Save the config to the environment
            $result = $this->config->saveConfig($environment, $configToSave);

            // Update cached TLD in database
            if ($result['success']) {
                $environment->update(['tld' => $newTld]);
            }

            // Only update DNS if TLD changed
            if ($result['success'] && $oldTld !== $newTld) {
                // Try to update DNS resolver (non-blocking - failures are logged but don't break the save)
                try {
                    $resolverResult = $this->dnsResolver->updateResolver($environment, $newTld);

                    // Remove the old resolver if no other environments use it
                    if ($oldTld) {
                        $otherEnvironmentsWithTld = $this->countEnvironmentsWithTld($oldTld, $environment->id);
                        if ($otherEnvironmentsWithTld === 0) {
                            $this->dnsResolver->removeResolver($oldTld);
                        }
                    }

                    $result['resolver'] = $resolverResult;
                } catch (\Exception $e) {
                    // DNS resolver update failed but config was saved - log and continue
                    \Illuminate\Support\Facades\Log::warning("DNS resolver update failed: {$e->getMessage()}");
                    $result['resolver'] = ['success' => false, 'error' => $e->getMessage()];
                }
                // Rebuild DNS container on the environment with the new TLD
                try {
                    $dnsRebuildResult = $this->serviceControl->rebuildDns($environment, $newTld);
                    $result['dns_rebuild'] = $dnsRebuildResult;
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("DNS container rebuild failed: {$e->getMessage()}");
                    $result['dns_rebuild'] = ['success' => false, 'error' => $e->getMessage()];
                }
            }

            return response()->json($result);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("saveConfig failed: {$e->getMessage()}");

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all TLDs for all environments (for conflict detection).
     * Uses cached TLD from database instead of API calls.
     */
    public function getAllTlds()
    {
        $tlds = Environment::whereNotNull('tld')
            ->pluck('tld', 'id')
            ->toArray();

        return response()->json([
            'success' => true,
            'data' => $tlds,
        ]);
    }

    /**
     * Count how many environments (excluding the given one) use a specific TLD.
     * Uses cached TLD from database instead of API calls.
     */
    protected function countEnvironmentsWithTld(string $tld, int $excludeEnvironmentId): int
    {
        return Environment::where('id', '!=', $excludeEnvironmentId)
            ->where('tld', $tld)
            ->count();
    }

    /**
     * Get all worktrees for an environment.
     */
    public function worktrees(Environment $environment)
    {
        $result = $this->worktree->worktrees($environment);

        return response()->json($result);
    }

    /**
     * Unlink a worktree from a site.
     */
    public function unlinkWorktree(Request $request, Environment $environment)
    {
        $validated = $request->validate([
            'site' => 'required|string',
            'worktree' => 'required|string',
        ]);

        $result = $this->worktree->unlinkWorktree(
            $environment,
            $validated['site'],
            $validated['worktree']
        );

        return response()->json($result);
    }

    /**
     * Refresh worktree detection.
     */
    public function refreshWorktrees(Environment $environment)
    {
        $result = $this->worktree->refreshWorktrees($environment);

        return response()->json($result);
    }

    /**
     * Show the create project form.
     */
    public function createProject(Environment $environment): \Inertia\Response
    {
        $recentTemplates = TemplateFavorite::orderByDesc('last_used_at')
            ->limit(5)
            ->get();

        return \Inertia\Inertia::render('environments/projects/Create', [
            'environment' => $environment,
            'recentTemplates' => $recentTemplates,
        ]);
    }

    /**
     * Store a newly created project.
     */
    public function storeProject(Request $request, Environment $environment)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'template' => 'nullable|string|max:500',
            'is_template' => 'boolean', // Whether the repo is a GitHub template (vs regular repo to clone)
            'fork' => 'boolean', // Whether to fork (true) or import as new repo (false) when cloning non-matching repo
            'integrate' => 'boolean',
            'linear_team_id' => 'nullable|string',
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

        // Always use CLI for project creation - CLI handles orchestrator registration at the end
        // Orchestrator handles Linear/VibeKanban integrations internally
        $projectOptions = [
            'name' => $validated['name'],
            'template' => $validated['template'] ?? null,
            'is_template' => $validated['is_template'] ?? false, // true = GitHub template, false = clone directly
            'fork' => $isImportScenario ? ($validated['fork'] ?? false) : false, // Only relevant for import scenario
            'visibility' => $validated['visibility'] ?? 'private',
            // PHP version
            'php_version' => $validated['php_version'] ?? null,
            // Driver options
            'db_driver' => $validated['db_driver'] ?? null,
            'session_driver' => $validated['session_driver'] ?? null,
            'cache_driver' => $validated['cache_driver'] ?? null,
            'queue_driver' => $validated['queue_driver'] ?? null,
        ];

        $result = $this->project->createProject($environment, $projectOptions);

        if (! $result['success']) {
            $errorMessage = $result['error'] ?? 'Failed to create project';

            return back()
                ->withErrors(['create' => $errorMessage])
                ->with('error', $errorMessage);
        }

        // CLI is source of truth - no local tracking needed
        // Just pass the slug for WebSocket provisioning status updates
        $projectSlug = $result['data']['project_slug'] ?? $result['data']['slug'] ?? \Illuminate\Support\Str::slug($validated['name']);

        return redirect()->route('environments.projects', $environment)
            ->with([
                'provisioning' => $projectSlug,
                'success' => "Project '{$validated['name']}' is being created...",
            ]);
    }

    /**
     * Delete a project from the environment.
     */
    public function destroyProject(Request $request, Environment $environment, string $projectName)
    {
        $errors = [];

        // If orchestrator is enabled, cascade delete to VK/Linear first
        if ($environment->orchestrator_url) {
            $orchestratorResult = $this->orchestrator->deleteIntegratedProject($environment, $projectName);

            if (! $orchestratorResult['success']) {
                // Log but continue - we still want to try deleting from filesystem
                \Illuminate\Support\Facades\Log::warning("Orchestrator delete failed for {$projectName}: ".($orchestratorResult['error'] ?? 'Unknown error'));
                $errors['orchestrator'] = $orchestratorResult['error'] ?? 'Failed to delete from orchestrator';
            }
        }

        // Delete from filesystem via CLI
        $cliResult = $this->project->deleteProject($environment, $projectName, force: true);

        if (! $cliResult['success']) {
            $errors['filesystem'] = $cliResult['error'] ?? 'Failed to delete project files';

            // If both failed, return error
            if (! empty($errors['orchestrator'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to delete project',
                    'details' => $errors,
                ], 500);
            }
        }

        // If CLI succeeded (even if orchestrator failed), consider it a success with warnings
        if ($errors !== []) {
            return response()->json([
                'success' => true,
                'warning' => 'Project deleted with some errors',
                'details' => $errors,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "Project '{$projectName}' deleted successfully",
        ]);
    }

    /**
     * Rebuild a project (re-run composer install, npm install, build, migrations).
     */
    public function rebuildProject(Request $request, Environment $environment, string $projectName)
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
     * Check if a GitHub repository already exists.
     * Used for real-time validation while user types project name.
     */
    public function githubRepoExists(Environment $environment, \Illuminate\Http\Request $request)
    {
        $request->validate([
            'repo' => 'required|string',
        ]);

        $repo = $request->input('repo');
        $result = $this->project->checkGitHubRepoExists($environment, $repo);

        return response()->json([
            'success' => true,
            'exists' => $result['exists'] ?? false,
            'error' => $result['error'] ?? null,
        ]);
    }

    /**
     * Get Linear teams for an environment.
     */
    public function linearTeams(Environment $environment)
    {
        $result = $this->orchestrator->getLinearTeams($environment);

        return response()->json($result);
    }

    /**
     * Extract a display name from a template URL or repo string.
     */
    protected function extractTemplateName(string $template): string
    {
        // Handle full URLs
        if (str_contains($template, 'github.com')) {
            $path = parse_url($template, PHP_URL_PATH);
            $parts = explode('/', trim($path ?? '', '/'));

            return end($parts) ?: $template;
        }

        // Handle owner/repo format
        if (str_contains($template, '/')) {
            $parts = explode('/', $template);

            return end($parts);
        }

        return $template;
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
            $envParser = app(\App\Services\TemplateAnalyzer\EnvParser::class);
            $envVars = $envParser->parse($envContent);

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

    /**
     * Get the recommended (highest compatible) PHP version for a constraint.
     * Always prefers the latest PHP version unless explicitly excluded.
     *
     * Examples:
     * - ^8.3 → 8.5 (allows 8.3+)
     * - ^8.4 → 8.5 (allows 8.4+)
     * - ~8.3.0 → 8.3 (only allows 8.3.x)
     * - 8.4.* → 8.4 (only allows 8.4.x)
     * - <8.5 → 8.4 (excludes 8.5)
     * - >=8.3 <8.5 → 8.4 (range excludes 8.5)
     */
    protected function getRecommendedPhpVersion(string $constraint): string
    {
        $constraint = trim($constraint);
        $availableVersions = ['8.5', '8.4', '8.3'];

        // Check for explicit upper bound that excludes versions
        // Patterns like: <8.5, <=8.4, <8.5.0
        if (preg_match('/<\s*(\d+)\.(\d+)/', $constraint, $matches)) {
            $maxMajor = (int) $matches[1];
            $maxMinor = (int) $matches[2];

            foreach ($availableVersions as $version) {
                [$major, $minor] = explode('.', $version);
                // Version must be strictly less than the upper bound
                if ((int) $major < $maxMajor || ((int) $major === $maxMajor && (int) $minor < $maxMinor)) {
                    return $version;
                }
            }
        }

        // Check for tilde constraint ~8.x.y which locks to 8.x.*
        // ~8.3.0 means >=8.3.0 <8.4.0
        if (preg_match('/~\s*(\d+)\.(\d+)\./', $constraint, $matches)) {
            return $matches[1].'.'.$matches[2];
        }

        // Check for wildcard constraint 8.x.* which locks to 8.x
        if (preg_match('/(\d+)\.(\d+)\.\*/', $constraint, $matches)) {
            return $matches[1].'.'.$matches[2];
        }

        // For caret (^), greater-than (>=, >), or simple version constraints,
        // the latest version is compatible
        // ^8.3 means >=8.3.0 <9.0.0, so 8.5 is fine
        // >=8.3 means 8.3 or higher, so 8.5 is fine
        return '8.5';
    }

    /**
     * Extract minimum PHP version from composer.json constraint (for display).
     */
    protected function extractMinPhpVersion(string $constraint): ?string
    {
        $constraint = trim($constraint);

        if (preg_match('/(\d+\.\d+)(?:\.\d+)?/', $constraint, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get repository info from GitHub via gh CLI over SSH.
     */
    protected function getRepoInfoViaGh(Environment $environment, string $repo): ?array
    {
        $command = "gh api repos/{$repo} --jq '{is_template, default_branch, name, full_name, private, clone_url}' 2>/dev/null";
        $result = $this->ssh->execute($environment, $command);

        if (! $result['success'] || empty($result['output'])) {
            return null;
        }

        $data = json_decode((string) $result['output'], true);

        return json_last_error() === JSON_ERROR_NONE ? $data : null;
    }

    /**
     * Fetch a file from GitHub via gh CLI over SSH.
     */
    protected function fetchFileViaGh(Environment $environment, string $repo, string $path): ?string
    {
        $command = "gh api repos/{$repo}/contents/{$path} --jq .content 2>/dev/null | base64 -d 2>/dev/null";
        $result = $this->ssh->execute($environment, $command);

        if (! $result['success'] || empty($result['output'])) {
            return null;
        }

        return $result['output'];
    }

    /**
     * Extract owner/repo from template string.
     */
    protected function extractRepoFromTemplate(string $template): ?string
    {
        if (str_contains($template, 'github.com')) {
            $path = parse_url($template, PHP_URL_PATH);
            $parts = array_values(array_filter(explode('/', trim($path ?? '', '/'))));

            if (count($parts) >= 2) {
                $repo = $parts[1];
                if (str_ends_with($repo, '.git')) {
                    $repo = substr($repo, 0, -4);
                }

                return "{$parts[0]}/{$repo}";
            }

            return null;
        }

        if (preg_match('/^[\w.-]+\/[\w.-]+$/', $template)) {
            return $template;
        }

        return null;
    }

    /**
     * Normalize a driver value (lowercase, trim).
     */
    protected function normalizeDriver(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return strtolower(trim($value));
    }

    /**
     * List all workspaces for an environment.
     */
    public function workspaces(Environment $environment): \Inertia\Response
    {
        $editor = Setting::getEditor();
        $remoteApiUrl = $this->getRemoteApiUrl($environment);

        // Don't fetch workspaces synchronously - let Vue load them async
        return \Inertia\Inertia::render('environments/Workspaces', [
            'environment' => $environment,
            'editor' => $editor,
            'remoteApiUrl' => $remoteApiUrl,
        ]);
    }

    /**
     * API endpoint for workspaces list.
     */
    public function workspacesApi(Environment $environment)
    {
        return response()->json($this->workspace->workspacesList($environment));
    }

    /**
     * Show the create workspace form.
     */
    public function createWorkspace(Environment $environment): \Inertia\Response
    {
        return \Inertia\Inertia::render('environments/workspaces/Create', [
            'environment' => $environment,
        ]);
    }

    /**
     * Store a new workspace.
     */
    public function storeWorkspace(Request $request, Environment $environment)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|regex:/^[a-z0-9-]+$/',
        ]);

        $result = $this->workspace->workspaceCreate($environment, $validated['name']);

        if (! $result['success']) {
            return back()->with('error', $result['error'] ?? 'Failed to create workspace');
        }

        return redirect()->route('environments.workspaces', $environment)
            ->with('success', "Workspace '{$validated['name']}' created successfully");
    }

    /**
     * Show a single workspace.
     */
    public function showWorkspace(Environment $environment, string $workspace): \Inertia\Response
    {
        $editor = Setting::getEditor();
        $remoteApiUrl = $this->getRemoteApiUrl($environment);

        // Don't fetch workspace data synchronously - let Vue load it async
        return \Inertia\Inertia::render('environments/workspaces/Show', [
            'environment' => $environment,
            'workspaceName' => $workspace,
            'editor' => $editor,
            'remoteApiUrl' => $remoteApiUrl,
        ]);
    }

    /**
     * API endpoint for single workspace.
     */
    public function workspaceApi(Environment $environment, string $workspace)
    {
        $result = $this->workspace->workspacesList($environment);
        $workspaces = $result['success'] ? ($result['data']['workspaces'] ?? []) : [];

        $workspaceData = collect($workspaces)->firstWhere('name', $workspace);

        if (! $workspaceData) {
            return response()->json([
                'success' => false,
                'error' => 'Workspace not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $workspaceData,
        ]);
    }

    /**
     * Delete a workspace.
     */
    public function destroyWorkspace(Environment $environment, string $workspace)
    {
        $result = $this->workspace->workspaceDelete($environment, $workspace);

        if (! $result['success']) {
            return back()->with('error', $result['error'] ?? 'Failed to delete workspace');
        }

        return redirect()->route('environments.workspaces', $environment)
            ->with('success', "Workspace '{$workspace}' deleted successfully");
    }

    /**
     * Add a project to a workspace.
     */
    public function addWorkspaceProject(Request $request, Environment $environment, string $workspace)
    {
        $validated = $request->validate([
            'project' => 'required|string|max:255',
        ]);

        $result = $this->workspace->workspaceAddProject($environment, $workspace, $validated['project']);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to add project to workspace',
            ]);
        }

        return response()->json([
            'success' => true,
            'workspace' => $result['data']['workspace'] ?? null,
        ]);
    }

    /**
     * Remove a project from a workspace.
     */
    public function removeWorkspaceProject(Environment $environment, string $workspace, string $project)
    {
        $result = $this->workspace->workspaceRemoveProject($environment, $workspace, $project);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to remove project from workspace',
            ]);
        }

        return response()->json([
            'success' => true,
            'workspace' => $result['data']['workspace'] ?? null,
        ]);
    }

    /**
     * Get linked packages for a project.
     */
    public function linkedPackages(Environment $environment, string $project)
    {
        $result = $this->package->packageLinked($environment, $project);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to get linked packages',
            ]);
        }

        return response()->json([
            'success' => true,
            'linked_packages' => $result['data']['linked_packages'] ?? [],
        ]);
    }

    /**
     * Link a package to a project.
     */
    public function linkPackage(Request $request, Environment $environment, string $project)
    {
        $package = $request->input('package');

        if (! $package) {
            return response()->json([
                'success' => false,
                'error' => 'Package name is required',
            ], 400);
        }

        $result = $this->package->packageLink($environment, $package, $project);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to link package',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $result['data']['message'] ?? 'Package linked successfully',
        ]);
    }

    /**
     * Unlink a package from a project.
     */
    public function unlinkPackage(Environment $environment, string $project, string $package)
    {
        $result = $this->package->packageUnlink($environment, $package, $project);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to unlink package',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $result['data']['message'] ?? 'Package unlinked successfully',
        ]);
    }

    /**
     * Run health checks on the environment (doctor).
     */
    public function runDoctor(Environment $environment)
    {
        $result = $this->doctor->runChecks($environment);

        return response()->json($result);
    }

    /**
     * Run quick connectivity check on the environment.
     */
    public function quickCheck(Environment $environment)
    {
        $result = $this->doctor->quickCheck($environment);

        return response()->json($result);
    }

    /**
     * Attempt to fix a specific doctor issue.
     */
    public function fixDoctorIssue(Environment $environment, string $check)
    {
        $result = $this->doctor->fixIssue($environment, $check);

        return response()->json($result);
    }
}
