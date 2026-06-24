<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class FamilyCommandPrefixRule implements GroupedRule
{
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

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            $familySlug = $this->docs->familyName($familyDirectory);

            if ($familySlug === 'operation') {
                continue;
            }

            foreach ($this->docs->commandDirectories($familyDirectory) as $commandDirectory) {
                $commandSlug = $this->docs->commandName($commandDirectory);

                if ($this->startsWithFamilyPrefix($commandSlug, $familySlug)) {
                    continue;
                }

                $findings[] = $this->finding($this->docs->relativePath($commandDirectory), $commandSlug, $familySlug);
            }

            foreach ($this->docs->markdownFiles($familyDirectory, recursive: false) as $file) {
                $filename = basename($file, '.md');

                if (preg_match('/^[1-9]\d*_/', $filename) !== 1) {
                    continue;
                }

                $commandSlug = preg_replace('/^[1-9]\d*_/', '', $filename) ?? $filename;

                if ($this->startsWithFamilyPrefix($commandSlug, $familySlug)) {
                    continue;
                }

                $findings[] = $this->finding($this->docs->relativePath($file), $commandSlug, $familySlug);
            }
        }

        return $findings;
    }

    private function startsWithFamilyPrefix(string $commandSlug, string $familySlug): bool
    {
        return $commandSlug === $familySlug || str_starts_with($commandSlug, "{$familySlug}-");
    }

    private function finding(string $path, string $commandSlug, string $familySlug): Finding
    {
        return new Finding(
            path: $path,
            line: null,
            severity: FindingSeverity::Error,
            rule: 'command_docs.family_command_prefix',
            message: "Command `{$commandSlug}` does not belong in the `{$familySlug}` family; non-operation family commands must start with `{$familySlug}-`.",
        );
    }
}
