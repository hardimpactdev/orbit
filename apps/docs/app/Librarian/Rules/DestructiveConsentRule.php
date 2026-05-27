<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class DestructiveConsentRule implements GroupedRule
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

        $canonicalContents = file_get_contents($canonicalFile) ?: '';

        if (! $this->hasDestructiveEffect($canonicalContents)) {
            return [];
        }

        return [
            ...$this->checkCanonicalContract($canonicalFile, $canonicalContents),
            ...$this->checkInteractiveInputMode("{$commandDirectory}/technical/5.1_{$commandName}_input-mode_interactive.md"),
            ...$this->checkNonInteractiveInputMode("{$commandDirectory}/technical/5.2_{$commandName}_input-mode_non-interactive.md"),
        ];
    }

    /**
     * @return list<Finding>
     */
    private function checkCanonicalContract(string $file, string $contents): array
    {
        $findings = [];
        $signature = $this->section($contents, 'Signature');
        $inputContract = $this->section($contents, 'Input Contract');
        $lowerContents = strtolower($contents);

        if (! str_contains($signature, '--force')) {
            $findings[] = $this->finding($file, 'Destructive canonical contracts must include [--force] in the command signature.');
        }

        if (preg_match('/^\|\s*`force`\s*\|\s*`--force`/m', $inputContract) !== 1) {
            $findings[] = $this->finding($file, 'Destructive canonical contracts must include a `force` input row sourced from `--force`.');
        }

        if (! str_contains($lowerContents, 'destructive consent')) {
            $findings[] = $this->finding($file, 'Destructive canonical contracts must describe the destructive consent model.');
        }

        if (! $this->statesJsonIsNotConsent($lowerContents)) {
            $findings[] = $this->finding($file, '`--json` must be documented as non-interactive mode selection, not destructive consent.');
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkInteractiveInputMode(string $file): array
    {
        if (! is_file($file)) {
            return [
                $this->finding($file, 'Destructive commands must include an interactive input-mode contract for confirmation prompting.'),
            ];
        }

        $findings = [];
        $contents = file_get_contents($file) ?: '';
        $lowerContents = strtolower($contents);

        if (! str_contains($lowerContents, 'confirm')) {
            $findings[] = $this->finding($file, 'Interactive input mode for destructive commands must define a confirmation prompt.');
        }

        if (! str_contains($contents, '--force')) {
            $findings[] = $this->finding($file, 'Interactive input mode for destructive commands must document `--force` confirmation bypass.');
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkNonInteractiveInputMode(string $file): array
    {
        if (! is_file($file)) {
            return [
                $this->finding($file, 'Destructive commands must include a non-interactive input-mode contract for missing-consent failure.'),
            ];
        }

        $findings = [];
        $contents = file_get_contents($file) ?: '';
        $lowerContents = strtolower($contents);

        if (! str_contains($contents, '--force')) {
            $findings[] = $this->finding($file, 'Non-interactive input mode for destructive commands must require `--force`.');
        }

        if (! str_contains($lowerContents, 'before side effects')) {
            $findings[] = $this->finding($file, 'Non-interactive missing-consent failures must be documented as failing before side effects.');
        }

        if (! $this->statesJsonIsNotConsent($lowerContents)) {
            $findings[] = $this->finding($file, '`--json` must be documented as forcing non-interactive input mode without destructive consent.');
        }

        return $findings;
    }

    private function hasDestructiveEffect(string $contents): bool
    {
        return preg_match('/^\*\*Effects:\*\*\s*(?<effects>.+)$/m', $contents, $matches) === 1
            && str_contains((string) $matches['effects'], 'destructive');
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

    private function finding(string $path, string $message): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: null,
            severity: FindingSeverity::Error,
            rule: 'command_docs.destructive_consent',
            message: $message,
        );
    }
}
