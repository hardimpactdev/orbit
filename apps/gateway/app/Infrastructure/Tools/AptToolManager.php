<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Nodes\RoleName;
use App\Domain\Tools\DebianVersionNormalizer;
use App\Domain\Tools\InstalledToolInventory;
use App\Domain\Tools\ReservesRolePackages;
use App\Domain\Tools\SupportsToolAdoption;
use App\Domain\Tools\ToolAdoptionFact;
use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolRemovalPlan;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\PhpBuild;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\NodeBootstrapPackageCatalog;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;

final readonly class AptToolManager implements ReservesRolePackages, SupportsToolAdoption, ToolManager
{
    /** Any PHP version's packages belong to the PHP role, not only the versions the recipe declares today. */
    private const string ROLE_PHP_PACKAGE_PATTERN = '/\Aphp[0-9]+\.[0-9]+-[a-z0-9.+-]+\z/D';

    private const int MAX_PACKAGE_LENGTH = 128;

    private const int MAX_VERSION_LENGTH = 255;

    private const string PACKAGE_PATTERN = '/\A[a-z0-9][a-z0-9+.-]*\z/D';

    private const string PLANNED_PACKAGE_PATTERN = '/\ARemv\s+([a-z0-9][a-z0-9+.-]*(?::[a-z0-9][a-z0-9-]*)?)(?:\s|$)/D';

    /**
     * Wait for another apt process to release the dpkg locks. Apt applies this
     * only to those locks, and 300 seconds stays inside the 900 second tool command budget.
     */
    private const string DPKG_LOCK_TIMEOUT = 'DPkg::Lock::Timeout=300';

    private const array REMOVED_STATUSES = [
        'deinstall ok config-files',
        'deinstall ok not-installed',
        'purge ok config-files',
        'purge ok not-installed',
    ];

    public function __construct(
        private RemoteToolCommandRunner $commands,
        private DebianVersionNormalizer $versions,
        private ?NodeBootstrapPackageCatalog $packages = null,
        private ?MachineRecipe $recipe = null,
    ) {}

    /** The PHP role owns the recipe's PHP build, ADR 0216: every `php<version>-*` package and the shared PHP pins. */
    public function roleOwns(string $package): bool
    {
        if (preg_match(self::ROLE_PHP_PACKAGE_PATTERN, $package) === 1) {
            return true;
        }

        return array_any(
            ($this->recipe ?? new RecipeLoader()->load())->php,
            static fn (PhpBuild $build): bool => in_array($package, $build->packages, true),
        );
    }

    public function name(): ToolManagerName
    {
        return ToolManagerName::Apt;
    }

    public function supportsNode(Node $node): bool
    {
        return $node->platform === 'linux';
    }

    public function validatePackage(string $package): bool
    {
        $length = strlen($package);

        return
            $length >= 2
            && $length <= self::MAX_PACKAGE_LENGTH
            && preg_match(self::PACKAGE_PATTERN, $package) === 1
            && preg_match('/[a-z]/', $package) === 1;
    }

    public function materialize(Node $node): void
    {
        $this->managerVersion($node);
    }

    public function managerVersion(Node $node): string
    {
        $result = $this->commands->execute($node, ['apt-get', '--version']);

        $this->guardSuccessfulResult(
            result: $result,
            step: 'manager-version',
            message: 'The APT manager version probe failed.',
        );

        $lines = preg_split('/\R/', $result->stdout, limit: 2);
        $version = is_array($lines) ? $lines[0] ?? '' : '';

        if (! $this->isSafeVersion($version)) {
            throw new ToolManagerException(
                step: 'manager-version',
                message: 'The APT manager version probe returned malformed output.',
                result: $result,
            );
        }

        return $version;
    }

    public function candidateVersion(Node $node, string $package, ToolOperation $operation): ?string
    {
        $this->guardPackage($package);

        $result = $this->commands->execute($node, ['apt-cache', 'policy', '--', $package]);

        $this->guardSuccessfulResult(
            result: $result,
            step: 'candidate-version',
            message: 'The APT candidate version probe failed.',
        );

        $lines = preg_split('/\R/', $result->stdout);
        $candidateLines = array_values(array_filter(
            is_array($lines) ? $lines : [],
            static fn (string $line): bool => preg_match('/\A\s*Candidate:/', $line) === 1,
        ));

        if (count($candidateLines) !== 1) {
            throw new ToolManagerException(
                step: 'candidate-version',
                message: 'The APT candidate version probe returned ambiguous output.',
                result: $result,
            );
        }

        $matches = [];

        if (preg_match('/\A\s*Candidate:\s+(\S+)\s*\z/D', $candidateLines[0], $matches) !== 1) {
            throw new ToolManagerException(
                step: 'candidate-version',
                message: 'The APT candidate version probe returned malformed output.',
                result: $result,
            );
        }

        $version = $matches[1];

        if ($version === '(none)') {
            return null;
        }

        if (! $this->isSafeVersion($version)) {
            throw new ToolManagerException(
                step: 'candidate-version',
                message: 'The APT candidate version probe returned malformed output.',
                result: $result,
            );
        }

        return $version;
    }

    public function installedInventory(Node $node): InstalledToolInventory
    {
        $result = $this->commands->execute($node, ['dpkg-query', '--show', '--showformat=${Package}\\t${Status}\\t${Version}\\n'], maxOutputBytes: 1_048_576);
        $this->guardSuccessfulResult($result, 'installed-version', 'The APT installed inventory probe failed.');
        $versions = [];
        $unverifiable = [];
        foreach (explode("\n", rtrim($result->stdout, "\n")) as $line) {
            if ($line === '') {
                continue;
            }
            $fields = explode("\t", $line);
            if (count($fields) !== 3 || ! $this->validatePackage($fields[0])) {
                throw new ToolManagerException('installed-version', 'The APT installed inventory was malformed.', $result);
            }
            [$package, $status, $version] = $fields;
            if (in_array($status, [...self::REMOVED_STATUSES, 'unknown ok not-installed'], true)) {
                continue;
            }
            if ($status !== 'install ok installed' || ! $this->isSafeVersion($version)) {
                $unverifiable[] = $package;

                continue;
            }
            $versions[$package][] = $version;
        }

        return new InstalledToolInventory($versions, $result, $unverifiable);
    }

    public function installedVersion(Node $node, string $package): ?string
    {
        $this->guardPackage($package);

        return $this->installedInventory($node)->versionFor($package);
    }

    public function normalizeVersion(string $rawVersion): ?string
    {
        return $this->versions->normalize($rawVersion);
    }

    public function inspectForAdoption(Node $node, string $package): ToolAdoptionFact
    {
        $this->guardPackage($package);

        if (! $this->supportsNode($node)) {
            throw new ToolManagerException(
                step: 'node',
                message: 'APT tools require a Linux node.',
            );
        }

        try {
            $this->managerVersion($node);
        } catch (ToolManagerException $exception) {
            throw new ToolManagerException(
                step: 'manager-absent',
                message: 'The APT manager is not available.',
                result: $exception->result,
                previous: $exception,
            );
        }

        $version = $this->installedVersion($node, $package);

        if ($version === null) {
            return new ToolAdoptionFact(null, null);
        }

        if (in_array($package, $this->protectedPackages($node), true) || $this->roleOwns($package)) {
            return new ToolAdoptionFact($version, ToolInventoryPackage::BLOCK_PROTECTED);
        }

        return new ToolAdoptionFact($version, null);
    }

    /** @return list<string> */
    private function protectedPackages(Node $node): array
    {
        $catalog = $this->packages ?? new NodeBootstrapPackageCatalog;
        $names = $catalog->forNode($node);

        foreach (RoleName::cases() as $role) {
            $names = [...$names, ...$catalog->forRole($node, $role)];
        }

        $names[] = 'openssh-server';
        $names[] = 'wireguard-tools';
        $names = [...$names, ...($this->recipe ?? new RecipeLoader()->load())->gitPackages];

        return array_values(array_unique($names));
    }

    public function install(Node $node, string $package): void
    {
        $this->mutate(
            node: $node,
            package: $package,
            step: 'install',
            arguments: ['sudo', 'apt-get', '-o', self::DPKG_LOCK_TIMEOUT, 'install', '--yes', '--no-install-recommends', '--', $package],
        );
    }

    public function update(Node $node, string $package): void
    {
        $this->mutate(
            node: $node,
            package: $package,
            step: 'update',
            arguments: ['sudo', 'apt-get', '-o', self::DPKG_LOCK_TIMEOUT, 'install', '--yes', '--no-install-recommends', '--', $package],
        );
    }

    public function planRemoval(Node $node, string $package): ToolRemovalPlan
    {
        $this->guardPackage($package);

        $result = $this->commands->execute($node, ['apt-get', '--simulate', 'remove', '--', $package]);

        $this->guardSuccessfulResult(
            result: $result,
            step: 'removal-plan',
            message: 'The APT removal plan failed.',
        );

        $packages = [];
        $lines = preg_split('/\R/', $result->stdout);

        foreach (is_array($lines) ? $lines : [] as $line) {
            if (preg_match('/\A\s*Remv(?:\s|\z)/', $line) !== 1) {
                continue;
            }

            $matches = [];

            if (preg_match(self::PLANNED_PACKAGE_PATTERN, $line, $matches) !== 1) {
                throw new ToolManagerException(
                    step: 'removal-plan',
                    message: 'The APT removal plan returned a malformed package record.',
                    result: $result,
                );
            }

            $plannedPackage = $this->normalizeRequestedArchitecture($matches[1], $package);
            $packages[$plannedPackage] = true;
        }

        return new ToolRemovalPlan(array_keys($packages));
    }

    public function remove(Node $node, string $package): void
    {
        $this->mutate(
            node: $node,
            package: $package,
            step: 'remove',
            arguments: ['sudo', 'apt-get', '-o', self::DPKG_LOCK_TIMEOUT, 'remove', '--yes', '--', $package],
        );
    }

    private function guardPackage(string $package): void
    {
        if ($this->validatePackage($package)) {
            return;
        }

        throw new ToolManagerException(
            step: 'package',
            message: 'The APT package coordinate is invalid.',
        );
    }

    private function guardSuccessfulResult(CommandResult $result, string $step, string $message): void
    {
        if ($result->succeeded()) {
            return;
        }

        throw new ToolManagerException(
            step: $step,
            message: $message,
            result: $result,
        );
    }

    private function isSafeVersion(string $version): bool
    {
        return
            $version !== ''
            && strlen($version) <= self::MAX_VERSION_LENGTH
            && preg_match('/[\x00-\x1F\x7F]/', $version) !== 1;
    }

    /** @param non-empty-list<string> $arguments */
    private function mutate(Node $node, string $package, string $step, array $arguments): void
    {
        $this->guardPackage($package);

        $result = $this->commands->execute($node, $arguments);

        $this->guardSuccessfulResult(
            result: $result,
            step: $step,
            message: "The APT {$step} operation failed.",
        );
    }

    private function normalizeRequestedArchitecture(string $plannedPackage, string $requestedPackage): string
    {
        $prefix = "{$requestedPackage}:";

        if (! str_starts_with($plannedPackage, $prefix)) {
            return $plannedPackage;
        }

        return $requestedPackage;
    }
}
