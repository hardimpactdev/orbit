<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Process;

class LaunchpadService
{
    protected SshService $ssh;
    protected CliUpdateService $cliUpdate;

    // Common installation paths for launchpad on remote servers
    protected array $binaryPaths = [
        '$HOME/projects/launchpad/launchpad',
        '$HOME/.local/bin/launchpad',
        '/usr/local/bin/launchpad',
        '$HOME/.composer/vendor/bin/launchpad',
    ];

    public function __construct(SshService $ssh, CliUpdateService $cliUpdate)
    {
        $this->ssh = $ssh;
        $this->cliUpdate = $cliUpdate;
    }

    protected function findBinary(Server $server): ?string
    {
        // Check common locations including project directory
        $paths = [
            '$HOME/projects/launchpad/launchpad',
            '$HOME/.local/bin/launchpad',
            '/usr/local/bin/launchpad',
            '$HOME/.composer/vendor/bin/launchpad',
        ];

        foreach ($paths as $path) {
            $result = $this->ssh->execute($server, "test -x {$path} && echo {$path}");
            if ($result['success'] && !empty(trim($result['output']))) {
                return trim($result['output']);
            }
        }

        // Fallback: try which with extended PATH
        $result = $this->ssh->execute(
            $server,
            'export PATH="$HOME/projects/launchpad:$HOME/.local/bin:$HOME/.composer/vendor/bin:/usr/local/bin:$PATH" && which launchpad'
        );

        if ($result['success'] && !empty(trim($result['output']))) {
            return trim($result['output']);
        }

        return null;
    }

    public function status(Server $server): array
    {
        return $this->executeCommand($server, 'status --json');
    }

    public function sites(Server $server): array
    {
        return $this->executeCommand($server, 'sites --json');
    }

    public function start(Server $server, ?string $site = null): array
    {
        $command = $site ? "start {$site} --json" : 'start --json';
        return $this->executeCommand($server, $command);
    }

    public function stop(Server $server, ?string $site = null): array
    {
        $command = $site ? "stop {$site} --json" : 'stop --json';
        return $this->executeCommand($server, $command);
    }

    public function restart(Server $server, ?string $site = null): array
    {
        $command = $site ? "restart {$site} --json" : 'restart --json';
        return $this->executeCommand($server, $command);
    }

    public function php(Server $server, string $site, ?string $version = null): array
    {
        $command = $version
            ? "php {$site} {$version} --json"
            : "php {$site} --json";
        return $this->executeCommand($server, $command);
    }

    public function phpReset(Server $server, string $site): array
    {
        return $this->executeCommand($server, "php {$site} --reset --json");
    }

    public function checkInstallation(Server $server): array
    {
        // For local servers, check the bundled CLI
        if ($server->is_local) {
            return $this->checkLocalInstallation();
        }

        // For remote servers, check via SSH
        return $this->checkRemoteInstallation($server);
    }

    protected function checkLocalInstallation(): array
    {
        if (!$this->cliUpdate->isInstalled()) {
            return [
                'installed' => false,
                'path' => null,
                'version' => null,
            ];
        }

        $pharPath = $this->cliUpdate->getPharPath();
        $result = Process::timeout(10)->run("php {$pharPath} --version");

        return [
            'installed' => true,
            'path' => $pharPath,
            'version' => trim($result->output()),
        ];
    }

    protected function checkRemoteInstallation(Server $server): array
    {
        $path = $this->findBinary($server);

        if (!$path) {
            return [
                'installed' => false,
                'path' => null,
                'version' => null,
            ];
        }

        $versionResult = $this->ssh->execute($server, "{$path} --version");

        return [
            'installed' => true,
            'path' => $path,
            'version' => trim($versionResult['output']),
        ];
    }

    protected function executeCommand(Server $server, string $command): array
    {
        // For local servers, use the bundled CLI directly
        if ($server->is_local) {
            return $this->executeLocalCommand($command);
        }

        // For remote servers, use SSH
        return $this->executeRemoteCommand($server, $command);
    }

    protected function executeLocalCommand(string $command): array
    {
        if (!$this->cliUpdate->isInstalled()) {
            return [
                'success' => false,
                'error' => 'Launchpad CLI not installed. Please update the CLI first.',
                'exit_code' => 1,
            ];
        }

        $pharPath = $this->cliUpdate->getPharPath();
        $fullCommand = "php {$pharPath} {$command}";

        $result = Process::timeout(30)->run($fullCommand);

        if (!$result->successful()) {
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
                'error' => 'Failed to parse JSON: ' . json_last_error_msg(),
                'exit_code' => $result->exitCode(),
            ];
        }

        return $decoded;
    }

    protected function executeRemoteCommand(Server $server, string $command): array
    {
        $path = $this->findBinary($server);

        if (!$path) {
            return [
                'success' => false,
                'error' => 'Launchpad CLI not found on remote server',
                'exit_code' => 1,
            ];
        }

        $fullCommand = "{$path} {$command}";
        $result = $this->ssh->executeJson($server, $fullCommand);

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'Command failed',
                'exit_code' => $result['exit_code'] ?? 1,
            ];
        }

        // CLI returns {success: bool, data: {...}} - return it directly
        return $result['data'];
    }

    public function getCliStatus(): array
    {
        return $this->cliUpdate->getStatus();
    }

    public function updateCli(): array
    {
        return $this->cliUpdate->checkAndUpdate();
    }
}
