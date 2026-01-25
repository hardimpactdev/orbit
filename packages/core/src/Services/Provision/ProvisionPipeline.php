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

        $result = app(InstallComposerDependencies::class)->handle($context, $logger);
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
        $result = app(InstallComposerDependencies::class)->handle($context, $logger);
        if ($result->isFailed()) {
            return $result;
        }

        // Step 2: Detect Node package manager
        $detectResult = app(DetectNodePackageManager::class)->handle($context, $logger);
        if ($detectResult->isFailed()) {
            return $detectResult;
        }
        $packageManager = $detectResult->data['packageManager'] ?? null;

        // Step 3: Install Node dependencies
        if ($packageManager) {
            $logger->broadcast('installing_npm');
            $result = app(InstallNodeDependencies::class)->handle($context, $logger, $packageManager);
            if ($result->isFailed()) {
                return $result;
            }
        }

        // Step 4: Build assets
        if ($packageManager) {
            $logger->broadcast('building');
            $result = app(BuildAssets::class)->handle($context, $logger, $packageManager);
            if ($result->isFailed()) {
                return $result;
            }
        }

        // Step 5: Configure environment
        $postgresConfig = $this->getPostgresConfig();
        $result = app(ConfigureEnvironment::class)->handle($context, $logger, $postgresConfig);
        if ($result->isFailed()) {
            return $result;
        }

        // Step 6: Create database
        $result = app(CreateDatabase::class)->handle($context, $logger);
        if ($result->isFailed()) {
            return $result;
        }

        // Step 7: Generate app key
        $result = app(GenerateAppKey::class)->handle($context, $logger);
        if ($result->isFailed()) {
            return $result;
        }

        // Step 8: Run migrations
        $result = app(RunMigrations::class)->handle($context, $logger);
        if ($result->isFailed()) {
            return $result;
        }

        // Step 9: Configure trusted proxies
        $result = app(ConfigureTrustedProxies::class)->handle($context, $logger);
        if ($result->isFailed()) {
            return $result;
        }

        // Step 10: Set PHP version
        $result = app(SetPhpVersion::class)->handle($context, $logger);
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

        return app(CloneRepository::class)->handle($context, $logger);
    }

    /**
     * Create a GitHub repository from template.
     */
    public function createFromTemplate(ProvisionContext $context, ProvisionLoggerContract $logger, string $targetRepo): StepResult
    {
        $logger->broadcast('creating_repo');

        return app(CreateGitHubRepository::class, ['github' => $this->github])
            ->handle($context, $logger, $targetRepo);
    }

    /**
     * Fork a repository.
     */
    public function forkRepository(ProvisionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        $logger->broadcast('forking');

        return app(ForkRepository::class, ['github' => $this->github])
            ->handle($context, $logger);
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
        $home = $_SERVER['HOME'] ?? '/home/orbit';
        $servicesPath = "{$home}/.config/orbit/services.yaml";

        if (! file_exists($servicesPath)) {
            return [
                'POSTGRES_USER' => 'orbit',
                'POSTGRES_PASSWORD' => 'secret',
                'port' => 5432,
            ];
        }

        $content = file_get_contents($servicesPath);

        // Simple YAML parsing for postgres section
        if (preg_match('/postgres:.*?POSTGRES_USER:\s*(\S+)/s', $content, $userMatch)) {
            $user = $userMatch[1];
        } else {
            $user = 'orbit';
        }

        if (preg_match('/postgres:.*?POSTGRES_PASSWORD:\s*(\S+)/s', $content, $passMatch)) {
            $password = $passMatch[1];
        } else {
            $password = 'secret';
        }

        if (preg_match('/postgres:.*?port:\s*(\d+)/s', $content, $portMatch)) {
            $port = (int) $portMatch[1];
        } else {
            $port = 5432;
        }

        return [
            'POSTGRES_USER' => $user,
            'POSTGRES_PASSWORD' => $password,
            'port' => $port,
        ];
    }
}
