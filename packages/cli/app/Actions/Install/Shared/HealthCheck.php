<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use HardImpact\Orbit\Core\Data\StepResult;
use App\Services\ConfigManager;
use App\Services\DockerManager;
use App\Services\Install\InstallLogger;
use App\Services\PhpManager;
use App\Services\ServiceManager;
use HardImpact\Orbit\Core\Models\Environment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

final readonly class HealthCheck
{
    public function __construct(
        private ConfigManager $configManager,
        private ServiceManager $serviceManager,
        private PhpManager $phpManager,
        private DockerManager $dockerManager,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        $logger->info('Running post-installation health checks...');

        // Check database tables exist
        if (! $this->checkDatabaseTables($logger)) {
            return StepResult::failed('Database tables not found');
        }

        // Check local environment record exists
        if (! $this->checkLocalEnvironment($logger)) {
            return StepResult::failed('Local environment record not found');
        }

        // Check web app accessibility
        if (! $this->checkWebAppAccess($logger)) {
            return StepResult::failed('Web app not accessible');
        }

        // Check PHP-FPM services
        if (! $this->checkPhpFpmServices($logger)) {
            return StepResult::failed('PHP-FPM services not running');
        }

        // Check Docker services (Horizon and Reverb)
        if (! $this->checkDockerServices($logger)) {
            return StepResult::failed('Required Docker services not running');
        }

        $logger->success('All health checks passed');

        return StepResult::success();
    }

    private function checkDatabaseTables(InstallLogger $logger): bool
    {
        try {
            // Check if environments table exists and has records
            $environmentsCount = DB::table('environments')->count();
            $logger->info("Found {$environmentsCount} environment(s) in database");

            // Check if projects table exists
            $projectsCount = DB::table('projects')->count();
            $logger->info("Found {$projectsCount} project(s) in database");

            return true;
        } catch (\Exception $e) {
            $logger->error("Database check failed: {$e->getMessage()}");

            return false;
        }
    }

    private function checkLocalEnvironment(InstallLogger $logger): bool
    {
        try {
            $localEnvironment = Environment::getLocal();

            if (! $localEnvironment) {
                $logger->error('Local environment record not found in database');

                return false;
            }

            $logger->info("Local environment found: {$localEnvironment->getAttribute('name')}");

            return true;
        } catch (\Exception $e) {
            $logger->error("Local environment check failed: {$e->getMessage()}");

            return false;
        }
    }

    private function checkWebAppAccess(InstallLogger $logger): bool
    {
        $tld = $this->configManager->getTld();
        $webUrl = "https://orbit.{$tld}";

        try {
            $result = Process::run("curl -s -o /dev/null -w '%{http_code}' --max-time 10 --insecure {$webUrl}");

            if ($result->successful()) {
                $statusCode = trim($result->output());
                if ($statusCode === '200') {
                    $logger->info("Web app accessible at {$webUrl}");

                    return true;
                }

                $logger->error("Web app returned status {$statusCode} at {$webUrl}");

                return false;
            }

            $logger->error("Web app check failed: {$result->errorOutput()}");

            return false;
        } catch (\Exception $e) {
            $logger->error("Web app check failed: {$e->getMessage()}");

            return false;
        }
    }

    private function checkPhpFpmServices(InstallLogger $logger): bool
    {
        $installedVersions = $this->phpManager->getInstalledVersions();

        if (empty($installedVersions)) {
            $logger->error('No PHP versions installed');

            return false;
        }

        $allRunning = true;

        foreach ($installedVersions as $version) {
            if ($this->phpManager->isRunning($version)) {
                $logger->info("PHP-FPM {$version} is running");
            } else {
                $logger->error("PHP-FPM {$version} is not running");
                $allRunning = false;
            }
        }

        return $allRunning;
    }

    private function checkDockerServices(InstallLogger $logger): bool
    {
        $requiredServices = ['reverb'];
        $enabledServices = $this->serviceManager->getEnabled();
        $allRunning = true;

        // Check if Horizon is enabled (it's part of the web app, not a Docker service)
        if ($this->isHorizonRunning()) {
            $logger->info('Horizon queue worker is running');
        } else {
            $logger->error('Horizon queue worker is not running');
            $allRunning = false;
        }

        // Check required Docker services
        foreach ($requiredServices as $service) {
            if (! isset($enabledServices[$service])) {
                $logger->warn("Service {$service} is not enabled");

                continue;
            }

            if ($this->dockerManager->isRunning("orbit-{$service}")) {
                $logger->info("Docker service {$service} is running");
            } else {
                $logger->error("Docker service {$service} is not running");
                $allRunning = false;
            }
        }

        return $allRunning;
    }

    private function isHorizonRunning(): bool
    {
        try {
            // Check if Horizon is running by looking for the process
            $result = shell_exec('pgrep -f "artisan horizon" 2>/dev/null');

            return ! empty(trim($result ?? ''));
        } catch (\Exception) {
            return false;
        }
    }
}
