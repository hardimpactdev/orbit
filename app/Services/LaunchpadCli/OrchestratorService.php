<?php

namespace App\Services\LaunchpadCli;

use App\Models\Environment;
use App\Services\LaunchpadCli\Shared\CommandService;
use App\Services\SshService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Service for orchestrator integration (Linear, VibeKanban, MCP).
 */
class OrchestratorService
{
    public function __construct(
        protected CommandService $command,
        protected SshService $ssh,
        protected ConfigurationService $config
    ) {}

    /**
     * Detect existing orchestrator installations in scan paths.
     * Looks for a Laravel app in an 'orchestrator' directory.
     */
    public function detectOrchestrator(Environment $environment): array
    {
        $config = $this->config->getConfig($environment);
        if (! $config['success']) {
            return ['success' => false, 'error' => 'Failed to get config'];
        }

        $paths = $config['data']['paths'] ?? [];
        $tld = $config['data']['tld'] ?? 'test';
        $found = [];

        foreach ($paths as $scanPath) {
            // Check for orchestrator directory
            $orchestratorPath = rtrim((string) $scanPath, '/').'/orchestrator';

            if ($environment->is_local) {
                // Local check - look for Laravel app (artisan + public/index.php)
                if (is_dir($orchestratorPath) &&
                    file_exists($orchestratorPath.'/artisan') &&
                    file_exists($orchestratorPath.'/public/index.php')) {
                    $found[] = [
                        'path' => $orchestratorPath,
                        'url' => "https://orchestrator.{$tld}",
                        'configured' => $environment->orchestrator_url !== null,
                    ];
                }
            } else {
                // Remote check via SSH - look for Laravel app
                $checkCmd = "test -d {$orchestratorPath} && test -f {$orchestratorPath}/artisan && test -f {$orchestratorPath}/public/index.php && echo 'found'";
                $result = $this->ssh->execute($environment, $checkCmd);

                if ($result['success'] && str_contains((string) $result['output'], 'found')) {
                    $found[] = [
                        'path' => $orchestratorPath,
                        'url' => "https://orchestrator.{$tld}",
                        'configured' => $environment->orchestrator_url !== null,
                    ];
                }
            }
        }

        return [
            'success' => true,
            'installations' => $found,
            'tld' => $tld,
        ];
    }

    /**
     * Install orchestrator in the first scan path.
     */
    public function installOrchestrator(Environment $environment): array
    {
        $config = $this->config->getConfig($environment);
        if (! $config['success']) {
            return ['success' => false, 'error' => 'Failed to get config'];
        }

        $paths = $config['data']['paths'] ?? [];
        $tld = $config['data']['tld'] ?? 'test';

        if (empty($paths)) {
            return ['success' => false, 'error' => 'No scan paths configured'];
        }

        $installPath = rtrim((string) $paths[0], '/').'/orchestrator';
        $orchestratorUrl = "https://orchestrator.{$tld}";

        if ($environment->is_local) {
            return $this->installOrchestratorLocal($environment, $installPath, $orchestratorUrl, $tld);
        }

        return $this->installOrchestratorRemote($environment, $installPath, $orchestratorUrl, $tld);
    }

    /**
     * Reconcile/reconfigure orchestrator linking with CLI.
     * This sets up the MCP connection and ensures proper configuration.
     */
    public function reconcileOrchestrator(Environment $environment, string $orchestratorPath): array
    {
        $config = $this->config->getConfig($environment);
        if (! $config['success']) {
            return ['success' => false, 'error' => 'Failed to get config'];
        }

        $tld = $config['data']['tld'] ?? 'test';
        $orchestratorUrl = "https://orchestrator.{$tld}";

        // Update environment with orchestrator URL
        $environment->update(['orchestrator_url' => $orchestratorUrl]);

        // Update CLI config with orchestrator settings including MCP
        $currentConfig = $config['data'];
        $currentConfig['orchestrator'] = [
            'url' => $orchestratorUrl,
            'mcp' => [
                'endpoint' => $orchestratorUrl.'/mcp',
            ],
        ];

        $saveResult = $this->config->saveConfig($environment, $currentConfig);
        if (! $saveResult['success']) {
            return ['success' => false, 'error' => 'Failed to save config: '.($saveResult['error'] ?? 'Unknown error')];
        }

        return [
            'success' => true,
            'url' => $orchestratorUrl,
            'path' => $orchestratorPath,
            'message' => 'Orchestrator reconciled successfully',
        ];
    }

