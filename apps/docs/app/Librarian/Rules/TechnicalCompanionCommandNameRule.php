<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class TechnicalCompanionCommandNameRule implements GroupedRule
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
        $commandSlug = $this->docs->commandName($commandDirectory);
        $canonicalFile = "{$commandDirectory}/technical/1_{$commandSlug}.md";

        if (! $this->docs->isFile($canonicalFile)) {
            return [];
        }

        $canonicalCommand = $this->canonicalCommand($this->docs->contents($canonicalFile));

        if ($canonicalCommand === null) {
            return [];
        }

        $findings = [];

        foreach ($this->docs->markdownFiles("{$commandDirectory}/technical", recursive: false) as $file) {
            if ($file === $canonicalFile) {
                continue;
            }

            $companionCommand = $this->headingCommand($this->docs->contents($file));

            if ($companionCommand === null || $companionCommand === $canonicalCommand) {
                continue;
            }

            $findings[] = new Finding(
                path: $this->docs->relativePath($file),
                line: 1,
                severity: FindingSeverity::Error,
                rule: 'command_docs.technical_companion_command_name',
                message: "Technical companion H1 names `{$companionCommand}`, but the canonical signature names `{$canonicalCommand}`.",
            );
        }

        return $findings;
    }

    private function canonicalCommand(string $contents): ?string
    {
        $section = [];

        if (preg_match('/^## Signature\s*$(?<section>.*?)(?=^## |\z)/ms', $contents, $section) !== 1) {
            return null;
        }

        $matches = [];

        if (
            preg_match('/(?:^|\s)orbit\s+(?<command>[a-z][a-z0-9-]*(?::[a-z0-9-]+)*)/', $section['section'], $matches)
            !== 1
        ) {
            return null;
        }

        return $matches['command'];
    }

    private function headingCommand(string $contents): ?string
    {
        $matches = [];

        if (
            preg_match(
                '/\A# [^\n]*`(?:(?:orbit)\s+)?(?<command>[a-z][a-z0-9-]*(?::[a-z0-9-]+)*)\b[^`]*`/',
                $contents,
                $matches,
            ) !== 1
        ) {
            return null;
        }

        return $matches['command'];
    }
}
