<?php
declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\OrbitCli\Shared;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\SshService;
use Illuminate\Support\Facades\Process;

/**
 * Shared service for executing orbit CLI commands.
 * Handles both local and remote (SSH) command execution with JSON parsing.
 */
class CommandService
{
    // Common installation paths for orbit on remote servers
    // Installed phar first, then dev paths as fallback
    protected const array BINARY_PATHS = [
        '$HOME/.local/bin/orbit',
        '/usr/local/bin/orbit',
        '$HOME/projects/orbit-cli/orbit',
        '$HOME/projects/orbit/orbit',
        '$HOME/.composer/vendor/bin/orbit',
    ];

    public function __construct(
        protected SshService $ssh
    ) {}

    /**
     * Execute a orbit CLI command on the environment.
     * Routes to local or remote execution based on environment type.
     *
     * @param  int  $timeout  Timeout in seconds (default 60, use 600 for provisioning)
     */
    public function executeCommand(Environment $environment, string $command, int $timeout = 60): array
    {
        // For local servers, use the bundled CLI directly
        if ($environment->is_local) {
            return $this->executeLocalCommand($command, $timeout);
        }

        // For remote servers, use SSH
        return $this->executeRemoteCommand($environment, $command);
    }

    /**
     * Execute a command locally using the CLI.
     *
     * @param  int  $timeout  Timeout in seconds (default 60, use 600 for provisioning)
     */
    public function executeLocalCommand(string $command, int $timeout = 60): array
    {
        $cliPath = $this->getCliPath();

        if (! $cliPath || ! file_exists($cliPath)) {
            return [
                'success' => false,
                'error' => 'Orbit CLI not found. Set ORBIT_CLI_PATH in .env',
                'exit_code' => 1,
            ];
        }

        $fullCommand = "{$cliPath} {$command}";

        try {
            \Illuminate\Support\Facades\Log::info("CommandService executing: {$fullCommand}");
            $result = Process::timeout($timeout)->run($fullCommand);

            if (! $result->successful()) {
                $error = $result->errorOutput() ?: $result->output() ?: 'Command failed';
                \Illuminate\Support\Facades\Log::error("CommandService failed: {$error}", [
                    'exit_code' => $result->exitCode(),
                    'stdout' => $result->output(),
                    'stderr' => $result->errorOutput(),
                ]);

                return [
                    'success' => false,
                    'error' => $error,
                    'exit_code' => $result->exitCode(),
                ];
            }

            $decoded = json_decode($result->output(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'success' => false,
                    'error' => 'Failed to parse JSON: '.json_last_error_msg(),
                    'exit_code' => $result->exitCode(),
                ];
            }

            return $decoded;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Command timed out or failed: '.$e->getMessage(),
                'exit_code' => 1,
            ];
        }
    }

    /**
     * Execute a command on a remote environment via SSH.
     */
    public function executeRemoteCommand(Environment $environment, string $command): array
    {
        // Use configured path or default to installed phar
        $cliPath = $environment->cli_path ?? '$HOME/.local/bin/orbit';
        $fullCommand = "{$cliPath} {$command}";

        $result = $this->ssh->executeJson($environment, $fullCommand);

        if (! $result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'Command failed',
                'exit_code' => $result['exit_code'] ?? 1,
            ];
        }

        // CLI returns {success: bool, data: {...}} - return it directly
        return $result['data'];
    }

    /**
     * Find the orbit binary on a remote environment.
     */
    public function findBinary(Environment $environment): ?string
    {
        // Single SSH call to find orbit binary
        // Check paths in order of preference (installed phar first)
        $command = <<<'BASH'
for path in "$HOME/.local/bin/orbit" "/usr/local/bin/orbit" "$HOME/projects/orbit-cli/orbit" "$HOME/projects/orbit/orbit" "$HOME/.composer/vendor/bin/orbit"; do
    if [ -x "$path" ]; then echo "$path"; exit 0; fi
done
exit 1
BASH;

        $result = $this->ssh->execute($environment, $command);
        if ($result['success'] && ! in_array(trim((string) $result['output']), ['', '0'], true)) {
            return trim((string) $result['output']);
        }

        return null;
    }

    /**
     * Check if CLI is installed locally.
     */
    public function isLocalCliInstalled(): bool
    {
        $cliPath = $this->getCliPath();

        return $cliPath && file_exists($cliPath);
    }

    /**
     * Get the local CLI path.
     */
    public function getLocalCliPath(): ?string
    {
        return $this->getCliPath();
    }

    /**
     * Execute a command without expecting JSON output.
     * Used for operations where we just need success/failure.
     */
    public function executeRawCommand(Environment $environment, string $command, int $timeout = 120): array
    {
        if ($environment->is_local) {
            $cliPath = $this->getCliPath();

            if (! $cliPath || ! file_exists($cliPath)) {
                return ['success' => false, 'error' => 'Orbit CLI not found. Set ORBIT_CLI_PATH in .env'];
            }

            $result = Process::timeout($timeout)->run("{$cliPath} {$command}");

            return [
                'success' => $result->successful(),
                'output' => $result->output(),
                'error' => $result->successful() ? null : ($result->errorOutput() ?: 'Command failed'),
            ];
        }

        // Remote server
        $path = $this->findBinary($environment);
        if (! $path) {
            return ['success' => false, 'error' => 'Orbit CLI not found on remote server'];
        }

        $result = $this->ssh->execute($environment, "{$path} {$command}", $timeout);

        return [
            'success' => $result['success'],
            'output' => $result['output'] ?? '',
            'error' => $result['success'] ? null : ($result['error'] ?? 'Command failed'),
        ];
    }

    /**
     * Get the CLI executable path from config or auto-detect.
     */
    protected function getCliPath(): ?string
    {
        // First check config
        $configPath = config('orbit.cli_path');
        if ($configPath && file_exists($configPath)) {
            return $configPath;
        }

        // Get home directory reliably (works in both CLI and HTTP context)
        $home = $this->getHomeDirectory();

        // Auto-detect via common paths (absolute paths first, then home-relative)
        $commonPaths = [
            '/usr/local/bin/orbit',
            '/opt/homebrew/bin/orbit',
        ];

        // Add home-based paths if we have a valid home directory
        if ($home) {
            $commonPaths[] = $home . '/.local/bin/orbit';
            $commonPaths[] = $home . '/.composer/vendor/bin/orbit';
        }

        foreach ($commonPaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Try `which orbit` as last resort (may not work in HTTP context)
        $result = @shell_exec('which orbit 2>/dev/null');
        if ($result) {
            $path = trim($result);
            if ($path && file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Get the user's home directory reliably.
     * Works in both CLI and HTTP context.
     */
    protected function getHomeDirectory(): ?string
    {
        // Try environment variables first
        $home = getenv('HOME');
        if ($home && is_dir($home)) {
            return $home;
        }

        // Try posix extension (available on most Unix systems)
        if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $userInfo = posix_getpwuid(posix_getuid());
            if (isset($userInfo['dir']) && is_dir($userInfo['dir'])) {
                return $userInfo['dir'];
            }
        }

        // Try common patterns
        $user = getenv('USER') ?: (getenv('LOGNAME') ?: null);
        if ($user) {
            $possibleHomes = [
                '/Users/' . $user,  // macOS
                '/home/' . $user,   // Linux
            ];
            foreach ($possibleHomes as $possibleHome) {
                if (is_dir($possibleHome)) {
                    return $possibleHome;
                }
            }
        }

        return null;
    }
}
