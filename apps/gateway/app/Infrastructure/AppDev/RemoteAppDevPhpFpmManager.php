<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\AppDevPhpFpmManager;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Models\Node;
use App\Rules\SupportedPhpVersion;
use Illuminate\Support\Collection;

/** Stored pool intent and package selection. Native commands own all Node inspection, publication, and recovery. */
final readonly class RemoteAppDevPhpFpmManager implements AppDevPhpFpmManager
{
    public const string PoolDirectoryMissing = 'app-dev.php_pool_directory_missing';

    public function __construct(
        private DevelopmentSiteRepository $sites,
        private DevelopmentPhpFpmConfigRenderer $renderer,
        private AgentCommandRunner $agents,
        private ManagedUserAccountResolver $accounts,
        private RemotePhpPackageManager $packages,
    ) {}

    public function converge(Node $node): void
    {
        $sites = $this->desiredSites($node);
        $configurations = $this->configurations($node, $sites);
        if ($configurations !== []) {
            $inspection = $this->run($node, 'php.inspect-shared-pools', $configurations);
            $missingDirectories = array_column($inspection['missing_pools'], 'directory');
            $presentSites = $sites->reject(static fn (DevelopmentSite $site): bool => in_array($site->phpWorkingDirectory(), $missingDirectories, true));
            $role = $presentSites->contains(static fn (DevelopmentSite $site): bool => $site->environment === 'production')
                ? RoleName::AppProd : RoleName::AppDev;
            $this->packages->installForInstance($node->loadMissing('roles'), collect($inspection['versions']), $role);
        }
        $result = $this->run($node, 'php.converge-shared-pools', $configurations);
        if ($result['missing_pools'] !== []) {
            $pools = collect($result['missing_pools'])->map(static fn (array $pool): string => "{$pool['pool']} ({$pool['directory']})")->implode(', ');
            throw new RuntimeConvergenceException(
                step: 'php-fpm-directories',
                errorCode: self::PoolDirectoryMissing,
                message: "PHP-FPM pools were not published on node [{$node->name}] because their working directories are missing: {$pools}.",
            );
        }
    }

    public function installPackages(Node $node, string $version): void
    {
        $this->validateVersion($version);
        $this->packages->installForInstance($node->loadMissing('roles'), collect([$version]), RoleName::AppDev);
    }

    /** @return list<array{pool: string, version: string, directory: string, installed: bool}> */
    public function poolsWithMissingDirectories(Node $node, ?float $commandTimeout = null): array
    {
        return $this->run($node, 'php.inspect-shared-pools', $this->configurations($node, $this->desiredSites($node)), $commandTimeout)['missing_pools'];
    }

    /** @return Collection<int, DevelopmentSite> */
    private function desiredSites(Node $node): Collection
    {
        return $this->sites->forNode($node)
            ->filter(static fn (DevelopmentSite $site): bool => $site->phpVersion !== null && ! $site->isProxy() && ! $site->usesDedicatedPhpRuntime())
            ->values();
    }

    /** @param Collection<int, DevelopmentSite> $sites
     * @return array<string, string>
     */
    private function configurations(Node $node, Collection $sites): array
    {
        if ($sites->isEmpty()) {
            return [];
        }

        $account = $this->accounts->resolve($node);
        $configurations = [];
        foreach ($sites->groupBy('phpVersion') as $version => $versionSites) {
            $this->validateVersion($version);
            $configurations[$version] = $this->renderer->render($versionSites->values(), $account);
        }

        return $configurations;
    }

    private function validateVersion(string $version): void
    {
        if (! SupportedPhpVersion::isSupported($version)) {
            throw new RuntimeConvergenceException('php-version', 'app-dev.php_version_unsupported', "PHP version [{$version}] is not supported.");
        }
    }

    /** @param array<string, string> $configurations
     * @return array{versions: list<string>, missing_pools: list<array{pool: string, version: string, directory: string, installed: bool}>}
     */
    private function run(Node $node, string $command, array $configurations, ?float $timeout = null): array
    {
        try {
            $report = $this->agents->run($node, $command, ['configurations' => (object) $configurations], $timeout);
            $malformed = static fn (): AgentRunnerFailure => new AgentRunnerFailure($command, AgentRunnerFailure::MalformedResult, 'Shared PHP pool inspection returned invalid data.');
            $versions = [];
            if ($command === 'php.inspect-shared-pools') {
                if (! is_array($report['versions'] ?? null) || ! array_is_list($report['versions'])) {
                    throw $malformed();
                }
                foreach ($report['versions'] as $version) {
                    if (! is_string($version)) {
                        throw $malformed();
                    }
                    $versions[] = $version;
                }
            }
            if (! is_array($report['missing_pools'] ?? null) || ! array_is_list($report['missing_pools'])) {
                throw $malformed();
            }
            $missing = [];
            foreach ($report['missing_pools'] as $pool) {
                if (! is_array($pool) || ! is_string($pool['pool'] ?? null) || ! is_string($pool['version'] ?? null)
                    || ! is_string($pool['directory'] ?? null) || ! is_bool($pool['installed'] ?? null)) {
                    throw $malformed();
                }
                $missing[] = ['pool' => $pool['pool'], 'version' => $pool['version'], 'directory' => $pool['directory'], 'installed' => $pool['installed']];
            }

            return ['versions' => $versions, 'missing_pools' => $missing];
        } catch (AgentCommandRefused|AgentRunnerFailure $error) {
            $read = $command === 'php.inspect-shared-pools';
            throw new RuntimeConvergenceException(
                step: $read ? 'php-fpm-discover' : 'php-fpm-config',
                errorCode: $read ? 'app-dev.php_fpm_discovery_failed' : 'app-dev.php_fpm_config_failed',
                message: $error->getMessage(),
                previous: $error,
            );
        }
    }
}
