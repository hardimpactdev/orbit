<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\InstalledToolInventory;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\SupportsToolAdoption;
use App\Domain\Tools\ToolAdoptionFact;
use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolRemovalPlan;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Models\Node;

final readonly class ComposerToolManager implements SupportsToolAdoption, ToolManager
{
    private const int MAX_PACKAGE_LENGTH = 255;

    private const string PACKAGE_PATTERN = '/\A[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?\z/D';

    public function __construct(
        private SemverVersionNormalizer $versions,
        private AgentCommandRunner $agents,
        private ?ToolCommandBudget $budget = null,
    ) {}

    public function name(): ToolManagerName
    {
        return ToolManagerName::Composer;
    }

    public function supportsNode(Node $node): bool
    {
        return $node->platform === 'linux';
    }

    public function validatePackage(string $package): bool
    {
        return
            $package !== ''
            && strlen($package) <= self::MAX_PACKAGE_LENGTH
            && preg_match(self::PACKAGE_PATTERN, $package) === 1;
    }

    public function materialize(Node $node): void
    {
        $this->guardSupportedNode($node);

        $this->command($node, 'tools.materialize-composer', ['user' => $node->user], 'materialize');
    }

    public function managerVersion(Node $node): string
    {
        $version = $this->version($node, 'tools.composer-version', [], 'manager-version');
        if ($version === null) {
            throw new ToolManagerException('manager-version', 'The agent returned an invalid Composer manager version.');
        }

        return $version;
    }

    public function candidateVersion(Node $node, string $package, ToolOperation $operation): ?string
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);
        if ($operation === ToolOperation::Remove || $operation === ToolOperation::Adopt) {
            throw new ToolManagerException('candidate-version', 'This Composer operation does not select a candidate version.');
        }

        return $this->version($node, 'tools.composer-candidate', ['package' => $package, 'operation' => $operation->value], 'candidate-version');
    }

    public function installedInventory(Node $node): InstalledToolInventory
    {
        $data = $this->command($node, 'tools.composer-inventory', [], 'installed-version');
        if (! is_array($data['packages'] ?? null) || ! array_is_list($data['packages'])) {
            throw new ToolManagerException('installed-version', 'The agent returned an invalid Composer inventory.');
        }
        $versions = [];
        $unverifiable = [];
        foreach ($data['packages'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null) || ! array_key_exists('version', $entry) || ($entry['version'] !== null && ! is_string($entry['version']))) {
                throw new ToolManagerException('installed-version', 'The agent returned an invalid Composer package observation.');
            }
            if (! $this->validatePackage($entry['name'])) {
                continue;
            }
            if ($entry['version'] === null) {
                $unverifiable[] = $entry['name'];
            } else {
                $versions[$entry['name']][] = $entry['version'];
            }
        }

        return new InstalledToolInventory($versions, unverifiablePackages: $unverifiable);
    }

    public function installedVersion(Node $node, string $package): ?string
    {
        $this->guardPackage($package);

        return $this->installedInventory($node)->versionFor($package);
    }

    public function inspectForAdoption(Node $node, string $package): ToolAdoptionFact
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);
        $roots = $this->adoptionRoots($node);
        $installed = $this->installedVersion($node, $package);

        if ($installed === null) {
            return new ToolAdoptionFact(null, null);
        }

        if (! in_array($package, $roots, true)) {
            return new ToolAdoptionFact($installed, ToolInventoryPackage::BLOCK_DEPENDENCY);
        }

        return new ToolAdoptionFact($installed, null);
    }

    public function normalizeVersion(string $rawVersion): ?string
    {
        return $this->versions->normalize($rawVersion);
    }

    public function install(Node $node, string $package): void
    {
        $this->mutate($node, $package, 'install');
    }

    public function update(Node $node, string $package): void
    {
        $this->mutate($node, $package, 'update');
    }

    public function planRemoval(Node $node, string $package): ToolRemovalPlan
    {
        $this->guardPackage($package);
        $data = $this->command($node, 'tools.composer-removal-plan', ['package' => $package], 'removal-plan');
        if (($data['packages'] ?? null) !== [$package]) {
            throw new ToolManagerException('removal-plan', 'The agent returned an invalid Composer removal plan.');
        }

        return new ToolRemovalPlan($data['packages']);
    }

    public function remove(Node $node, string $package): void
    {
        $this->mutate($node, $package, 'remove');
    }

    private function guardPackage(string $package): void
    {
        if ($this->validatePackage($package)) {
            return;
        }

        throw new ToolManagerException(
            step: 'package',
            message: 'The Composer package coordinate is invalid.',
        );
    }

    private function guardSupportedNode(Node $node): void
    {
        if ($this->supportsNode($node)) {
            return;
        }

        throw new ToolManagerException(
            step: 'node',
            message: 'Composer tools require a Linux node.',
        );
    }

    /**
     * Reads the shared Composer scope without installing packages or creating directories.
     *
     * @return list<string>
     */
    private function adoptionRoots(Node $node): array
    {
        $data = $this->command($node, 'tools.inspect-composer-scope', [], 'manager-probe');
        if (! is_array($data['roots'] ?? null) || ! array_is_list($data['roots']) || array_filter($data['roots'], is_string(...)) !== $data['roots']) {
            throw new ToolManagerException('manager-probe', 'The agent returned an invalid Composer scope.');
        }

        return array_values(array_filter($data['roots'], $this->validatePackage(...)));
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function command(Node $node, string $command, array $input, string $step): array
    {
        $this->guardSupportedNode($node);
        try {
            return $this->agents->run($node, $command, $input, $this->budget?->seconds());
        } catch (AgentCommandRefused|AgentRunnerFailure $exception) {
            $step = match ($step === 'manager-probe' && $exception instanceof AgentCommandRefused ? $exception->errorCode : null) {
                'tools.composer_scope_absent' => 'manager-absent',
                'tools.composer_scope_conflict' => 'manager-conflict',
                default => $step,
            };
            throw new ToolManagerException($step, 'The Composer operation failed.', previous: $exception);
        }
    }

    /** @param array<string, mixed> $input */
    private function version(Node $node, string $command, array $input, string $step): ?string
    {
        $data = $this->command($node, $command, $input, $step);
        if (! array_key_exists('version', $data) || ($data['version'] !== null && ! is_string($data['version']))) {
            throw new ToolManagerException($step, 'The agent returned an invalid Composer version.');
        }

        return $data['version'];
    }

    private function mutate(Node $node, string $package, string $step): void
    {
        $this->guardPackage($package);
        $this->command($node, 'tools.composer-'.$step, ['package' => $package], $step);
    }
}
