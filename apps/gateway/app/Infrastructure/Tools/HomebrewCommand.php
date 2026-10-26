<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\InstalledToolInventory;
use App\Domain\Tools\ToolAdoptionFact;
use App\Domain\Tools\ToolManagerException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Models\Node;

/** Maps the native Homebrew contract to Gateway intent and errors. */
final readonly class HomebrewCommand
{
    public function __construct(private AgentCommandRunner $agents, private ?ToolCommandBudget $budget = null) {}

    public function verifyScope(Node $node): void
    {
        $scope = $this->run($node, 'tools.inspect-homebrew-scope', [], 'manager-probe');
        if (! is_string($scope['prefix'] ?? null) || ! is_string($scope['home'] ?? null)) {
            throw new ToolManagerException('manager-probe', 'The agent returned an invalid Homebrew scope.');
        }
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function run(Node $node, string $command, array $input, string $step, ?float $timeout = null): array
    {
        try {
            return $this->agents->run($node, $command, $input, $timeout ?? $this->budget?->seconds());
        } catch (AgentCommandRefused|AgentRunnerFailure $error) {
            $code = $error instanceof AgentCommandRefused ? $error->errorCode : null;
            $step = match ($code) {
                'tools.homebrew_scope_absent' => 'manager-absent',
                'tools.homebrew_scope_conflict' => 'manager-conflict',
                'tools.homebrew_scope_unreadable' => 'manager-probe',
                'tools.homebrew_candidate_invalid', 'tools.homebrew_bottle_unavailable' => 'candidate-version',
                'tools.homebrew_cask_source' => 'cask-source',
                'tools.homebrew_cask_artifact' => 'cask-artifact',
                'tools.homebrew_cask_authorization' => 'cask-authorization',
                'tools.homebrew_cask_checksum' => 'cask-checksum',
                'tools.homebrew_cask_version' => 'cask-version',
                default => $step,
            };
            $message = match ($code) {
                'tools.homebrew_candidate_invalid' => 'The Homebrew formula did not provide a compatible verified bottle.',
                'tools.homebrew_bottle_unavailable' => 'The Node has no compatible Homebrew bottle.',
                'tools.homebrew_output_invalid' => 'The Homebrew command returned malformed output.',
                'tools.homebrew_version_unsupported' => 'The Homebrew manager returned an unsupported version.',
                'tools.homebrew_cask_source' => 'The Homebrew cask is not an official Homebrew cask.',
                'tools.homebrew_cask_artifact' => 'The Homebrew cask artifact is not supported.',
                'tools.homebrew_cask_authorization' => 'The Homebrew cask requires interactive or administrator authorization.',
                'tools.homebrew_cask_checksum' => 'The Homebrew cask checksum policy was not satisfied.',
                'tools.homebrew_cask_version' => 'The Homebrew cask version cannot be read.',
                default => 'The Homebrew command failed.',
            };
            throw new ToolManagerException($step, $message, previous: $error);
        }
    }

    /** @param array<string, mixed> $data */
    public function inventory(array $data): InstalledToolInventory
    {
        if (! is_array($data['packages'] ?? null) || ! array_is_list($data['packages'])) {
            throw new ToolManagerException('installed-version', 'The agent returned an invalid Homebrew inventory.');
        }
        $versions = [];
        $unverifiable = [];
        foreach ($data['packages'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null) || ! is_array($entry['versions'] ?? null)
                || ! array_is_list($entry['versions']) || ! array_all($entry['versions'], static fn (mixed $version): bool => is_string($version))
                || ! is_bool($entry['unverifiable'] ?? null)) {
                throw new ToolManagerException('installed-version', 'The agent returned an invalid Homebrew package observation.');
            }
            $versions[$entry['name']] = [...($versions[$entry['name']] ?? []), ...$entry['versions']];
            if ($entry['unverifiable']) {
                $unverifiable[] = $entry['name'];
            }
        }

        return new InstalledToolInventory($versions, unverifiablePackages: $unverifiable);
    }

    /** @param array<string, mixed> $data */
    public function adoption(array $data): ToolAdoptionFact
    {
        if (! array_key_exists('installed_version', $data) || ($data['installed_version'] !== null && ! is_string($data['installed_version']))
            || ! array_key_exists('block', $data) || ($data['block'] !== null && ! is_string($data['block']))) {
            throw new ToolManagerException('installed-version', 'The agent returned an invalid Homebrew adoption observation.');
        }

        return new ToolAdoptionFact($data['installed_version'], $data['block']);
    }
}
