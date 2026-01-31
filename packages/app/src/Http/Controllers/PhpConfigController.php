<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Controllers;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\MacPhpFpmConfigService;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\App\Http\Controllers\Concerns\ProvidesRemoteApiUrl;
use Illuminate\Http\Request;

class PhpConfigController extends Controller
{
    use ProvidesRemoteApiUrl;

    public function __construct(
        protected ConfigurationService $config,
        protected MacPhpFpmConfigService $macPhpFpm,
    ) {}

    /**
     * Change PHP version for a project.
     */
    public function changePhp(Request $request, Environment $environment, ?string $project = null)
    {
        $validated = $request->validate([
            'version' => 'required|string',
            'project' => $project ? 'nullable|string' : 'required|string',
        ]);

        $projectName = $project ?? ($validated['project'] ?? null);

        if (! $projectName) {
            return response()->json([
                'success' => false,
                'error' => 'Project name is required',
            ], 422);
        }

        $result = $this->config->php($environment, $projectName, $validated['version']);

        return response()->json($result);
    }

    /**
     * Reset PHP version for a project to default.
     */
    public function resetPhp(Request $request, Environment $environment, ?string $project = null)
    {
        $validated = $request->validate([
            'project' => $project ? 'nullable|string' : 'required|string',
        ]);

        $projectName = $project ?? ($validated['project'] ?? null);

        if (! $projectName) {
            return response()->json([
                'success' => false,
                'error' => 'Project name is required',
            ], 422);
        }

        $result = $this->config->phpReset($environment, $projectName);

        return response()->json($result);
    }

    /**
     * Get PHP configuration settings.
     */
    public function getConfig(Environment $environment, ?string $version = null)
    {
        if ($environment->is_local) {
            return $this->getLocalConfig($version);
        }

        // For remote environments, proxy to the remote API
        return $this->proxyToRemoteApi($environment, 'GET', '/php/config/'.($version ?? ''));
    }

    /**
     * Set PHP configuration settings.
     */
    public function setConfig(Request $request, Environment $environment, ?string $version = null)
    {
        if ($environment->is_local) {
            return $this->setLocalConfig($request, $version);
        }

        // For remote environments, proxy to the remote API
        return $this->proxyToRemoteApi($environment, 'POST', '/php/config/'.($version ?? ''), $request->all());
    }

    /**
     * Get local PHP configuration (macOS).
     */
    protected function getLocalConfig(?string $version = null)
    {
        $homebrewPrefix = $this->macPhpFpm->getHomebrewPrefix();
        if (! $homebrewPrefix) {
            return response()->json(['success' => false, 'error' => 'Homebrew not found']);
        }

        // Get installed versions
        $etcPhpPath = $homebrewPrefix.'/etc/php';
        $versions = [];
        if (is_dir($etcPhpPath)) {
            foreach (scandir($etcPhpPath) as $entry) {
                if (preg_match('/^\d+\.\d+$/', $entry) && is_dir($etcPhpPath.'/'.$entry)) {
                    $versions[] = $entry;
                }
            }
            usort($versions, 'version_compare');
        }

        if (empty($versions)) {
            return response()->json(['success' => false, 'error' => 'No PHP versions found']);
        }

        // Use specified version or latest
        $version = $version ?? end($versions);
        $phpIniPath = $etcPhpPath.'/'.$version.'/php.ini';
        $orbitIniPath = $this->macPhpFpm->getGlobalIniPath();

        // Read current settings from php.ini and orbit.ini
        $settings = [
            'upload_max_filesize' => $this->getIniValue($phpIniPath, 'upload_max_filesize', '2M'),
            'post_max_size' => $this->getIniValue($phpIniPath, 'post_max_size', '8M'),
            'memory_limit' => $this->getIniValue($phpIniPath, 'memory_limit', '128M'),
            'max_execution_time' => $this->getIniValue($phpIniPath, 'max_execution_time', '30'),
            // FPM pool settings - not directly configurable on macOS Homebrew
            'max_children' => '5',
            'start_servers' => '2',
            'min_spare_servers' => '1',
            'max_spare_servers' => '3',
        ];

        // Override with orbit.ini values if present
        if ($orbitIniPath && file_exists($orbitIniPath)) {
            $settings['upload_max_filesize'] = $this->getIniValue($orbitIniPath, 'upload_max_filesize', $settings['upload_max_filesize']);
            $settings['post_max_size'] = $this->getIniValue($orbitIniPath, 'post_max_size', $settings['post_max_size']);
            $settings['memory_limit'] = $this->getIniValue($orbitIniPath, 'memory_limit', $settings['memory_limit']);
            $settings['max_execution_time'] = $this->getIniValue($orbitIniPath, 'max_execution_time', $settings['max_execution_time']);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'version' => $version,
                'settings' => $settings,
                'paths' => [
                    'php_ini' => $phpIniPath,
                    'orbit_ini' => $orbitIniPath,
                ],
            ],
        ]);
    }

    /**
     * Set local PHP configuration (macOS).
     */
    protected function setLocalConfig(Request $request, ?string $version = null)
    {
        $orbitIniPath = $this->macPhpFpm->getGlobalIniPath();
        if (! $orbitIniPath) {
            return response()->json(['success' => false, 'error' => 'Unable to determine config path']);
        }

        // Ensure directory exists
        $dir = dirname($orbitIniPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Build new orbit.ini content
        $settings = [];
        $allowedKeys = ['upload_max_filesize', 'post_max_size', 'memory_limit', 'max_execution_time'];

        foreach ($allowedKeys as $key) {
            if ($request->has($key) && $request->input($key)) {
                $settings[$key] = $request->input($key);
            }
        }

        if (empty($settings)) {
            return response()->json(['success' => false, 'error' => 'No valid settings provided']);
        }

        // Generate ini content
        $content = "; Orbit global PHP settings\n";
        $content .= "; Auto-generated by Orbit Desktop\n\n";
        foreach ($settings as $key => $value) {
            $content .= "{$key} = {$value}\n";
        }

        // Write file
        if (file_put_contents($orbitIniPath, $content) === false) {
            return response()->json(['success' => false, 'error' => 'Failed to write config file']);
        }

        // PHP-FPM will auto-restart due to file watcher (LaunchAgent)

        return response()->json([
            'success' => true,
            'data' => [
                'updated' => array_keys($settings),
                'settings' => $settings,
            ],
        ]);
    }

    /**
     * Get a value from an INI file.
     */
    protected function getIniValue(string $path, string $key, string $default = ''): string
    {
        if (! file_exists($path)) {
            return $default;
        }

        $content = file_get_contents($path);
        if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*([^;\n]+)/m', $content, $matches)) {
            return trim($matches[1]);
        }

        return $default;
    }

    /**
     * Proxy a request to the remote API.
     */
    protected function proxyToRemoteApi(Environment $environment, string $method, string $path, array $data = [])
    {
        $remoteApiUrl = $this->getRemoteApiUrl($environment);
        if (! $remoteApiUrl) {
            return response()->json(['success' => false, 'error' => 'Remote API URL not available']);
        }

        try {
            $client = new \GuzzleHttp\Client(['verify' => false]);
            $options = ['timeout' => 30];

            if ($method === 'POST' && ! empty($data)) {
                $options['json'] = $data;
            }

            $response = $client->request($method, $remoteApiUrl.$path, $options);
            $body = json_decode($response->getBody()->getContents(), true);

            return response()->json($body);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
