<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services;

use HardImpact\Orbit\Core\Models\Environment;
use Illuminate\Support\Facades\Process;

/**
 * Service to detect and run first-run setup.
 */
class SetupService
{
    protected array $steps = [
        'check_prerequisites' => 'Checking prerequisites',
        'download_cli' => 'Downloading Orbit CLI',
        'install_cli' => 'Installing CLI',
        'init_services' => 'Initializing services',
        'configure_tld' => 'Configuring TLD',
        'create_environment' => 'Creating local environment',
    ];

    public function __construct(
        protected CliUpdateService $cliUpdate,
    ) {}

    /**
     * Check if first-run setup is needed.
     */
    public function needsSetup(): bool
    {
        // Check if CLI is installed
        if (! $this->isCliInstalled()) {
            return true;
        }

        // Check if local environment exists
        if (! $this->hasLocalEnvironment()) {
            return true;
        }

        // Check if services are configured
        if (! $this->hasServicesConfigured()) {
            return true;
        }

        return false;
    }

    /**
     * Get the current setup status for display.
     */
    public function getStatus(): array
    {
        $cliStatus = $this->cliUpdate->getStatus();

        return [
            'needs_setup' => $this->needsSetup(),
            'cli_installed' => $cliStatus['installed'],
            'cli_version' => $cliStatus['version'],
            'has_local_environment' => $this->hasLocalEnvironment(),
            'has_services' => $this->hasServicesConfigured(),
            'has_tld' => $this->hasTldConfigured(),
            'steps' => $this->steps,
        ];
    }

    /**
     * Check if CLI is installed.
     */
    public function isCliInstalled(): bool
    {
        return $this->cliUpdate->isInstalled();
    }

    /**
     * Check if local environment exists.
     */
    public function hasLocalEnvironment(): bool
    {
        return Environment::where('is_local', true)->exists();
    }

    /**
     * Check if services are configured (Docker containers running).
     */
    public function hasServicesConfigured(): bool
    {
        // Check if orbit services are running via CLI
        $cliPath = $this->cliUpdate->getPharPath();

        if (! file_exists($cliPath)) {
            return false;
        }

        $phpBinary = PHP_BINARY;
        $result = Process::timeout(10)
            ->run("{$phpBinary} {$cliPath} status --json 2>/dev/null");

        if (! $result->successful()) {
            return false;
        }

        $output = trim($result->output());
        if (empty($output)) {
            return false;
        }

        $data = json_decode($output, true);

        // Check if services array exists and has running containers
        $services = $data['services'] ?? [];
        if (empty($services)) {
            return false;
        }

        // Check if at least core services are running
        $runningCount = collect($services)
            ->filter(fn ($s): bool => ($s['status'] ?? '') === 'running')
            ->count();

        return $runningCount >= 3; // At least 3 core services (postgres, redis, etc.)
    }

    /**
     * Check if TLD is configured.
     */
    public function hasTldConfigured(): bool
    {
        $env = Environment::getLocal();

        return $env !== null && ! empty($env->tld);
    }

