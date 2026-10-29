<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\InstalledToolInventory;
use App\Domain\Tools\ToolInventoryReceipt;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Models\Node;

/** Maps the native Homebrew contract to Gateway intent and errors. */
final readonly class HomebrewCommand
{
    public function __construct(private AgentCommandRunner $agents, private ?ToolCommandBudget $budget = null) {}

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
                'tool.homebrew_scope_absent' => 'manager-absent',
                'tool.homebrew_scope_conflict' => 'manager-conflict',
                'tool.homebrew_scope_unreadable' => 'manager-probe',
                'tool.homebrew_candidate_invalid', 'tool.homebrew_bottle_unavailable' => 'candidate-version',
                'tool.homebrew_cask_source' => 'cask-source',
                'tool.homebrew_cask_artifact' => 'cask-artifact',
                'tool.homebrew_cask_authorization' => 'cask-authorization',
                'tool.homebrew_cask_checksum' => 'cask-checksum',
                'tool.homebrew_cask_version' => 'cask-version',
                default => $step,
            };
            $message = match ($code) {
                'tool.homebrew_candidate_invalid' => 'The Homebrew formula did not provide a compatible verified bottle.',
                'tool.homebrew_bottle_unavailable' => 'The Node has no compatible Homebrew bottle.',
                'tool.homebrew_output_invalid' => 'The Homebrew command returned malformed output.',
                'tool.homebrew_version_unsupported' => 'The Homebrew manager returned an unsupported version.',
                'tool.homebrew_cask_source' => 'The Homebrew cask is not an official Homebrew cask.',
                'tool.homebrew_cask_artifact' => 'The Homebrew cask artifact is not supported.',
                'tool.homebrew_cask_authorization' => 'The Homebrew cask requires interactive or administrator authorization.',
                'tool.homebrew_cask_checksum' => 'The Homebrew cask checksum policy was not satisfied.',
                'tool.homebrew_cask_version' => 'The Homebrew cask version cannot be read.',
                default => 'The Homebrew command failed.',
            };
            throw new ToolManagerException($step, $message, previous: $error);
        }
    }

    /** @param array<string, mixed> $data */
    public function inventory(array $data): InstalledToolInventory
    {
        return new ToolInventoryReceipt()->parse(ToolManagerName::Brew, $data);
    }
}
