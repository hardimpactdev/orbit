<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class PublicJsonOptionContractRule implements CommandDocsLintRule
{
    public function id(): string
    {
        return 'command_docs.public_json_option_contract';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            foreach ($context->commandDirectories($familyDirectory) as $commandDirectory) {
                $commandName = preg_replace('/^[1-9]\d*_/', '', basename($commandDirectory));
                $canonicalFile = "{$commandDirectory}/technical/1_{$commandName}.md";
                $publicFile = "{$commandDirectory}/{$commandName}.md";

                if (! is_file($canonicalFile) || ! is_file($publicFile)) {
                    continue;
                }

                $canonicalContents = $context->read($canonicalFile);

                if (! $this->signatureHasJsonOption($canonicalContents)) {
                    continue;
                }

                if (! str_contains($context->read($publicFile), '--json')) {
                    $findings[] = $this->finding($context, $publicFile, 'Public command page must mention `--json` when the canonical signature accepts it.');
                }
            }
        }

        return $findings;
    }

    private function signatureHasJsonOption(string $contents): bool
    {
        return str_contains($this->signatureSection($contents), '--json');
    }

    private function signatureSection(string $contents): string
    {
        if (preg_match('/\n## Signature\s*(?<section>.*?)(?:\n## |\z)/s', $contents, $matches) === 1) {
            return $matches['section'];
        }

        return '';
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
