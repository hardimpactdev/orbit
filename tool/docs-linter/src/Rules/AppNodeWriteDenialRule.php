<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class AppNodeWriteDenialRule implements CommandDocsLintRule
{
    public function id(): string
    {
        return 'command_docs.app_node_write_denial';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            if (basename($familyDirectory) !== '5_app') {
                continue;
            }

            foreach ($context->commandDirectories($familyDirectory) as $commandDirectory) {
                $commandName = preg_replace('/^[1-9]\d*_/', '', basename($commandDirectory));
                $canonicalFile = "{$commandDirectory}/technical/1_{$commandName}.md";

                if (! is_file($canonicalFile)) {
                    continue;
                }

                $canonicalContents = $context->read($canonicalFile);

                if (! $this->hasWriteEffect($canonicalContents)) {
                    continue;
                }

                $contractContents = $this->combinedContractContents($context, $commandDirectory);

                if (! str_contains($contractContents, 'caller_role_not_allowed')) {
                    $findings[] = $this->finding($context, $canonicalFile, 'App write commands must document app-node denial with error.code=caller_role_not_allowed.');
                }

                if (! $this->documentsAppNodeDenial($contractContents)) {
                    $findings[] = $this->finding($context, $canonicalFile, 'App write commands must explicitly state that app-node callers are denied.');
                }

                if (! $this->documentsPreSideEffectDenial($contractContents)) {
                    $findings[] = $this->finding($context, $canonicalFile, 'App-node denial must be documented as happening before prompts or side effects.');
                }
            }
        }

        return $findings;
    }

    private function hasWriteEffect(string $contents): bool
    {
        if (preg_match('/^\*\*Effects:\*\*\s*(?<effects>.+)$/m', $contents, $matches) !== 1) {
            return false;
        }

        $effects = strtolower($matches['effects']);

        return str_contains($effects, 'write')
            || str_contains($effects, 'destructive')
            || str_contains($effects, 'stream');
    }

    private function combinedContractContents(CommandDocsLintContext $context, string $commandDirectory): string
    {
        $contents = [];

        foreach ($context->technicalMarkdownFiles("{$commandDirectory}/technical") as $file) {
            $contents[] = $context->read($file);
        }

        return strtolower(implode("\n", $contents));
    }

    private function documentsAppNodeDenial(string $contents): bool
    {
        return str_contains($contents, 'app-node callers are denied')
            || str_contains($contents, 'app callers are denied')
            || str_contains($contents, '| `app` | denied')
            || str_contains($contents, 'deny `app` nodes')
            || str_contains($contents, 'caller role is `app`');
    }

    private function documentsPreSideEffectDenial(string $contents): bool
    {
        return (str_contains($contents, 'before prompts') && str_contains($contents, 'side effects'))
            || str_contains($contents, 'before prompts or side effects')
            || str_contains($contents, 'before prompts, forwarding')
            || str_contains($contents, 'before side effects');
    }

    private function finding(CommandDocsLintContext $context, string $path, string $message): CommandDocsLintFinding
    {
        return new CommandDocsLintFinding(
            path: $context->relativePath($path),
            ruleId: $this->id(),
            message: $message,
        );
    }
}