    /**
     * Set orchestrator URL in CLI config.
     */
    public function setOrchestratorUrl(Environment $environment, string $url): array
    {
        // Get current config
        $currentConfig = $this->config->getConfig($environment);

        if (! $currentConfig['success']) {
            // Start with default config if none exists
            $config = $this->config->getDefaultConfig();
        } else {
            $config = $currentConfig['data'];
        }

        // Add/update orchestrator settings
        $config['orchestrator'] = [
            'url' => $url,
        ];

        // Save the updated config
        return $this->config->saveConfig($environment, $config);
    }

    /**
     * Remove orchestrator URL from CLI config.
     */
    public function removeOrchestratorUrl(Environment $environment): array
    {
        // Get current config
        $currentConfig = $this->config->getConfig($environment);

        if (! $currentConfig['success']) {
            return ['success' => true]; // Nothing to remove
        }

        $config = $currentConfig['data'];

        // Remove orchestrator settings
        unset($config['orchestrator']);

        // Save the updated config
        return $this->config->saveConfig($environment, $config);
    }

    /**
     * Get Linear teams from the orchestrator.
     */
    public function getLinearTeams(Environment $environment): array
    {
        $result = $this->callOrchestratorMcp($environment, 'list-linear-teams', []);

        if (! $result['success']) {
            return ['success' => false, 'error' => $result['error'] ?? 'Failed to fetch Linear teams'];
        }

        $data = $result['data'];

        if (isset($data['error'])) {
            return ['success' => false, 'error' => $data['error']['message'] ?? 'Unknown MCP error'];
        }

        // Teams are returned in the meta data from ListLinearTeamsTool
        $teams = $data['result']['_meta']['teams'] ?? $data['result']['meta']['teams'] ?? [];

        return ['success' => true, 'data' => $teams];
    }

    /**
     * Call the orchestrator's create-project MCP tool directly.
     * Used when creating integrated projects that need GitHub/Linear/VK setup.
     */
    public function createIntegratedProject(Environment $environment, array $options): array
    {
        if (! $environment->orchestrator_url) {
            return [
                'success' => false,
                'error' => 'No orchestrator URL configured for this environment',
            ];
        }

        // Get config to determine local_path and GitHub org
        $config = $this->config->getConfig($environment);
        if (! $config['success']) {
            return ['success' => false, 'error' => 'Failed to get environment config'];
        }

        $paths = $config['data']['paths'] ?? [];
        if (empty($paths)) {
            return ['success' => false, 'error' => 'No scan paths configured'];
        }

        $projectName = $options['name'];
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9-]/', '-', (string) $projectName));
        $localPath = rtrim((string) $paths[0], '/').'/'.$slug;

        // Get GitHub org from config or default to user's GitHub account
        $githubOrg = $config['data']['github_org'] ?? 'nckrtl';
        $githubRepo = $githubOrg.'/'.$slug;

        $arguments = [
            'name' => $projectName,
            'local_path' => $localPath,
            'github_repo' => $githubRepo,
        ];

        if (! empty($options['template'])) {
            $arguments['template_repo'] = $options['template'];
        }

        if (! empty($options['linearTeamId'])) {
            $arguments['linear_team_id'] = $options['linearTeamId'];
        }

        if (! empty($options['visibility'])) {
            $arguments['github_visibility'] = $options['visibility'];
        }

        $mcpResult = $this->callOrchestratorMcp($environment, 'create-project', $arguments);

