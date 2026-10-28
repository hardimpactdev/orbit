<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\InstalledToolInventory;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\SupportsToolAdoption;
use App\Domain\Tools\ToolAdoptionFact;
use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolInventoryReceipt;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolRemovalPlan;
use App\Domain\Tools\VpEnvironment;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\MachineSoftware\VitePlusRuntimeLock;
use App\Models\Node;

final readonly class VpToolManager implements SupportsToolAdoption, ToolManager
{
    private const int MAX_PACKAGE_LENGTH = 214;

    private const string PACKAGE_PATTERN = '/\A(?:[a-z0-9][a-z0-9._~-]*|@[a-z0-9][a-z0-9._~-]*\/[a-z0-9][a-z0-9._~-]*)\z/D';

    public function __construct(
        private SemverVersionNormalizer $versions,
        private AgentCommandRunner $agents,
        private ?ToolCommandBudget $budget = null,
    ) {}

    public function name(): ToolManagerName
    {
        return ToolManagerName::Vp;
    }

    public function supportsNode(Node $node): bool
    {
        return $node->platform === 'linux' || $node->platform === 'macos';
    }

    /**
     * The enrolled account's existing Vite+ binary.
     * The probe does not install Vite+, publish launchers, or change the scope.
     *
     * @throws ToolManagerException
     */
    public function existingBinary(Node $node): string
    {
        $this->guardNode($node);

        try {
            $data = $this->agents->run($node, 'tool.inspect-vp-scope', deadlineSeconds: $this->budget?->seconds());
        } catch (AgentCommandRefused $exception) {
            $step = match ($exception->errorCode) {
                'tool.vp_scope_absent' => 'manager-absent',
                'tool.vp_scope_conflict' => 'manager-conflict',
                default => 'manager-probe',
            };
            throw new ToolManagerException($step, 'The Vite+ global scope is unavailable for the enrolled account.');
        }
        $binary = $data['binary'] ?? null;
        if (! is_string($binary)) {
            throw new ToolManagerException('manager-probe', 'The Vite+ global scope probe returned malformed output.');
        }

        return $binary;
    }

    public function validatePackage(string $package): bool
    {
        $length = strlen($package);

        return $length >= 1 && $length <= self::MAX_PACKAGE_LENGTH && preg_match(self::PACKAGE_PATTERN, $package) === 1;
    }

    /** @return array<string, mixed> */
    public function materializeInput(Node $node): array
    {
        $architecture = MachineRecipe::architecture($node->architecture)
            ?? throw new ToolManagerException('materialize', 'The Node architecture is not supported by the Vite+ recipe.');
        $recipe = new RecipeLoader()->load();
        $data = json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! is_array($data['tools'] ?? null)) {
            throw new ToolManagerException('materialize', 'The JavaScript recipe document is invalid.');
        }

        return [
            'user' => $node->user,
            'snapshot' => $recipe->snapshot,
            'architecture' => $architecture,
            'artifact_mirror' => $recipe->artifactMirror,
            'tools' => array_intersect_key($data['tools'], array_flip(['node', 'vp-cli', 'vp', 'pnpm'])),
            'vp_lock' => VitePlusRuntimeLock::read($recipe->tools['vp']),
        ];
    }

    public function managerVersion(Node $node): string
    {
        $this->guardNode($node);

        return $this->version($node, 'tool.vp-version', [], 'manager-version');
    }

    /** vp itself is recipe-owned; deploy prerequisites only inspect it. */
    public function ensureVersion(Node $node, string $required): void
    {
        if (! $this->versionSatisfies($this->managerVersion($node), $required)) {
            throw new ToolManagerException('manager-version', 'Update the machine recipe: vp is older than the locked Project version.');
        }
    }

    public function versionSatisfies(string $observed, string $required): bool
    {
        $normalized = $this->versions->normalize(preg_replace('/\\Avp\\s+/', '', trim($observed)) ?? '');

        return $normalized !== null && version_compare($normalized, $required, '>=');
    }

    /** Read-only inspection; no registry requests, commands, locks, or store writes. */
    public function environmentInstalled(Node $node, VpEnvironment $environment): bool
    {
        return $this->environmentsInstalled($node, [$environment])[0];
    }

    /** @param list<VpEnvironment> $environments
     * @return list<bool>
     */
    public function environmentsInstalled(Node $node, array $environments): array
    {
        $store = dirname($this->existingBinary($node), 2);
        $data = $this->storeCommand($node, 'tool.inspect-vp-store', ['environments' => array_map(fn (VpEnvironment $environment): array => $environment->input(), $environments)]);
        $installed = $data['installed'] ?? null;
        if (! is_array($installed) || ! array_is_list($installed) || count($installed) !== count($environments) || array_filter($installed, is_bool(...)) !== $installed) {
            throw new ToolManagerException('environment-inspect', 'Malformed vp store inventory.');
        }

        return array_map(fn (bool $present, int $index): bool => $present && $environments[$index]->store === $store, $installed, array_keys($installed));
    }

    public function installEnvironment(Node $node, VpEnvironment $environment, string $npmrc = ''): void
    {
        $this->storeCommand($node, 'tool.install-vp-environment', ['environment' => $environment->input(), 'npmrc' => $npmrc]);
    }

    /** @param list<array<string, mixed>> $references */
    public function pruneEnvironments(Node $node, string $store, array $references): void
    {
        $this->storeCommand($node, 'tool.prune-vp-store', ['store' => $store, 'references' => array_map(fn (array $reference): array => VpEnvironment::fromArray($reference)->input(), $references)]);
    }

    /** @param array<string, mixed> $input
     * @return array<array-key, mixed>
     */
    private function storeCommand(Node $node, string $name, array $input): array
    {
        try {
            return $this->agents->run($node, $name, $input, $this->budget?->seconds());
        } catch (AgentCommandRefused $error) {
            if (! in_array($error->errorCode, ['tool.store_invalid', 'tool.store_failed', 'tool.store_lock_timeout'], true)) {
                throw $error;
            }
            throw new ToolManagerException('environment', 'The vp store prerequisite failed before build; check store ownership, checksums, registry access, and lock contention.');
        }
    }

    public function candidateVersion(Node $node, string $package, ToolOperation $operation): string
    {
        $this->guardNode($node);
        $this->guardPackage($package);

        return $this->version($node, 'tool.vp-candidate', ['package' => $package], 'candidate-version');
    }

    /** @return array{packages: list<array{name: string, version: ?string}>, scan_complete: bool} */
    public function inventory(Node $node): array
    {
        $this->guardNode($node);

        $data = $this->globalCommand($node, 'tool.vp-inventory', [], 'installed-version');
        if (! is_array($data['packages'] ?? null) || ! array_is_list($data['packages']) || ! is_bool($data['scan_complete'] ?? null)) {
            throw new ToolManagerException('installed-version', 'The agent returned an invalid Vite+ inventory.');
        }
        $packages = [];
        foreach ($data['packages'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null) || ! array_key_exists('version', $entry) || ($entry['version'] !== null && ! is_string($entry['version']))) {
                throw new ToolManagerException('installed-version', 'The agent returned an invalid Vite+ package observation.');
            }
            $packages[] = ['name' => $entry['name'], 'version' => $entry['version']];
        }

        return ['packages' => $packages, 'scan_complete' => $data['scan_complete']];
    }

    public function installedInventory(Node $node): InstalledToolInventory
    {
        $inventory = $this->inventory($node);

        return new ToolInventoryReceipt()->parse(ToolManagerName::Vp, ['scan_complete' => $inventory['scan_complete'], 'packages' => $inventory['packages']], $this->validatePackage(...));
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
        $this->guardNode($node);
        $this->guardPackage($package);
        $version = $this->installedVersion($node, $package);

        if ($version === null) {
            return new ToolAdoptionFact(null, null);
        }

        if ($package === 'pnpm') {
            return new ToolAdoptionFact($version, ToolInventoryPackage::BLOCK_PROTECTED);
        }

        return new ToolAdoptionFact($version, null);
    }

    public function planRemoval(Node $node, string $package): ToolRemovalPlan
    {
        $this->guardNode($node);
        $this->guardPackage($package);
        $data = $this->globalCommand($node, 'tool.vp-removal-plan', ['package' => $package], 'removal-plan');

        if (! is_array($data['packages'] ?? null) || ! array_is_list($data['packages']) || array_filter($data['packages'], is_string(...)) !== $data['packages']) {
            throw new ToolManagerException('removal-plan', 'The agent returned an invalid Vite+ removal plan.');
        }

        return new ToolRemovalPlan($data['packages']);
    }

    private function guardNode(Node $node): void
    {
        if ($this->supportsNode($node)) {
            return;
        }

        throw new ToolManagerException(
            step: 'node',
            message: 'The VP tool manager does not support this node.',
        );
    }

    private function guardPackage(string $package): void
    {
        if ($this->validatePackage($package)) {
            return;
        }

        throw new ToolManagerException(
            step: 'package',
            message: 'The VP package coordinate is invalid.',
        );
    }

    /** @param array<string, mixed> $input */
    private function version(Node $node, string $command, array $input, string $step): string
    {
        $data = $this->globalCommand($node, $command, $input, $step);
        if (! is_string($data['version'] ?? null)) {
            throw new ToolManagerException($step, 'The agent returned an invalid Vite+ version.');
        }

        return $data['version'];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function globalCommand(Node $node, string $command, array $input, string $step): array
    {
        try {
            return $this->agents->run($node, $command, $input, $this->budget?->seconds());
        } catch (AgentCommandRefused|AgentRunnerFailure $exception) {
            $step = match ($exception instanceof AgentCommandRefused ? $exception->errorCode : null) {
                'tool.vp_scope_absent' => 'manager-absent',
                'tool.vp_scope_conflict' => 'manager-conflict',
                'tool.vp_scope_unreadable' => 'manager-probe',
                default => $step,
            };
            throw new ToolManagerException($step, 'The Vite+ operation failed.', previous: $exception);
        }
    }
}
