<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\HomebrewPackageName;
use App\Domain\Tools\InstalledToolInventory;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\SupportsToolAdoption;
use App\Domain\Tools\ToolAdoptionFact;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolRemovalPlan;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;

final readonly class HomebrewCaskToolManager implements SupportsToolAdoption, ToolManager
{
    private HomebrewCommand $homebrew;

    public function __construct(
        private SemverVersionNormalizer $versions,
        AgentCommandRunner $agents,
        ?HomebrewCommand $homebrew = null,
        private ?ToolCommandBudget $budget = null,
    ) {
        $this->homebrew = $homebrew ?? new HomebrewCommand($agents);
    }

    public function name(): ToolManagerName
    {
        return ToolManagerName::BrewCask;
    }

    public function supportsNode(Node $node): bool
    {
        return $node->platform === 'macos';
    }

    public function validatePackage(string $package): bool
    {
        return HomebrewPackageName::valid($package);
    }

    public function materialize(Node $node): void
    {
        $this->guardSupportedNode($node);
        $this->homebrew->verifyScope($node);
    }

    public function managerVersion(Node $node): string
    {
        $this->guardSupportedNode($node);
        $data = $this->agentCommand($node, 'tools.homebrew-version', [], 'manager-version');
        if (! is_string($data['version'] ?? null)) {
            throw new ToolManagerException('manager-version', 'The agent returned an invalid Homebrew version.');
        }

        return $data['version'];
    }

    public function candidateVersion(Node $node, string $package, ToolOperation $operation): ?string
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);
        if ($operation === ToolOperation::Remove) {
            throw new ToolManagerException('candidate-version', 'Homebrew casks do not provide a removal candidate version.');
        }
        $data = $this->agentCommand($node, 'tools.homebrew-cask-candidate', $this->packageInput($node, $package), 'candidate-version');
        if (! array_key_exists('version', $data) || ($data['version'] !== null && ! is_string($data['version']))) {
            throw new ToolManagerException('candidate-version', 'The agent returned an invalid Homebrew candidate.');
        }

        return $data['version'];
    }

    public function installedInventory(Node $node): InstalledToolInventory
    {
        $this->guardSupportedNode($node);

        return $this->homebrew->inventory($this->agentCommand($node, 'tools.homebrew-cask-inventory', [], 'installed-version'));
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

    public function install(Node $node, string $package): void
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);
        $this->agentCommand($node, 'tools.homebrew-cask-install', $this->packageInput($node, $package, needsBottle: true), 'install');
    }

    public function update(Node $node, string $package): void
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);
        $this->agentCommand($node, 'tools.homebrew-cask-update', $this->packageInput($node, $package, needsBottle: true), 'update');
    }

    public function planRemoval(Node $node, string $package): ToolRemovalPlan
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);

        return new ToolRemovalPlan([$package]);
    }

    public function remove(Node $node, string $package): void
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);
        $this->agentCommand($node, 'tools.homebrew-cask-remove', $this->packageInput($node, $package, needsBottle: true), 'remove');
    }

    private function guardMacCpu(Node $node): void
    {
        if ($node->architecture === 'arm64' || $node->architecture === 'x86_64') {
            return;
        }

        throw new ToolManagerException(
            step: 'candidate-version',
            message: 'The node architecture has no supported Homebrew cask checksum.',
        );
    }

    private function guardPackage(string $package): void
    {
        if ($this->validatePackage($package)) {
            return;
        }

        throw new ToolManagerException(
            step: 'package',
            message: 'The Homebrew cask name is invalid.',
        );
    }

    private function guardSupportedNode(Node $node): void
    {
        if ($this->supportsNode($node)) {
            return;
        }

        throw new ToolManagerException(
            step: 'node',
            message: 'Homebrew casks require a macOS node.',
        );
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function agentCommand(Node $node, string $command, array $input, string $step): array
    {
        return $this->homebrew->run($node, $command, $input, $step, $this->budget?->seconds());
    }

    public function inspectForAdoption(Node $node, string $package): ToolAdoptionFact
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);

        return $this->homebrew->adoption($this->agentCommand($node, 'tools.homebrew-cask-adoption', $this->packageInput($node, $package), 'installed-version'));
    }

    /** @return array<string, mixed> */
    private function packageInput(Node $node, string $package, bool $needsBottle = true): array
    {
        if ($node->platform === 'macos' && $needsBottle) {
            $this->guardMacCpu($node);

            return ['package' => $package, 'architecture' => $node->architecture];
        }

        return ['package' => $package];
    }
}
