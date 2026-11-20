<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\HomebrewPackageName;
use App\Domain\Tools\InstalledToolInventory;
use App\Domain\Tools\ObservedPackages;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;

final readonly class HomebrewCaskToolManager implements ToolManager
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

    /** A Linux Node offers casks once the Node agent reports its Homebrew prefix; Orbit does not install Homebrew for a cask. */
    public function supportsNode(Node $node): bool
    {
        return $node->platform === 'macos'
            || ($node->platform === 'linux' && ObservedPackages::find($node, ToolManagerName::Brew) !== null);
    }

    public function validatePackage(string $package): bool
    {
        return HomebrewPackageName::valid($package);
    }

    public function installedInventory(Node $node): InstalledToolInventory
    {
        $this->guardSupportedNode($node);

        return $this->homebrew->inventory($this->agentCommand($node, 'tool.homebrew-cask-inventory', [], 'installed-version'));
    }

    /** A cask version carries its build after a comma, such as `1.5.13,5253791805734912`; SemVer reads the part before it. */
    public function normalizeVersion(string $rawVersion): ?string
    {
        return $this->versions->normalize(explode(',', $rawVersion, 2)[0]);
    }

    private function guardSupportedNode(Node $node): void
    {
        if ($this->supportsNode($node)) {
            return;
        }

        throw new ToolManagerException(
            step: 'node',
            message: 'Homebrew casks require a macOS node, or a Linux node with Homebrew.',
        );
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function agentCommand(Node $node, string $command, array $input, string $step): array
    {
        return $this->homebrew->run($node, $command, $input, $step, $this->budget?->seconds());
    }
}
