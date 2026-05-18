<?php

declare(strict_types=1);

namespace App\Docs\Librarian\Rules;

use App\Docs\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class InputModeContractRule implements GroupedRule
{
    public function __construct(
        private OrbitCommandDocs $docs,
    ) {}

    public function group(): string
    {
        return 'contracts';
    }

    public function check(): array
    {
        $findings = [];

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            foreach ($this->docs->commandDirectories($familyDirectory) as $commandDirectory) {
                array_push($findings, ...$this->checkCommandDirectory($commandDirectory));
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkCommandDirectory(string $commandDirectory): array
    {
        $commandName = $this->docs->commandName($commandDirectory);
        $canonicalFile = "{$commandDirectory}/technical/1_{$commandName}.md";

        if (! is_file($canonicalFile)) {
            return [];
        }

        $findings = [];
        $canonicalContents = file_get_contents($canonicalFile) ?: '';
        $interactiveFile = "{$commandDirectory}/technical/5.1_{$commandName}_input-mode_interactive.md";
        $nonInteractiveFile = "{$commandDirectory}/technical/5.2_{$commandName}_input-mode_non-interactive.md";

        array_push(
            $findings,
            ...$this->checkCanonicalInvocationModelReference($canonicalFile, $canonicalContents),
            ...$this->checkCanonicalInvocationBoilerplate($canonicalFile, $canonicalContents),
        );

        if (! is_file($interactiveFile) && ! is_file($nonInteractiveFile)) {
            array_push($findings, ...$this->checkNoSplitInputModeStatement($canonicalFile, $canonicalContents));

            return $findings;
        }

        if (! is_file($interactiveFile)) {
            $findings[] = $this->finding($canonicalFile, 'Commands with split input-mode contracts must include 5.1 interactive input mode.');
        }

        if (! is_file($nonInteractiveFile)) {
            $findings[] = $this->finding($canonicalFile, 'Commands with split input-mode contracts must include 5.2 non-interactive input mode.');
        }

        array_push(
            $findings,
            ...$this->checkCanonicalLinks($canonicalFile, $canonicalContents, basename($interactiveFile), basename($nonInteractiveFile)),
        );

        if (is_file($interactiveFile)) {
            array_push($findings, ...$this->checkInteractiveFile($interactiveFile));
        }

        if (is_file($nonInteractiveFile)) {
            array_push($findings, ...$this->checkNonInteractiveFile($nonInteractiveFile));
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkCanonicalInvocationModelReference(string $file, string $contents): array
    {
        $section = $this->section($contents, 'Input Contract');

        if ($section === '' || str_contains($section, 'README.md#invocation-model')) {
            return [];
        }

        return [
            $this->finding($file, 'Canonical Input Contract sections must link the shared Invocation Model.'),
        ];
    }

    /**
     * @return list<Finding>
     */
    private function checkCanonicalInvocationBoilerplate(string $file, string $contents): array
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
            if (! str_contains($intro, $genericPhrase)) {
                continue;
            }

            return [
                $this->finding($file, 'Canonical Input Contract sections should link the shared Invocation Model without repeating generic input-mode or `--json` behavior.'),
            ];
        }

        return [];
    }

    /**
     * @return list<Finding>
     */
    private function checkNoSplitInputModeStatement(string $file, string $contents): array
    {
        $section = $this->section($contents, 'Input Mode Contracts');

        if ($section === '') {
            return [];
        }

        if (! str_contains(strtolower($section), 'no input-mode-specific contracts are required')) {
            return [];
        }

        return [
            $this->finding($file, 'Omit the Input Mode Contracts section entirely when no split input-mode files exist; the shared Invocation Model applies.'),
        ];
    }

    /**
     * @return list<Finding>
     */
    private function checkCanonicalLinks(string $file, string $contents, string $interactiveFileName, string $nonInteractiveFileName): array
    {
        $findings = [];

        if (! str_contains($contents, $interactiveFileName)) {
            $findings[] = $this->finding($file, "Canonical contract must link {$interactiveFileName} when split input-mode files are used.");
        }

        if (! str_contains($contents, $nonInteractiveFileName)) {
            $findings[] = $this->finding($file, "Canonical contract must link {$nonInteractiveFileName} when split input-mode files are used.");
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkInteractiveFile(string $file): array
    {
        $contents = file_get_contents($file) ?: '';
        $lowerContents = strtolower($contents);
        $findings = [];

        if (! str_contains($contents, '**Input mode:** Interactive.')) {
            $findings[] = $this->finding($file, 'Interactive input-mode files must declare "**Input mode:** Interactive.".');
        }

        if (! str_contains($lowerContents, 'tty') || ! $this->statesJsonAbsent($lowerContents)) {
            $findings[] = $this->finding($file, 'Interactive input-mode files must state that they apply only with a TTY and without `--json`.');
        }

        if (! $this->documentsPromptBehavior($contents)) {
            $findings[] = $this->finding($file, 'Interactive input-mode files must document prompt behavior or explicitly state why no prompts render.');
        }

        if (! str_contains($contents, "\n## Test Mapping")) {
            $findings[] = $this->finding($file, 'Interactive input-mode files must include "## Test Mapping".');
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkNonInteractiveFile(string $file): array
    {
        $contents = file_get_contents($file) ?: '';
        $lowerContents = strtolower($contents);
        $findings = [];

        if (! str_contains($contents, '**Input mode:** Non-interactive.')) {
            $findings[] = $this->finding($file, 'Non-interactive input-mode files must declare "**Input mode:** Non-interactive.".');
        }

        if (! $this->statesNonInteractiveSelection($lowerContents)) {
            $findings[] = $this->finding($file, 'Non-interactive input-mode files must state that they apply without a TTY or when `--json` is present.');
        }

        if (! $this->statesJsonForcesNonInteractive($lowerContents)) {
            $findings[] = $this->finding($file, '`--json` must be documented as forcing non-interactive input mode.');
        }

        if (! $this->statesNoPrompts($lowerContents)) {
            $findings[] = $this->finding($file, 'Non-interactive input-mode files must state that prompts are never rendered.');
        }

        if (! str_contains($contents, "\n## Test Mapping")) {
            $findings[] = $this->finding($file, 'Non-interactive input-mode files must include "## Test Mapping".');
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

    private function finding(string $path, string $message): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: null,
            severity: FindingSeverity::Error,
            rule: 'command_docs.input_mode_contract',
            message: $message,
        );
    }
}
