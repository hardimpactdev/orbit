<?php

namespace App\Console\Commands;

use App\Services\MacBrewService;
use App\Services\MacHorizonService;
use App\Services\MacPhpFpmService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class StatusCommand extends Command
{
    protected $signature = 'status
        {--json : Output as JSON}';

    protected $description = 'Show status of Launchpad services (PHP-FPM architecture)';

    public function handle(
        MacPhpFpmService $phpFpmService,
        MacBrewService $brewService,
        MacHorizonService $horizonService
    ): int {
        $status = $this->collectStatus($phpFpmService, $brewService, $horizonService);

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return Command::SUCCESS;
        }

        $this->displayStatus($status);

        return Command::SUCCESS;
    }

    /**
     * Collect status from all services.
     *
     * @return array{architecture: string, services: array<string, array{type: string, running: bool, status: string, socket?: string}>}
     */
    protected function collectStatus(
        MacPhpFpmService $phpFpmService,
        MacBrewService $brewService,
        MacHorizonService $horizonService
    ): array {
        $architecture = $this->detectArchitecture();
        $status = [
            'architecture' => $architecture,
            'services' => [],
        ];

        if ($architecture === 'fpm') {
            // PHP-FPM services
            $versions = $phpFpmService->detectInstalledPhpVersions();
            foreach ($versions as $version) {
                $serviceStatus = $brewService->getServiceStatus("php@{$version}");
                $socketExists = file_exists($phpFpmService->getSocketPath($version));

                $status['services']["php-{$version}"] = [
                    'type' => 'php-fpm',
                    'running' => $serviceStatus['running'] && $socketExists,
                    'status' => $serviceStatus['running'] ? ($socketExists ? 'running' : 'no socket') : 'stopped',
                    'socket' => $phpFpmService->getSocketPath($version),
                ];
            }

            // Caddy (host)
            $caddyStatus = $brewService->getServiceStatus('caddy');
            $status['services']['caddy'] = [
                'type' => 'host',
                'running' => $caddyStatus['running'],
                'status' => $caddyStatus['status'],
            ];

            // Horizon (launchd)
            $horizonRunning = $horizonService->isRunning();
            $status['services']['horizon'] = [
                'type' => 'launchd',
                'running' => $horizonRunning,
                'status' => $horizonRunning ? 'running' : ($horizonService->isLoaded() ? 'stopped' : 'not installed'),
            ];
        }

        // Docker containers (always check these)
        $dockerContainers = ['postgres', 'redis', 'mailpit', 'reverb', 'dns'];
        foreach ($dockerContainers as $container) {
            $containerName = "launchpad-{$container}";
            $dockerStatus = $this->getDockerContainerStatus($containerName);
            $status['services'][$container] = [
                'type' => 'docker',
                'running' => $dockerStatus['running'],
                'status' => $dockerStatus['status'],
            ];
        }

        return $status;
    }

    /**
     * Detect the current architecture (fpm or frankenphp).
     */
    protected function detectArchitecture(): string
    {
        // Check if PHP-FPM sockets exist
        $home = getenv('HOME');
        $socketPath = is_string($home) && $home !== '' ? $home.'/.config/launchpad/php' : '';

        if ($socketPath !== '' && is_dir($socketPath)) {
            $sockets = glob($socketPath.'/*.sock');
            if (is_array($sockets) && ! empty($sockets)) {
                return 'fpm';
            }
        }

        // Check if FrankenPHP containers are running
        $result = Process::run('docker ps --format "{{.Names}}" 2>/dev/null | grep -E "launchpad-php-8[345]" | head -1');
        if ($result->successful() && trim($result->output()) !== '') {
            return 'frankenphp';
        }

        // Default to fpm if neither detected
        return 'unknown';
    }

    /**
     * Get Docker container status.
     *
     * @return array{running: bool, status: string}
     */
    protected function getDockerContainerStatus(string $containerName): array
    {
        $result = Process::run("docker inspect --format='{{.State.Status}}' {$containerName} 2>/dev/null");

        if (! $result->successful()) {
            return ['running' => false, 'status' => 'not found'];
        }

        $state = trim($result->output());

        return [
            'running' => $state === 'running',
            'status' => $state ?: 'unknown',
        ];
    }

    /**
     * Display status in a human-readable format.
     *
     * @param  array{architecture: string, services: array<string, array{type: string, running: bool, status: string, socket?: string}>}  $status
     */
    protected function displayStatus(array $status): void
    {
        $this->info('Architecture: '.$status['architecture']);
        $this->newLine();

        $this->info('Services:');
        $services = $status['services'];

        foreach ($services as $name => $service) {
            $icon = $service['running'] ? '✓' : '✗';
            $color = $service['running'] ? 'green' : 'red';
            $type = $service['type'];

            $this->line("  <fg={$color}>{$icon}</> {$name}: {$service['status']} ({$type})");
        }
    }
}
