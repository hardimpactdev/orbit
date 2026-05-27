<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class RoleCompanionCoverageRule implements GroupedRule
{
    /**
     * @var array<int, string>
     */
    private const array ROLE_SUFFIXES = [
        2 => '_on-client',
        3 => '_on-gateway-node',
        4 => '_on-app-role',
    ];

    /**
     * @var list<string>
     */
    private const array BEHAVIOR_HEADINGS = [
        '## Allowed Paths',
        '## App-Role Rules',
        '## Behavior',
        '## Client Enrollment',
        '## Error Contract',
        '## First-Gateway Bootstrap',
        '## Forwarding Contract',
        '## Gateway Authority Rules',
        '## Gateway-Connected Operation',
        '## Gateway Convergence And Adoption',
        '## Gateway Path Matrix',
        '## Validity',
    ];

    public function __construct(
        private OrbitCommandDocs $docs,
    ) {}

    public function group(): string
    {
        return 'references';
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

        $companionSlots = $this->companionSlotsToCheck($canonicalContents, $commandDirectory, $commandName);

        if ($companionSlots === []) {
            return [];
        }

        $findings = [];

        foreach ($companionSlots as $slot => $suffix) {
            $companionFile = "{$commandDirectory}/technical/{$slot}_{$commandName}{$suffix}.md";

            if (! is_file($companionFile)) {
                $findings[] = $this->finding(
                    $canonicalFile,
                    "Canonical contract declares role-specific companion behavior but {$slot}_{$commandName}{$suffix}.md is missing.",
                );

                continue;
            }

            if (! str_contains($canonicalContents, "{$slot}_{$commandName}{$suffix}.md")) {
                $findings[] = $this->finding(
                    $canonicalFile,
                    "Canonical contract must link {$slot}_{$commandName}{$suffix}.md when role companion files are used.",
                );
            }

            array_push($findings, ...$this->checkCompanionFile($companionFile, $suffix));
        }

        return $findings;
    }

    /**
     * @return array<int, string>
     */
    private function companionSlotsToCheck(string $canonicalContents, string $commandDirectory, string $commandName): array
    {
        if (str_contains($canonicalContents, 'Role-specific behavior is defined in these companion contracts')) {
            return self::ROLE_SUFFIXES;
        }

        $slots = [];

        foreach (self::ROLE_SUFFIXES as $slot => $suffix) {
            if (is_file("{$commandDirectory}/technical/{$slot}_{$commandName}{$suffix}.md")) {
                $slots[$slot] = $suffix;
            }

            if (str_contains($canonicalContents, "{$slot}_{$commandName}{$suffix}.md")) {
                $slots[$slot] = $suffix;
            }
        }

        return $slots;
    }

    /**
     * @return list<Finding>
     */
    private function checkCompanionFile(string $file, string $suffix): array
    {
        $findings = [];
        $contents = file_get_contents($file) ?: '';

        if (! str_contains($contents, '[Back to')) {
            $findings[] = $this->finding($file, 'Role companion files must link back to the canonical technical contract.');
        }

        if (! $this->hasBehaviorSection($contents)) {
            $findings[] = $this->finding($file, 'Role companion files must define role-specific behavior, allowed paths, or denial behavior.');
        }

        if (str_contains($suffix, 'app-role') && ! $this->describesAppRolePath($contents)) {
            $findings[] = $this->finding($file, 'App-role companion files must explicitly describe the app-role invocation path.');
        }

        if (! str_contains($contents, "\n## Test Mapping")) {
            $findings[] = $this->finding($file, 'Role companion files must include "## Test Mapping".');
        }

        return $findings;
    }

    private function hasBehaviorSection(string $contents): bool
    {
        return array_any(self::BEHAVIOR_HEADINGS, fn ($heading) => str_contains($contents, (string) $heading));
    }

    private function describesAppRolePath(string $contents): bool
    {
        $lowerContents = strtolower($contents);

        return str_contains($lowerContents, 'app role')
            || str_contains($lowerContents, 'app-role')
            || str_contains($lowerContents, 'app-development')
            || str_contains($lowerContents, 'app-production');
    }

    private function finding(string $path, string $message): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: null,
            severity: FindingSeverity::Error,
            rule: 'command_docs.role_companion_coverage',
            message: $message,
        );
    }
}
