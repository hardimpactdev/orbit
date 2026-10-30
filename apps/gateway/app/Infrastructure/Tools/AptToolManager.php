<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\DebianVersionNormalizer;
use App\Domain\Tools\InstalledToolInventory;
use App\Domain\Tools\ReservesRolePackages;
use App\Domain\Tools\ToolInventoryReceipt;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\PhpBuild;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\Node;

final readonly class AptToolManager implements ReservesRolePackages, ToolManager
{
    /** Any PHP version's packages belong to the PHP role, not only the versions the recipe declares today. */
    private const string ROLE_PHP_PACKAGE_PATTERN = '/\Aphp[0-9]+\.[0-9]+-[a-z0-9.+-]+\z/D';

    private const int MAX_PACKAGE_LENGTH = 128;

    private const string PACKAGE_PATTERN = '/\A[a-z0-9][a-z0-9+.-]*\z/D';

    public function __construct(
        private AgentCommandRunner $agents,
        private DebianVersionNormalizer $versions,
        private ?MachineRecipe $recipe = null,
        private ?ToolCommandBudget $budget = null,
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

    public function installedInventory(Node $node): InstalledToolInventory
    {
        $data = $this->command($node, 'tool.apt-inventory', [], 'installed-version');

        return new ToolInventoryReceipt()->parse(ToolManagerName::Apt, $data);
    }

    public function normalizeVersion(string $rawVersion): ?string
    {
        return $this->versions->normalize($rawVersion);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function command(Node $node, string $command, array $input, string $step): array
    {
        if (! $this->supportsNode($node)) {
            throw new ToolManagerException('node', 'APT tools require a Linux node.');
        }
        try {
            return $this->agents->run($node, $command, $input, $this->budget?->seconds());
        } catch (AgentCommandRefused|AgentRunnerFailure $exception) {
            throw new ToolManagerException($step, 'The APT operation failed.', previous: $exception);
        }
    }
}