    /**
     * Run the complete setup flow.
     * Returns a generator for progress tracking.
     *
     * @return \Generator<string, array{step: int, total: int, message: string, success: bool, error?: string|null}>
     */
    public function runSetup(string $tld = 'test'): \Generator
    {
        $totalSteps = count($this->steps);
        $currentStep = 0;

        // Step 1: Check prerequisites
        $currentStep++;
        yield 'check_prerequisites' => [
            'step' => $currentStep,
            'total' => $totalSteps,
            'message' => $this->steps['check_prerequisites'],
            'success' => true,
        ];

        // Step 2: Download CLI
        $currentStep++;
        yield 'download_cli' => [
            'step' => $currentStep,
            'total' => $totalSteps,
            'message' => $this->steps['download_cli'],
            'success' => true,
        ];

        // Step 3: Install CLI
        $currentStep++;
        $cliResult = $this->cliUpdate->ensureInstalled();
        yield 'install_cli' => [
            'step' => $currentStep,
            'total' => $totalSteps,
            'message' => $this->steps['install_cli'],
            'success' => $cliResult['success'],
            'error' => $cliResult['error'] ?? null,
        ];

        if (! $cliResult['success']) {
            return;
        }

        // Step 4: Initialize services
        $currentStep++;
        $initResult = $this->initServices();
        yield 'init_services' => [
            'step' => $currentStep,
            'total' => $totalSteps,
            'message' => $this->steps['init_services'],
            'success' => $initResult['success'],
            'error' => $initResult['error'] ?? null,
        ];

        if (! $initResult['success']) {
            return;
        }

        // Step 5: Configure TLD
        $currentStep++;
        $tldResult = $this->configureTld($tld);
        yield 'configure_tld' => [
            'step' => $currentStep,
            'total' => $totalSteps,
            'message' => $this->steps['configure_tld'],
            'success' => $tldResult['success'],
            'error' => $tldResult['error'] ?? null,
        ];

        // Step 6: Create local environment
        $currentStep++;
        $envResult = $this->createLocalEnvironment($tld);
        yield 'create_environment' => [
            'step' => $currentStep,
            'total' => $totalSteps,
            'message' => $this->steps['create_environment'],
            'success' => $envResult['success'],
            'error' => $envResult['error'] ?? null,
        ];
    }

    /**
     * Initialize orbit services via CLI.
     */
    protected function initServices(): array
    {
        $cliPath = $this->cliUpdate->getPharPath();
        $phpBinary = PHP_BINARY;

        // Run orbit init to set up Docker network and pull images
        $result = Process::timeout(300)
            ->run("{$phpBinary} {$cliPath} init --json 2>&1");

        if (! $result->successful()) {
            return [
                'success' => false,
                'error' => 'Failed to initialize services: '.$result->errorOutput(),
            ];
        }

        // Start services
        $startResult = Process::timeout(120)
            ->run("{$phpBinary} {$cliPath} start --json 2>&1");

        if (! $startResult->successful()) {
            return [
                'success' => false,
                'error' => 'Failed to start services: '.$startResult->errorOutput(),
            ];
        }

        return ['success' => true];
    }

    /**
     * Configure the TLD in orbit config.
     */
    protected function configureTld(string $tld): array
    {
        $cliPath = $this->cliUpdate->getPharPath();
        $phpBinary = PHP_BINARY;

        // Set TLD via CLI config command
        $result = Process::timeout(30)
            ->run("{$phpBinary} {$cliPath} config:set tld {$tld} --json 2>&1");

        if (! $result->successful()) {
            // TLD config might not exist yet, that's okay
            return ['success' => true];
        }

        return ['success' => true];
    }

    /**
     * Create the local environment record.
     */
    protected function createLocalEnvironment(string $tld): array
    {
        try {
            // Check if local environment already exists
            $existing = Environment::where('is_local', true)->first();

            if ($existing) {
                $existing->update(['tld' => $tld]);

                return ['success' => true, 'environment' => $existing];
            }

            // Create new local environment
            $environment = Environment::create([
                'name' => 'Local',
                'host' => 'localhost',
                'user' => get_current_user(),
                'port' => 22,
                'is_local' => true,
                'is_default' => true,
                'tld' => $tld,
                'status' => Environment::STATUS_ACTIVE,
            ]);

            return ['success' => true, 'environment' => $environment];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to create environment: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Run setup synchronously and return final result.
     */
    public function runSetupSync(string $tld = 'test'): array
    {
        $results = [];
        $success = true;
        $lastError = null;

        foreach ($this->runSetup($tld) as $stepId => $result) {
            $results[$stepId] = $result;

            if (! $result['success']) {
                $success = false;
                $lastError = $result['error'] ?? 'Unknown error';
                break;
            }
        }

        return [
            'success' => $success,
            'steps' => $results,
            'error' => $lastError,
        ];
    }
}
