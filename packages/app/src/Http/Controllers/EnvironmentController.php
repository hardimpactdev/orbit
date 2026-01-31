<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Controllers;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Models\Setting;
use HardImpact\Orbit\Core\Services\DnsResolverService;
use HardImpact\Orbit\Core\Services\EnvironmentManager;
use HardImpact\Orbit\Core\Services\MacPhpFpmConfigService;
use HardImpact\Orbit\Core\Services\NotificationService;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\StatusService;
use HardImpact\Orbit\App\Http\Controllers\Concerns\ProvidesRemoteApiUrl;
use Illuminate\Http\Request;

/**
 * Controller for environment CRUD operations and core environment features.
 *
 * Most environment-related functionality has been extracted to specialized controllers:
 * - EnvironmentStatusController: Health checks, doctor
 * - EnvironmentConfigController: Config save/get, TLD/DNS
 * - EnvironmentProjectController: Project CRUD, GitHub integration
 * - EnvironmentServiceController: Service start/stop/restart
 * - PhpConfigController: PHP version management
 * - WorkspaceController: Workspace management
 * - WorktreeController: Git worktree management
 * - PackageController: Composer package linking
 */
class EnvironmentController extends Controller
{
    use ProvidesRemoteApiUrl;

    public function __construct(
        protected StatusService $status,
        protected ConfigurationService $config,
        protected DnsResolverService $dnsResolver,
        protected MacPhpFpmConfigService $macPhpFpm,
        protected EnvironmentManager $environments,
        protected NotificationService $notificationService,
    ) {}

    public function index(): \Inertia\Response|\Illuminate\Http\RedirectResponse
    {
        $environments = Environment::all();
        $hasLocalEnvironment = Environment::where('is_local', true)->exists();

        // Desktop mode: auto-create local environment if none exist
        if ($environments->isEmpty() && config('orbit.mode') === 'desktop') {
            $localEnvironment = Environment::create([
                'name' => 'Local',
                'host' => 'localhost',
                'user' => get_current_user(),
                'port' => 22,
                'is_local' => true,
            ]);

            return redirect()->route('environments.show', $localEnvironment)
                ->with('success', 'Local environment created automatically.');
        }

        // If only one environment exists, redirect directly to it
        if ($environments->count() === 1) {
            return redirect()->route('environments.show', $environments->first());
        }

        return \Inertia\Inertia::render('environments/Index', [
            'environments' => $environments,
            'hasLocalEnvironment' => $hasLocalEnvironment,
        ]);
    }

    public function create(): \Inertia\Response
    {
        $sshKeys = \HardImpact\Orbit\Core\Models\SshKey::orderBy('is_default', 'desc')->orderBy('name')->get();
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

        // Redirect to the environment dashboard (not index)
        return redirect()->route('environments.show', $environment)
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

        // Only check installation synchronously (fast), load status/projects via AJAX
        $installation = $this->status->checkInstallation($environment);
        $editor = $environment->getEditor();
        $remoteApiUrl = $this->getRemoteApiUrl($environment);
        $reverb = $this->config->getReverbConfig($environment);

        return \Inertia\Inertia::render('environments/Show', [
            'environment' => $environment,
            'installation' => $installation,
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
                $otherEnvironmentsWithTld = Environment::where('tld', $tld)->count();
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

    public function switchEnvironment(Environment $environment, Request $request)
    {
        $activeEnvironment = $this->environments->setActive($environment->id);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'environment' => $activeEnvironment,
            ]);
        }

        return redirect()->route('environments.show', $activeEnvironment)
            ->with('success', "Environment '{$activeEnvironment->name}' is now active.");
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

    /**
     * Services page (Inertia view).
     */
    public function servicesPage(Environment $environment): \Inertia\Response
    {
        $remoteApiUrl = $this->getRemoteApiUrl($environment);
        $editor = $environment->getEditor();

        $reverb = $this->config->getReverbConfig($environment);

        return \Inertia\Inertia::render('environments/Services', [
            'environment' => $environment,
            'remoteApiUrl' => $remoteApiUrl,
            'editor' => $editor,
            'localPhpIniPath' => $environment->is_local ? $this->macPhpFpm->getGlobalIniPath() : null,
            'homebrewPrefix' => $environment->is_local ? $this->macPhpFpm->getHomebrewPrefix() : null,
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
     * Environment settings page.
     */
    public function settings(Environment $environment): \Inertia\Response
    {
        $remoteApiUrl = $this->getRemoteApiUrl($environment);

        // Import models for additional settings
        $sshKeys = \HardImpact\Orbit\Core\Models\SshKey::orderBy('is_default', 'desc')->orderBy('name')->get();
        $availableSshKeys = \HardImpact\Orbit\Core\Models\Setting::getAvailableSshKeys();
        $templateFavorites = \HardImpact\Orbit\Core\Models\TemplateFavorite::orderByDesc('usage_count')->get();
        $notificationsEnabled = $this->notificationService->isEnabled();
        $menuBarEnabled = \HardImpact\Orbit\Core\Models\UserPreference::getValue('menu_bar_enabled', false);

        return \Inertia\Inertia::render('environments/Configuration', [
            'environment' => $environment,
            'remoteApiUrl' => $remoteApiUrl,
            'editor' => $environment->getEditor(),
            'editorOptions' => Environment::getEditorOptions(),
            'sshKeys' => $sshKeys,
            'availableSshKeys' => $availableSshKeys,
            'templateFavorites' => $templateFavorites,
            'notificationsEnabled' => $notificationsEnabled,
            'menuBarEnabled' => $menuBarEnabled,
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
            'editor_scheme' => 'nullable|string|in:'.implode(',', array_keys(Environment::getEditorOptions())),
        ]);

        $environment->update($validated);

        return redirect()->back()->with('success', 'Environment settings updated.');
    }

    /**
     * Update external access settings.
     */
    public function updateExternalAccess(Request $request, Environment $environment)
    {
        $validated = $request->validate([
            'external_access' => 'required|boolean',
            'external_host' => 'nullable|string|max:255',
        ]);

        $environment->update([
            'external_access' => $validated['external_access'],
            'external_host' => $validated['external_host'] ?: null,
        ]);

        return redirect()->back()->with('success', 'External access settings updated.');
    }

    /**
     * Get instance info for the local environment.
     * Used by desktop app to fetch the canonical display name.
     */
    public function instanceInfo()
    {
        $localEnv = Environment::getLocal();

        if (! $localEnv) {
            return response()->json([
                'success' => false,
                'error' => 'No local environment configured',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'name' => $localEnv->name,
                'tld' => $localEnv->tld,
                'cli_version' => $localEnv->cli_version,
            ],
        ]);
    }

    /**
     * Update instance info for the local environment.
     * Used by desktop app to rename the environment remotely.
     */
    public function updateInstanceInfo(Request $request)
    {
        $localEnv = Environment::getLocal();

        if (! $localEnv) {
            return response()->json([
                'success' => false,
                'error' => 'No local environment configured',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $localEnv->update(['name' => $validated['name']]);

        return response()->json([
            'success' => true,
            'data' => [
                'name' => $localEnv->name,
                'tld' => $localEnv->tld,
                'cli_version' => $localEnv->cli_version,
            ],
        ]);
    }
}
