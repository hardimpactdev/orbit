<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class CompoundCommandPrefixRule implements CommandDocsLintRule
{
    /**
     * @var list<string>
     */
    private const CompoundPrefixes = [
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

    public function id(): string
    {
        return 'command_docs.compound_command_prefix';
    }

    public function group(): string
    {
        return 'structure';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->markdownFiles($context->scanRoot) as $file) {
            array_push($findings, ...$this->fileFindings($context, $file, $context->read($file)));
        }

        return $findings;
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function fileFindings(CommandDocsLintContext $context, string $file, string $contents): array
    {
        $findings = [];

        foreach (explode("\n", $contents) as $index => $line) {
            foreach ($this->splitCompoundCommands($line) as $command => $suggestedCommand) {
                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($file),
                    ruleId: $this->id(),
                    message: "Command `{$command}` splits a compound command prefix. Use `{$suggestedCommand}` so the longest command prefix stays before the colon.",
                    line: $index + 1,
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
        foreach (self::CompoundPrefixes as $compoundPrefix) {
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
