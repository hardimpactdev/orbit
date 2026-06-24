<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class CompoundCommandPrefixRule implements GroupedRule
{
    /**
     * @var list<string>
     */
    private const array COMPOUND_PREFIXES = [
        'workspace-teardown-step',
        'workspace-setup-step',
        'cf-cache-rule',
        'vpn-web-ui',
        'vpn-client',
        'cf-cache',
        'cf-zone',
        'cf-dns',
        'cf-ssl',
    ];

    public function __construct(
        private OrbitCommandDocs $docs,
    ) {}

    public function group(): string
    {
        return 'structure';
    }

    public function check(): array
    {
        $findings = [];

        foreach ($this->docs->markdownFiles($this->docs->commandsRoot()) as $file) {
            array_push($findings, ...$this->fileFindings($file, file_get_contents($file) ?: ''));
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function fileFindings(string $file, string $contents): array
    {
        $findings = [];

        foreach (explode("\n", $contents) as $index => $line) {
            foreach ($this->splitCompoundCommands($line) as $command => $suggestedCommand) {
                $findings[] = new Finding(
                    path: $this->docs->relativePath($file),
                    line: $index + 1,
                    severity: FindingSeverity::Error,
                    rule: 'command_docs.compound_command_prefix',
                    message: "Command `{$command}` splits a compound command prefix. Use `{$suggestedCommand}` so the longest command prefix stays before the colon.",
                );
            }
        }

        return $findings;
    }

    /**
     * @return array<string, string>
     */
    private function splitCompoundCommands(string $line): array
    {
        preg_match_all(
            '/(?<![a-z0-9_-])(?<command>(?<family>[a-z0-9]+):(?<rest>[a-z0-9]+(?:-(?:[a-z0-9]+|\*))*))(?![a-z0-9_-])/i',
            $line,
            $matches,
            PREG_SET_ORDER,
        );

        $commands = [];

        foreach ($matches as $match) {
            $suggestedCommand = $this->suggestedCompoundCommand(
                strtolower($match['family']),
                strtolower($match['rest']),
            );

            if ($suggestedCommand === null) {
                continue;
            }

            $commands[$match['command']] = $suggestedCommand;
        }

        return $commands;
    }

    private function suggestedCompoundCommand(string $family, string $rest): ?string
    {
        foreach (self::COMPOUND_PREFIXES as $compoundPrefix) {
            [$compoundFamily, $compoundSuffix] = explode('-', $compoundPrefix, 2);

            if ($family !== $compoundFamily) {
                continue;
            }

            if (! str_starts_with($rest, "{$compoundSuffix}-")) {
                continue;
            }

            $action = substr($rest, strlen($compoundSuffix) + 1);

            return "{$compoundPrefix}:{$action}";
        }

        return null;
    }
}
