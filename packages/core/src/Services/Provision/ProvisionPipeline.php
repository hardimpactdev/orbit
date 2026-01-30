<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Provision;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Data\StepResult;
use HardImpact\Orbit\Core\Services\Provision\Actions\BuildAssets;
use HardImpact\Orbit\Core\Services\Provision\Actions\CloneRepository;
use HardImpact\Orbit\Core\Services\Provision\Actions\ConfigureEnvironment;
use HardImpact\Orbit\Core\Services\Provision\Actions\ConfigureTrustedProxies;
use HardImpact\Orbit\Core\Services\Provision\Actions\CreateDatabase;
use HardImpact\Orbit\Core\Services\Provision\Actions\CreateGitHubRepository;
use HardImpact\Orbit\Core\Services\Provision\Actions\DetectNodePackageManager;
use HardImpact\Orbit\Core\Services\Provision\Actions\ForkRepository;
use HardImpact\Orbit\Core\Services\Provision\Actions\GenerateAppKey;
use HardImpact\Orbit\Core\Services\Provision\Actions\InstallComposerDependencies;
use HardImpact\Orbit\Core\Services\Provision\Actions\InstallNodeDependencies;
use HardImpact\Orbit\Core\Services\Provision\Actions\RunMigrations;
use HardImpact\Orbit\Core\Services\Provision\Actions\SetPhpVersion;

/**
 * Pipeline for running provision actions in sequence.
 *
 * Orchestrates the complete project provisioning process including:
 * - Repository operations (clone, fork, template)
 * - Dependency installation (composer, npm/bun)
 * - Environment configuration
 * - Database setup
 * - Asset building
 */
final readonly class ProvisionPipeline
{
    public function __construct(
        private GitHubService $github,
        private InstallComposerDependencies $installComposerDependencies,
        private DetectNodePackageManager $detectNodePackageManager,
        private InstallNodeDependencies $installNodeDependencies,
        private BuildAssets $buildAssets,
        private ConfigureEnvironment $configureEnvironment,
        private CreateDatabase $createDatabase,
        private GenerateAppKey $generateAppKey,
        private RunMigrations $runMigrations,
        private ConfigureTrustedProxies $configureTrustedProxies,
        private SetPhpVersion $setPhpVersion,
        private CloneRepository $cloneRepository,
        private CreateGitHubRepository $createGitHubRepository,
        private ForkRepository $forkRepository,
    ) {}

    /**
     * Run the full provisioning pipeline.
     */
    public function run(ProvisionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        if ($context->minimal) {
            return $this->runMinimal($context, $logger);
        }

        return $this->runFull($context, $logger);
    }

    /**
     * Run minimal setup (composer install only).
     */
    public function runMinimal(ProvisionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        $logger->info('Running minimal setup (composer install only)...');
        $logger->broadcast('installing_composer');

        $result = $this->installComposerDependencies->handle($context, $logger);
        if ($result->isFailed()) {
            return $result;
        }

        $logger->info('Minimal setup completed');

        return StepResult::success();
    }

    /**
     * Run full project setup.
     */
    public function runFull(ProvisionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        $logger->info('Running project setup...');

        // Step 1: Composer install
        $logger->broadcast('installing_composer');
        $result = $this->installComposerDependencies->handle($context, $logger);
        if ($result->isFailed()) {
            return $result;
        }

        // Step 2: Detect Node package manager
        $detectResult = $this->detectNodePackageManager->handle($context, $logger);
        if ($detectResult->isFailed()) {
            return $detectResult;
        }
        $packageManager = $detectResult->data['packageManager'] ?? null;

        // Step 3: Install Node dependencies
        if ($packageManager) {
            $logger->broadcast('installing_npm');
            $result = $this->installNodeDependencies->handle($context, $logger, $packageManager);
            if ($result->isFailed()) {
                return $result;
            }
        }

        // Step 4: Build assets
        if ($packageManager) {
            $logger->broadcast('building');
            $result = $this->buildAssets->handle($context, $logger, $packageManager);
            if ($result->isFailed()) {
                return $result;
            }
        }

        // Step 5: Configure environment
        $postgresConfig = $this->getPostgresConfig();
        $result = $this->configureEnvironment->handle($context, $logger, $postgresConfig);
        if ($result->isFailed()) {
            return $result;
        }

        // Step 6: Create database
        $result = $this->createDatabase->handle($context, $logger);
        if ($result->isFailed()) {
            return $result;
        }

        // Step 7: Generate app key
        $result = $this->generateAppKey->handle($context, $logger);
        if ($result->isFailed()) {
            return $result;
        }

        // Step 8: Run migrations
        $result = $this->runMigrations->handle($context, $logger);
        if ($result->isFailed()) {
            return $result;
        }

        // Step 9: Configure trusted proxies
        $result = $this->configureTrustedProxies->handle($context, $logger);
        if ($result->isFailed()) {
            return $result;
        }

        // Step 10: Set PHP version
        $result = $this->setPhpVersion->handle($context, $logger);
        if ($result->isFailed()) {
            return $result;
        }

        $logger->info('Setup completed');

        return StepResult::success();
    }

    /**
     * Clone a repository.
     */
    public function cloneRepository(ProvisionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        $logger->broadcast('cloning');

        return $this->cloneRepository->handle($context, $logger);
    }

    /**
     * Create a GitHub repository from template.
     */
    public function createFromTemplate(ProvisionContext $context, ProvisionLoggerContract $logger, string $targetRepo): StepResult
    {
        $logger->broadcast('creating_repo');

        return $this->createGitHubRepository->handle($context, $logger, $targetRepo);
    }

    /**
     * Fork a repository.
     */
    public function forkRepository(ProvisionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        $logger->broadcast('forking');

        return $this->forkRepository->handle($context, $logger);
    }

    /**
     * Get GitHub service instance.
     */
    public function getGitHubService(): GitHubService
    {
        return $this->github;
    }

    /**
     * Get PostgreSQL configuration from local orbit config.
     */
    private function getPostgresConfig(): array
    {
        $home = $_SERVER['HOME'] ?? config('orbit.home_directory');
        $servicesPath = "{$home}/.config/orbit/services.yaml";

        if (! file_exists($servicesPath)) {
            return [
                'POSTGRES_USER' => config('orbit.postgres.user'),
                'POSTGRES_PASSWORD' => config('orbit.postgres.password'),
                'port' => config('orbit.postgres.port'),
            ];
        }

        $content = file_get_contents($servicesPath);

        // Simple YAML parsing for postgres section
        if (preg_match('/postgres:.*?POSTGRES_USER:\s*(\S+)/s', $content, $userMatch)) {
            $user = $userMatch[1];
        } else {
            $user = config('orbit.postgres.user');
        }

        if (preg_match('/postgres:.*?POSTGRES_PASSWORD:\s*(\S+)/s', $content, $passMatch)) {
            $password = $passMatch[1];
        } else {
            $password = config('orbit.postgres.password');
        }

        if (preg_match('/postgres:.*?port:\s*(\d+)/s', $content, $portMatch)) {
            $port = (int) $portMatch[1];
        } else {
            $port = config('orbit.postgres.port');
        }

        return [
            'POSTGRES_USER' => $user,
            'POSTGRES_PASSWORD' => $password,
            'port' => $port,
        ];
    }
}
