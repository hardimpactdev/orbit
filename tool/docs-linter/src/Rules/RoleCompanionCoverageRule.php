<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class RoleCompanionCoverageRule implements CommandDocsLintRule
{
    /**
     * @var array<int, string>
     */
    private const array ROLE_SUFFIXES = [
        2 => '_on-control-node',
        3 => '_on-gateway-node',
        4 => '_on-app-node',
    ];

    /**
     * @var list<string>
     */
    private const array BEHAVIOR_HEADINGS = [
        '## Allowed Paths',
        '## App-Node Rules',
        '## Behavior',
        '## Control-Node Enrollment',
        '## Error Contract',
        '## First-Gateway Bootstrap',
        '## Forwarding Contract',
        '## Gateway Authority Rules',
        '## Gateway-Connected Operation',
        '## Gateway Convergence And Adoption',
        '## Gateway Path Matrix',
        '## Validity',
    ];

    public function id(): string
    {
        return 'command_docs.role_companion_coverage';
    }

    public function group(): string
    {
        return 'references';
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

                if (! $this->requiresRoleCompanions($canonicalContents, $commandDirectory, $commandName)) {
                    continue;
                }

                foreach (self::ROLE_SUFFIXES as $slot => $suffix) {
                    $companionFile = "{$commandDirectory}/technical/{$slot}_{$commandName}{$suffix}.md";

                    if (! is_file($companionFile)) {
                        $findings[] = $this->finding(
                            $context,
                            $canonicalFile,
                            "Canonical contract declares role-specific companion behavior but {$slot}_{$commandName}{$suffix}.md is missing.",
                        );

                        continue;
                    }

                    if (! str_contains($canonicalContents, "{$slot}_{$commandName}{$suffix}.md")) {
                        $findings[] = $this->finding(
                            $context,
                            $canonicalFile,
                            "Canonical contract must link {$slot}_{$commandName}{$suffix}.md when role companion files are used.",
                        );
                    }

                    $findings = [
                        ...$findings,
                        ...$this->checkCompanionFile($context, $companionFile, $suffix),
                    ];
                }
            }
        }

        return $findings;
    }

    private function requiresRoleCompanions(string $canonicalContents, string $commandDirectory, string $commandName): bool
    {
        if (str_contains($canonicalContents, 'Role-specific behavior is defined in these companion contracts')) {
            return true;
        }

        foreach (self::ROLE_SUFFIXES as $slot => $suffix) {
            if (is_file("{$commandDirectory}/technical/{$slot}_{$commandName}{$suffix}.md")) {
                return true;
            }

            if (str_contains($canonicalContents, "{$slot}_{$commandName}{$suffix}.md")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function checkCompanionFile(CommandDocsLintContext $context, string $file, string $suffix): array
    {
        $findings = [];
        $contents = $context->read($file);

        if (! str_contains($contents, '[Back to')) {
            $findings[] = $this->finding($context, $file, 'Role companion files must link back to the canonical technical contract.');
        }

        if (! $this->hasBehaviorSection($contents)) {
            $findings[] = $this->finding($context, $file, 'Role companion files must define role-specific behavior, allowed paths, or denial behavior.');
        }

        if (str_contains($suffix, 'app-node') && ! $this->describesAppNodePath($contents)) {
            $findings[] = $this->finding($context, $file, 'App-node role companion files must explicitly describe the app-node invocation path.');
        }

        if (! str_contains($contents, "\n## Test Mapping")) {
            $findings[] = $this->finding($context, $file, 'Role companion files must include "## Test Mapping".');
        }

        return $findings;
    }

    private function hasBehaviorSection(string $contents): bool
    {
        return array_any(self::BEHAVIOR_HEADINGS, fn ($heading) => str_contains($contents, (string) $heading));
    }

    private function describesAppNodePath(string $contents): bool
    {
        $lowerContents = strtolower($contents);

        return str_contains($lowerContents, 'app node') || str_contains($lowerContents, 'app-node');
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
