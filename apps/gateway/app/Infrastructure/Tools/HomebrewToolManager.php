<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\HomebrewPackageName;
use App\Domain\Tools\InstalledToolInventory;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;

final readonly class HomebrewToolManager implements ToolManager
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
        return ToolManagerName::Brew;
    }

    public function supportsNode(Node $node): bool
    {
        return $node->platform === 'linux' || $node->platform === 'macos';
    }

    public function validatePackage(string $package): bool
    {
        return HomebrewPackageName::valid($package);
    }

    public function managerVersion(Node $node): string
    {
        $this->guardSupportedNode($node);
        $data = $this->agentCommand($node, 'tool.homebrew-version', [], 'manager-version');
        if (! is_string($data['version'] ?? null)) {
            throw new ToolManagerException('manager-version', 'The agent returned an invalid Homebrew version.');
        }

        return $data['version'];
    }

    public function installedInventory(Node $node): InstalledToolInventory
    {
        $this->guardSupportedNode($node);

        return $this->homebrew->inventory($this->agentCommand($node, 'tool.homebrew-formula-inventory', [], 'installed-version'));
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

    private function guardPackage(string $package): void
    {
        if ($this->validatePackage($package)) {
            return;
        }

        throw new ToolManagerException(
            step: 'package',
            message: 'The Homebrew formula name is invalid.',
        );
    }

    private function guardSupportedNode(Node $node): void
    {
        if ($this->supportsNode($node)) {
            return;
        }

        throw new ToolManagerException(
            step: 'node',
            message: 'Homebrew tools require a Linux or macOS node.',
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
