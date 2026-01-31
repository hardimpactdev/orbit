<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Ui\Http\Controllers;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\DnsResolverService;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\ServiceControlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EnvironmentConfigController extends Controller
{
    public function __construct(
        protected ConfigurationService $config,
        protected DnsResolverService $dnsResolver,
        protected ServiceControlService $serviceControl,
    ) {}

    /**
     * Get the orbit configuration for an environment.
     */
    public function getConfig(Environment $environment)
    {
        $result = $this->config->getConfig($environment);

        return response()->json($result);
    }

    /**
     * Save the orbit configuration for an environment.
     */
    public function saveConfig(Request $request, Environment $environment)
    {
        try {
            // Get available PHP versions dynamically from the environment
            $config = $this->config->getConfig($environment);
            $availableVersions = $config['success'] && isset($config['data']['available_php_versions'])
                ? $config['data']['available_php_versions']
                : config('orbit-ui.php.versions', ['8.3', '8.4', '8.5']);

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

            // Preserve existing project-specific settings (like PHP versions per project)
            $configToSave = $validated;
            if ($currentConfig['success'] && isset($currentConfig['data']['projects'])) {
                $configToSave['projects'] = $currentConfig['data']['projects'];
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
                    Log::warning("DNS resolver update failed: {$e->getMessage()}");
                    $result['resolver'] = ['success' => false, 'error' => $e->getMessage()];
                }
                // Rebuild DNS container on the environment with the new TLD
                try {
                    $dnsRebuildResult = $this->serviceControl->rebuildDns($environment, $newTld);
                    $result['dns_rebuild'] = $dnsRebuildResult;
                } catch (\Exception $e) {
                    Log::warning("DNS container rebuild failed: {$e->getMessage()}");
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
            Log::error("saveConfig failed: {$e->getMessage()}");

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Browse directories for the directory picker.
     */
    public function browseDirectories(Request $request, Environment $environment)
    {
        $path = $request->query('path', '~');

        // Expand ~ to home directory
        if (str_starts_with($path, '~')) {
            $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '/home/'.get_current_user());
            $path = $home.substr($path, 1);
        }

        // Normalize the path
        $path = realpath($path) ?: $path;

        if (! is_dir($path)) {
            return response()->json([
                'success' => false,
                'error' => 'Directory not found',
            ], 404);
        }

        // Get directories in this path
        $directories = [];
        $items = @scandir($path);

        if ($items === false) {
            return response()->json([
                'success' => false,
                'error' => 'Unable to read directory',
            ], 403);
        }

        foreach ($items as $item) {
            if ($item === '.' || str_starts_with($item, '.')) {
                continue; // Skip hidden files and current directory
            }
            if ($item === '..') {
                continue; // We'll handle parent separately
            }

            $fullPath = $path.'/'.$item;
            if (is_dir($fullPath) && is_readable($fullPath)) {
                $directories[] = [
                    'name' => $item,
                    'path' => $fullPath,
                ];
            }
        }

        // Sort alphabetically
        usort($directories, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        // Get parent directory
        $parent = dirname($path);
        if ($parent === $path) {
            $parent = null; // At root
        }

        return response()->json([
            'success' => true,
            'data' => [
                'current' => $path,
                'parent' => $parent,
                'directories' => $directories,
            ],
        ]);
    }

    /**
     * Get Reverb WebSocket configuration for real-time updates.
     */
    public function getReverbConfig(Environment $environment)
    {
        $result = $this->config->getReverbConfig($environment);

        return response()->json($result);
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
}
