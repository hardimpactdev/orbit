<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\InstalledToolInventory;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolInventoryReceipt;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Models\Node;

final readonly class ComposerToolManager implements ToolManager
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

    public function managerVersion(Node $node): string
    {
        $version = $this->version($node, 'tool.composer-version', [], 'manager-version');
        if ($version === null) {
            throw new ToolManagerException('manager-version', 'The agent returned an invalid Composer manager version.');
        }

        return $version;
    }

    public function installedInventory(Node $node): InstalledToolInventory
    {
        $data = $this->command($node, 'tool.composer-inventory', [], 'installed-version');

        return new ToolInventoryReceipt()->parse(ToolManagerName::Composer, $data, $this->validatePackage(...));
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
                'tool.composer_scope_absent' => 'manager-absent',
                'tool.composer_scope_conflict' => 'manager-conflict',
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
}