        if (! $mcpResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to create project: '.($mcpResult['error'] ?? 'Unknown error'),
            ];
        }

        $data = $mcpResult['data'];

        if (isset($data['error'])) {
            return [
                'success' => false,
                'error' => $data['error']['message'] ?? 'Unknown MCP error',
            ];
        }

        // Extract meta from MCP response
        $meta = $data['result']['meta'] ?? [];

        // The orchestrator triggers background provisioning via CLI's `launchpad provision`
        // which handles: GitHub repo creation -> clone -> setup -> Caddy reload
        // We just return 'provisioning' status and let the background process complete
        $tld = $config['data']['tld'] ?? 'test';

        return [
            'success' => true,
            'data' => [
                'slug' => $slug,
                'project_slug' => $slug,
                'path' => $localPath,
                'local_path' => $localPath,
                'site_url' => "https://{$slug}.{$tld}",
                'github_url' => "https://github.com/{$githubRepo}",
                'orchestrator_id' => $meta['project_id'] ?? null,
                'status' => 'provisioning',
                'websocket_channel' => $meta['websocket_channel'] ?? "project.{$slug}",
            ],
        ];
    }

    /**
     * Upgrade a standalone project to integrated.
     * Calls the orchestrator's create-project MCP tool with existing repository_url.
     */
    public function upgradeToIntegrated(Environment $environment, array $options): array
    {
        $arguments = [
            'name' => $options['name'],
            'repository_url' => $options['repository_url'],
            'local_path' => $options['local_path'],
        ];

        if (! empty($options['linearTeamId'])) {
            $arguments['linear_team_id'] = $options['linearTeamId'];
        }

        if (! empty($options['description'])) {
            $arguments['description'] = $options['description'];
        }

        $result = $this->callOrchestratorMcp($environment, 'create-project', $arguments);

        if (! $result['success']) {
            return ['success' => false, 'error' => 'Failed to upgrade project: '.($result['error'] ?? 'Unknown error')];
        }

        $data = $result['data'];

        if (isset($data['error'])) {
            return ['success' => false, 'error' => $data['error']['message'] ?? 'Unknown MCP error'];
        }

        // Extract orchestrator_id from meta
        $meta = $data['result']['meta'] ?? [];

        return [
            'success' => true,
            'data' => [
                'orchestrator_id' => $meta['project_id'] ?? null,
                'integrations' => $meta['integrations'] ?? [],
                'status' => $meta['status'] ?? 'ready',
            ],
        ];
    }

    /**
     * Delete a project via the orchestrator's MCP endpoint directly.
     * Used for integrated projects that need cascade deletion to VK/Linear.
     */
    public function deleteIntegratedProject(Environment $environment, string $slug): array
    {
        $result = $this->callOrchestratorMcp($environment, 'delete-project', [
            'slug' => $slug,
            'confirm_slug' => $slug,
        ]);

        if (! $result['success']) {
            return ['success' => false, 'error' => 'Failed to delete project: '.($result['error'] ?? 'Unknown error')];
        }

        $data = $result['data'];

        if (isset($data['error'])) {
            return ['success' => false, 'error' => $data['error']['message'] ?? 'Unknown MCP error'];
        }

        return ['success' => true, 'data' => $data['result'] ?? []];
    }

    /**
     * Call an MCP tool on the orchestrator.
     * For remote environments, proxies through SSH to avoid DNS issues.
     */
    protected function callOrchestratorMcp(Environment $environment, string $tool, array $arguments): array
    {
        $orchestratorUrl = $environment->orchestrator_url;

        if (! $orchestratorUrl) {
            return [
                'success' => false,
                'error' => 'No orchestrator URL configured for this environment',
            ];
        }

        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => $tool,
                'arguments' => $arguments,
            ],
            'id' => uniqid('mcp_', true),
        ];

        if ($environment->is_local) {
            // Local: direct HTTP call
            try {
                $response = Http::timeout(120)
                    ->withoutVerifying()
                    ->post(rtrim($orchestratorUrl, '/').'/mcp', $payload);

                if (! $response->successful()) {
                    return ['success' => false, 'error' => 'HTTP '.$response->status()];
                }

                return ['success' => true, 'data' => $response->json()];
            } catch (\Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        // Remote: proxy through SSH using curl
        // Use --resolve to bypass system DNS since the launchpad-dns container
        // may not be configured as the system resolver
        $jsonPayload = json_encode($payload);
        $escapedPayload = escapeshellarg($jsonPayload);
        $mpcUrl = rtrim($orchestratorUrl, '/').'/mcp';
        $escapedUrl = escapeshellarg($mpcUrl);

        // Extract host from URL for --resolve flag
        $parsedUrl = parse_url($orchestratorUrl);
        $host = $parsedUrl['host'] ?? '';
        $port = ($parsedUrl['scheme'] ?? 'https') === 'https' ? 443 : 80;

        $curlCommand = "curl -s -k -X POST {$escapedUrl} --resolve '{$host}:{$port}:127.0.0.1' -H 'Content-Type: application/json' -d {$escapedPayload}";
        $result = $this->ssh->execute($environment, $curlCommand, 120);

        if (! $result['success']) {
            return ['success' => false, 'error' => $result['error'] ?? 'SSH command failed'];
        }

        $responseData = json_decode((string) $result['output'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'error' => 'Invalid JSON response: '.$result['output']];
        }

        return ['success' => true, 'data' => $responseData];
    }

    /**
     * Install orchestrator on local environment.
     */
    protected function installOrchestratorLocal(Environment $environment, string $installPath, string $orchestratorUrl, string $tld): array
    {
        // Check if already exists
        if (is_dir($installPath)) {
            return ['success' => false, 'error' => 'Orchestrator directory already exists at '.$installPath];
        }

        // Clone the repository
        $cloneResult = Process::timeout(120)->run("git clone https://github.com/hardimpactdev/orchestrator.git {$installPath}");
        if (! $cloneResult->successful()) {
            return ['success' => false, 'error' => 'Failed to clone orchestrator: '.$cloneResult->errorOutput()];
        }

        // Run composer install
        $composerResult = Process::timeout(300)->path($installPath)->run('composer install --no-interaction');
        if (! $composerResult->successful()) {
            return ['success' => false, 'error' => 'Failed to run composer install: '.$composerResult->errorOutput()];
        }

        // Copy .env.example to .env if it doesn't exist
        if (! file_exists($installPath.'/.env') && file_exists($installPath.'/.env.example')) {
            copy($installPath.'/.env.example', $installPath.'/.env');
        }

        // Generate app key
        Process::timeout(30)->path($installPath)->run('php artisan key:generate');

        // Run migrations
        Process::timeout(60)->path($installPath)->run('php artisan migrate --force');

        // Update environment with orchestrator URL
        $environment->update(['orchestrator_url' => $orchestratorUrl]);

        // Update CLI config with orchestrator URL
        $this->setOrchestratorUrl($environment, $orchestratorUrl);

        return [
            'success' => true,
            'path' => $installPath,
            'url' => $orchestratorUrl,
            'message' => 'Orchestrator installed successfully',
        ];
    }

    /**
     * Install orchestrator on remote environment.
     */
    protected function installOrchestratorRemote(Environment $environment, string $installPath, string $orchestratorUrl, string $tld): array
    {
        // Check if already exists
        $checkResult = $this->ssh->execute($environment, "test -d {$installPath} && echo 'exists'");
        if (str_contains((string) $checkResult['output'], 'exists')) {
            return ['success' => false, 'error' => 'Orchestrator directory already exists at '.$installPath];
        }

        // Clone the repository
        $cloneResult = $this->ssh->execute($environment, "git clone https://github.com/hardimpactdev/orchestrator.git {$installPath}", 120);
        if (! $cloneResult['success']) {
            return ['success' => false, 'error' => 'Failed to clone orchestrator: '.($cloneResult['error'] ?? 'Unknown error')];
        }

        // Run composer install
        $composerResult = $this->ssh->execute($environment, "cd {$installPath} && composer install --no-interaction", 300);
        if (! $composerResult['success']) {
            return ['success' => false, 'error' => 'Failed to run composer install: '.($composerResult['error'] ?? 'Unknown error')];
        }

        // Copy .env.example to .env if it doesn't exist
        $this->ssh->execute($environment, "cd {$installPath} && test -f .env || cp .env.example .env");

        // Generate app key
        $this->ssh->execute($environment, "cd {$installPath} && php artisan key:generate", 30);

        // Run migrations
        $this->ssh->execute($environment, "cd {$installPath} && php artisan migrate --force", 60);

        // Update environment with orchestrator URL
        $environment->update(['orchestrator_url' => $orchestratorUrl]);

        // Update CLI config with orchestrator URL
        $this->setOrchestratorUrl($environment, $orchestratorUrl);

        return [
            'success' => true,
            'path' => $installPath,
            'url' => $orchestratorUrl,
            'message' => 'Orchestrator installed successfully',
        ];
    }
}
