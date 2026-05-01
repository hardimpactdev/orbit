<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class DestructiveConsentRule implements CommandDocsLintRule
{
    public function id(): string
    {
        return 'command_docs.destructive_consent';
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

                if (! is_file($canonicalFile)) {
                    continue;
                }

                $canonicalContents = $context->read($canonicalFile);

                if (! $this->hasDestructiveEffect($canonicalContents)) {
                    continue;
                }

                $findings = [
                    ...$findings,
                    ...$this->checkCanonicalContract($context, $canonicalFile, $canonicalContents),
                    ...$this->checkInteractiveInputMode($context, "{$commandDirectory}/technical/5.1_{$commandName}_input-mode_interactive.md"),
                    ...$this->checkNonInteractiveInputMode($context, "{$commandDirectory}/technical/5.2_{$commandName}_input-mode_non-interactive.md"),
                ];
            }
        }

        return $findings;
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function checkCanonicalContract(CommandDocsLintContext $context, string $file, string $contents): array
    {
        $findings = [];
        $signature = $this->section($contents, 'Signature');
        $inputContract = $this->section($contents, 'Input Contract');
        $lowerContents = strtolower($contents);

        if (! str_contains($signature, '--force')) {
            $findings[] = $this->finding($context, $file, 'Destructive canonical contracts must include [--force] in the command signature.');
        }

        if (preg_match('/^\|\s*`force`\s*\|\s*`--force`/m', $inputContract) !== 1) {
            $findings[] = $this->finding($context, $file, 'Destructive canonical contracts must include a `force` input row sourced from `--force`.');
        }

        if (! str_contains($lowerContents, 'destructive consent')) {
            $findings[] = $this->finding($context, $file, 'Destructive canonical contracts must describe the destructive consent model.');
        }

        if (! $this->statesJsonIsNotConsent($lowerContents)) {
            $findings[] = $this->finding($context, $file, '`--json` must be documented as non-interactive mode selection, not destructive consent.');
        }

        return $findings;
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function checkInteractiveInputMode(CommandDocsLintContext $context, string $file): array
    {
        if (! is_file($file)) {
            return [
                $this->finding($context, $file, 'Destructive commands must include an interactive input-mode contract for confirmation prompting.'),
            ];
        }

        $findings = [];
        $contents = $context->read($file);
        $lowerContents = strtolower($contents);

        if (! str_contains($lowerContents, 'confirm')) {
            $findings[] = $this->finding($context, $file, 'Interactive input mode for destructive commands must define a confirmation prompt.');
        }

        if (! str_contains($contents, '--force')) {
            $findings[] = $this->finding($context, $file, 'Interactive input mode for destructive commands must document `--force` confirmation bypass.');
        }

        return $findings;
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function checkNonInteractiveInputMode(CommandDocsLintContext $context, string $file): array
    {
        if (! is_file($file)) {
            return [
                $this->finding($context, $file, 'Destructive commands must include a non-interactive input-mode contract for missing-consent failure.'),
            ];
        }

        $findings = [];
        $contents = $context->read($file);
        $lowerContents = strtolower($contents);

        if (! str_contains($contents, '--force')) {
            $findings[] = $this->finding($context, $file, 'Non-interactive input mode for destructive commands must require `--force`.');
        }

        if (! str_contains($lowerContents, 'before side effects')) {
            $findings[] = $this->finding($context, $file, 'Non-interactive missing-consent failures must be documented as failing before side effects.');
        }

        if (! $this->statesJsonIsNotConsent($lowerContents)) {
            $findings[] = $this->finding($context, $file, '`--json` must be documented as forcing non-interactive input mode without destructive consent.');
        }

        return $findings;
    }

    private function hasDestructiveEffect(string $contents): bool
    {
        return preg_match('/^\*\*Effects:\*\*\s*(?<effects>.+)$/m', $contents, $matches) === 1
            && str_contains($matches['effects'], 'destructive');
    }

    private function statesJsonIsNotConsent(string $lowerContents): bool
    {
        return str_contains($lowerContents, '--json')
            && str_contains($lowerContents, 'non-interactive')
            && str_contains($lowerContents, 'consent')
            && (str_contains($lowerContents, 'never') || str_contains($lowerContents, 'not'));
    }

    private function section(string $contents, string $heading): string
    {
        if (preg_match('/^## '.preg_quote($heading, '/').'\s*$(?<section>.*?)(?:^## |\z)/ms', $contents, $matches) === 1) {
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
