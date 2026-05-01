<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class InputModeContractRule implements CommandDocsLintRule
{
    public function id(): string
    {
        return 'command_docs.input_mode_contract';
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
                $interactiveFile = "{$commandDirectory}/technical/5.1_{$commandName}_input-mode_interactive.md";
                $nonInteractiveFile = "{$commandDirectory}/technical/5.2_{$commandName}_input-mode_non-interactive.md";

                $findings = [
                    ...$findings,
                    ...$this->checkCanonicalInvocationModelReference($context, $canonicalFile, $canonicalContents),
                    ...$this->checkCanonicalInvocationBoilerplate($context, $canonicalFile, $canonicalContents),
                ];

                if (! is_file($interactiveFile) && ! is_file($nonInteractiveFile)) {
                    $findings = [
                        ...$findings,
                        ...$this->checkNoSplitInputModeStatement($context, $canonicalFile, $canonicalContents),
                    ];

                    continue;
                }

                if (! is_file($interactiveFile)) {
                    $findings[] = $this->finding($context, $canonicalFile, 'Commands with split input-mode contracts must include 5.1 interactive input mode.');
                }

                if (! is_file($nonInteractiveFile)) {
                    $findings[] = $this->finding($context, $canonicalFile, 'Commands with split input-mode contracts must include 5.2 non-interactive input mode.');
                }

                $findings = [
                    ...$findings,
                    ...$this->checkCanonicalLinks($context, $canonicalFile, $canonicalContents, basename($interactiveFile), basename($nonInteractiveFile)),
                ];

                if (is_file($interactiveFile)) {
                    $findings = [
                        ...$findings,
                        ...$this->checkInteractiveFile($context, $interactiveFile),
                    ];
                }

                if (is_file($nonInteractiveFile)) {
                    $findings = [
                        ...$findings,
                        ...$this->checkNonInteractiveFile($context, $nonInteractiveFile),
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function checkCanonicalInvocationModelReference(CommandDocsLintContext $context, string $file, string $contents): array
    {
        $section = $this->section($contents, 'Input Contract');

        if ($section === '') {
            return [];
        }

        if (str_contains($section, 'README.md#invocation-model')) {
            return [];
        }

        return [
            $this->finding($context, $file, 'Canonical Input Contract sections must link the shared Invocation Model.'),
        ];
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function checkCanonicalInvocationBoilerplate(CommandDocsLintContext $context, string $file, string $contents): array
    {
        $intro = strtolower($this->normalizeWhitespace($this->inputContractIntro($contents)));

        if ($intro === '') {
            return [];
        }

        $genericPhrases = [
            'interactive input mode may prompt',
            'interactive input mode asks for destructive confirmation',
            'non-interactive input mode fails before side effects',
            '`--json` always uses non-interactive input mode',
        ];

        foreach ($genericPhrases as $genericPhrase) {
            if (str_contains($intro, $genericPhrase)) {
                return [
                    $this->finding($context, $file, 'Canonical Input Contract sections should link the shared Invocation Model without repeating generic input-mode or `--json` behavior.'),
                ];
            }
        }

        return [];
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function checkNoSplitInputModeStatement(CommandDocsLintContext $context, string $file, string $contents): array
    {
        $section = $this->section($contents, 'Input Mode Contracts');

        if ($section === '') {
            return [];
        }

        $lowerSection = strtolower($section);

        if (str_contains($lowerSection, 'no input-mode-specific contracts are required') && str_contains($lowerSection, 'does not prompt')) {
            return [];
        }

        return [
            $this->finding($context, $file, 'Canonical Input Mode Contracts sections without split files must state that no input-mode-specific contracts are required and explain why no prompt contract is needed.'),
        ];
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function checkCanonicalLinks(CommandDocsLintContext $context, string $file, string $contents, string $interactiveFileName, string $nonInteractiveFileName): array
    {
        $findings = [];

        if (! str_contains($contents, $interactiveFileName)) {
            $findings[] = $this->finding($context, $file, "Canonical contract must link {$interactiveFileName} when split input-mode files are used.");
        }

        if (! str_contains($contents, $nonInteractiveFileName)) {
            $findings[] = $this->finding($context, $file, "Canonical contract must link {$nonInteractiveFileName} when split input-mode files are used.");
        }

        return $findings;
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function checkInteractiveFile(CommandDocsLintContext $context, string $file): array
    {
        $contents = $context->read($file);
        $lowerContents = strtolower($contents);
        $findings = [];

        if (! str_contains($contents, '**Input mode:** Interactive.')) {
            $findings[] = $this->finding($context, $file, 'Interactive input-mode files must declare "**Input mode:** Interactive.".');
        }

        if (! str_contains($lowerContents, 'tty') || ! $this->statesJsonAbsent($lowerContents)) {
            $findings[] = $this->finding($context, $file, 'Interactive input-mode files must state that they apply only with a TTY and without `--json`.');
        }

        if (! $this->documentsPromptBehavior($contents)) {
            $findings[] = $this->finding($context, $file, 'Interactive input-mode files must document prompt behavior or explicitly state why no prompts render.');
        }

        if (! str_contains($contents, "\n## Test Mapping")) {
            $findings[] = $this->finding($context, $file, 'Interactive input-mode files must include "## Test Mapping".');
        }

        return $findings;
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function checkNonInteractiveFile(CommandDocsLintContext $context, string $file): array
    {
        $contents = $context->read($file);
        $lowerContents = strtolower($contents);
        $findings = [];

        if (! str_contains($contents, '**Input mode:** Non-interactive.')) {
            $findings[] = $this->finding($context, $file, 'Non-interactive input-mode files must declare "**Input mode:** Non-interactive.".');
        }

        if (! $this->statesNonInteractiveSelection($lowerContents)) {
            $findings[] = $this->finding($context, $file, 'Non-interactive input-mode files must state that they apply without a TTY or when `--json` is present.');
        }

        if (! $this->statesJsonForcesNonInteractive($lowerContents)) {
            $findings[] = $this->finding($context, $file, '`--json` must be documented as forcing non-interactive input mode.');
        }

        if (! $this->statesNoPrompts($lowerContents)) {
            $findings[] = $this->finding($context, $file, 'Non-interactive input-mode files must state that prompts are never rendered.');
        }

        if (! str_contains($contents, "\n## Test Mapping")) {
            $findings[] = $this->finding($context, $file, 'Non-interactive input-mode files must include "## Test Mapping".');
        }

        return $findings;
    }

    private function statesJsonAbsent(string $lowerContents): bool
    {
        $normalizedContents = $this->normalizeWhitespace($lowerContents);

        return str_contains($normalizedContents, '--json')
            && (
                str_contains($normalizedContents, 'not present')
                || str_contains($normalizedContents, 'not supplied')
                || str_contains($normalizedContents, 'without `--json`')
                || str_contains($normalizedContents, 'without --json')
            );
    }

    private function documentsPromptBehavior(string $contents): bool
    {
        $lowerContents = strtolower($contents);

        return preg_match('/^## Prompt/sm', $contents) === 1
            || str_contains($lowerContents, 'does not render prompts')
            || str_contains($lowerContents, 'do not prompt')
            || str_contains($lowerContents, 'no prompt');
    }

    private function statesNonInteractiveSelection(string $lowerContents): bool
    {
        $normalizedContents = $this->normalizeWhitespace($lowerContents);

        return str_contains($normalizedContents, '--json')
            && (
                str_contains($normalizedContents, 'without a tty')
                || str_contains($normalizedContents, 'not attached to a tty')
                || str_contains($normalizedContents, 'non-tty')
            );
    }

    private function statesJsonForcesNonInteractive(string $lowerContents): bool
    {
        $normalizedContents = $this->normalizeWhitespace($lowerContents);

        return str_contains($normalizedContents, '--json')
            && str_contains($normalizedContents, 'force')
            && str_contains($normalizedContents, 'non-interactive');
    }

    private function statesNoPrompts(string $lowerContents): bool
    {
        $normalizedContents = $this->normalizeWhitespace($lowerContents);

        return str_contains($normalizedContents, 'never render prompts')
            || str_contains($normalizedContents, 'without prompts')
            || str_contains($normalizedContents, 'no prompts')
            || str_contains($normalizedContents, 'no fallback prompts')
            || str_contains($normalizedContents, 'never block on user input')
            || str_contains($normalizedContents, 'never wait for user input');
    }

    private function normalizeWhitespace(string $contents): string
    {
        return preg_replace('/\s+/', ' ', $contents) ?? $contents;
    }

    private function section(string $contents, string $heading): string
    {
        if (preg_match('/^## '.preg_quote($heading, '/').'\s*$(?<section>.*?)(?:^## |\z)/ms', $contents, $matches) === 1) {
            return $matches['section'];
        }

        return '';
    }

    private function inputContractIntro(string $contents): string
    {
        $section = trim($this->section($contents, 'Input Contract'));

        if ($section === '') {
            return '';
        }

        $lines = preg_split('/\R/', $section) ?: [];
        $introLines = [];

        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '|')) {
                break;
            }

            $introLines[] = $line;
        }

        return trim(implode("\n", $introLines));
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
