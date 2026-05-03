<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class FamilyCommandPrefixRule implements CommandDocsLintRule
{
    public function id(): string
    {
        return 'command_docs.family_command_prefix';
    }

    public function group(): string
    {
        return 'structure';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            $familySlug = $this->slugFromBasename(basename($familyDirectory));

            if ($familySlug === 'operation') {
                continue;
            }

            $expectedPrefix = $this->commandPrefix($familySlug);

            foreach ($context->commandDirectories($familyDirectory) as $commandDirectory) {
                $commandSlug = $this->slugFromBasename(basename($commandDirectory));

                if ($this->startsWithFamilyPrefix($commandSlug, $expectedPrefix)) {
                    continue;
                }

                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($commandDirectory),
                    ruleId: $this->id(),
                    message: "Command `{$commandSlug}` does not belong in the `{$familySlug}` family; non-operation family commands must start with `{$expectedPrefix}-`.",
                );
            }

            foreach ($context->markdownFiles($familyDirectory, recursive: false) as $file) {
                $filename = basename($file, '.md');

                if (preg_match('/^[1-9]\d*_/', $filename) !== 1) {
                    continue;
                }

                $commandSlug = $this->slugFromBasename($filename);

                if ($this->startsWithFamilyPrefix($commandSlug, $expectedPrefix)) {
                    continue;
                }

                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($file),
                    ruleId: $this->id(),
                    message: "Command `{$commandSlug}` does not belong in the `{$familySlug}` family; non-operation family commands must start with `{$expectedPrefix}-`.",
                );
            }
        }

        return $findings;
    }

    private function slugFromBasename(string $basename): string
    {
        return (string) preg_replace('/^[1-9]\d*_/', '', $basename);
    }

    private function commandPrefix(string $familySlug): string
    {
        return $familySlug;
    }

    private function startsWithFamilyPrefix(string $commandSlug, string $expectedPrefix): bool
    {
        return $commandSlug === $expectedPrefix
            || str_starts_with($commandSlug, "{$expectedPrefix}-");
    }
}
