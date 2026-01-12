<?php

namespace App\Services\LaunchpadCli\Shared;

use App\Models\Environment;
use App\Services\CliUpdateService;
use App\Services\SshService;
use Illuminate\Support\Facades\Process;

/**
 * Shared service for executing launchpad CLI commands.
 * Handles both local and remote (SSH) command execution with JSON parsing.
 */
class CommandService
{
    // Common installation paths for launchpad on remote servers
    // Installed phar first, then dev paths as fallback
    protected const array BINARY_PATHS = [
        '$HOME/.local/bin/launchpad',
        '/usr/local/bin/launchpad',
        '$HOME/projects/launchpad-cli/launchpad',
        '$HOME/projects/launchpad/launchpad',
        '$HOME/.composer/vendor/bin/launchpad',
    ];

    public function __construct(
        protected SshService $ssh,
        protected CliUpdateService $cliUpdate
    ) {}

    /**
     * Execute a launchpad CLI command on the environment.
     * Routes to local or remote execution based on environment type.
     */
    public function executeCommand(Environment $environment, string $command): array
    {
        // For local servers, use the bundled CLI directly
        if ($environment->is_local) {
            return $this->executeLocalCommand($command);
        }

        // For remote servers, use SSH
        return $this->executeRemoteCommand($environment, $command);
    }

    /**
     * Execute a command locally using the bundled CLI.
     */
    public function executeLocalCommand(string $command): array
    {
        if (! $this->cliUpdate->isInstalled()) {
            return [
                'success' => false,
                'error' => 'Launchpad CLI not installed.',
                'exit_code' => 1,
            ];
        }

        $pharPath = $this->cliUpdate->getPharPath();
        $fullCommand = "php {$pharPath} {$command}";

        try {
            $result = Process::timeout(60)->run($fullCommand);

            if (! $result->successful()) {
                return [
                    'success' => false,
                    'error' => $result->errorOutput() ?: 'Command failed',
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
        $cliPath = $environment->cli_path ?? '$HOME/.local/bin/launchpad';
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
     * Find the launchpad binary on a remote environment.
     */
    public function findBinary(Environment $environment): ?string
    {
        // Single SSH call to find launchpad binary
        // Check paths in order of preference (installed phar first)
        $command = <<<'BASH'
for path in "$HOME/.local/bin/launchpad" "/usr/local/bin/launchpad" "$HOME/projects/launchpad-cli/launchpad" "$HOME/projects/launchpad/launchpad" "$HOME/.composer/vendor/bin/launchpad"; do
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
        return $this->cliUpdate->isInstalled();
    }

    /**
     * Get the local CLI phar path.
     */
    public function getLocalCliPath(): string
    {
        return $this->cliUpdate->getPharPath();
    }

    /**
     * Execute a command without expecting JSON output.
     * Used for operations where we just need success/failure.
     */
    public function executeRawCommand(Environment $environment, string $command, int $timeout = 120): array
    {
        if ($environment->is_local) {
            if (! $this->cliUpdate->isInstalled()) {
                return ['success' => false, 'error' => 'Launchpad CLI not installed.'];
            }

            $pharPath = $this->cliUpdate->getPharPath();
            $result = Process::timeout($timeout)->run("php {$pharPath} {$command}");

            return [
                'success' => $result->successful(),
                'output' => $result->output(),
                'error' => $result->successful() ? null : ($result->errorOutput() ?: 'Command failed'),
            ];
        }

        // Remote server
        $path = $this->findBinary($environment);
        if (! $path) {
            return ['success' => false, 'error' => 'Launchpad CLI not found on remote server'];
        }

        $result = $this->ssh->execute($environment, "{$path} {$command}", $timeout);

        return [
            'success' => $result['success'],
            'output' => $result['output'] ?? '',
            'error' => $result['success'] ? null : ($result['error'] ?? 'Command failed'),
        ];
    }
}
