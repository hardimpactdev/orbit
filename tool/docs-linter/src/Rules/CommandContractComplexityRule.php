<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;
use OrbitDocsLinter\CommandDocsLintSeverity;

final class CommandContractComplexityRule implements CommandDocsLintRule
{
    private const MaxScore = 70;

    private const MaxBehaviorItems = 16;

    public function id(): string
    {
        return 'command_docs.command_contract_complexity';
    }

    public function group(): string
    {
        return 'complexity';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            foreach ($context->commandDirectories($familyDirectory) as $commandDirectory) {
                $commandName = preg_replace('/^[1-9]\d*_/', '', basename($commandDirectory));
                $file = "{$commandDirectory}/technical/1_{$commandName}.md";

                if (! is_file($file)) {
                    continue;
                }

                $contents = $context->read($file);
                $metrics = $this->metrics($contents);
                $score = $this->score($metrics);

                if ($score <= self::MaxScore && $metrics['behavior_items'] <= self::MaxBehaviorItems) {
                    continue;
                }

                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($file),
                    ruleId: $this->id(),
                    message: sprintf(
                        'Command contract complexity score is %d. Metrics: %d input field(s), %d conditional required field(s), %d caller-role path(s), %d behavior item(s), %d failure case(s), %d doctor handoff(s), and %d scope-boundary clause(s). Consider grouping behavior by path, extracting stable concepts, or adding stronger split-file test ownership.',
                        $score,
                        $metrics['input_fields'],
                        $metrics['conditional_required_fields'],
                        $metrics['caller_role_paths'],
                        $metrics['behavior_items'],
                        $metrics['failure_cases'],
                        $metrics['doctor_handoffs'],
                        $metrics['scope_boundary_clauses'],
                    ),
                    severity: CommandDocsLintSeverity::Warning,
                    line: $this->sectionLine($contents, 'Behavior Contract'),
                );
            }
        }

        return $findings;
    }

    /**
     * @return array{
     *     input_fields: int,
     *     conditional_required_fields: int,
     *     caller_role_paths: int,
     *     behavior_items: int,
     *     failure_cases: int,
     *     doctor_handoffs: int,
     *     scope_boundary_clauses: int,
     * }
     */
    private function metrics(string $contents): array
    {
        $inputRows = $this->firstTableRows($this->section($contents, 'Input Contract'));
        $callerRoleRows = $this->firstTableRows($this->section($contents, 'Caller Role Behavior'));
        $failureSection = $this->section($contents, 'Failure Semantics');
        $failureRows = $this->firstTableRows($failureSection);
        $behaviorSection = $this->section($contents, 'Behavior Contract');

        return [
            'input_fields' => count($inputRows),
            'conditional_required_fields' => count(array_filter(
                $inputRows,
                fn (array $row): bool => isset($row[2]) && ! in_array($this->normalizedCell($row[2]), ['never', 'optional', ''], true),
            )),
            'caller_role_paths' => count($callerRoleRows),
            'behavior_items' => $this->listItemCount($behaviorSection),
            'failure_cases' => max(count($failureRows), $this->listItemCount($failureSection)),
            'doctor_handoffs' => preg_match_all('/\bdoctor\s+--(?:family|self)\b/', $contents) ?: 0,
            'scope_boundary_clauses' => preg_match_all('/\b(?:must not|does not|do not|outside|not supported|forbidden)\b/i', $behaviorSection) ?: 0,
        ];
    }

    /**
     * @param array{
     *     input_fields: int,
     *     conditional_required_fields: int,
     *     caller_role_paths: int,
     *     behavior_items: int,
     *     failure_cases: int,
     *     doctor_handoffs: int,
     *     scope_boundary_clauses: int,
     * } $metrics
     */
    private function score(array $metrics): int
    {
        return $metrics['input_fields']
            + ($metrics['conditional_required_fields'] * 2)
            + (max(0, $metrics['caller_role_paths'] - 1) * 3)
            + ($metrics['behavior_items'] * 3)
            + ($metrics['failure_cases'] * 2)
            + $metrics['doctor_handoffs']
            + $metrics['scope_boundary_clauses'];
    }

    private function section(string $contents, string $heading): string
    {
        if (preg_match('/^## '.preg_quote($heading, '/').'\s*$(?<section>.*?)(?:^## |\z)/ms', $contents, $matches) === 1) {
            return $matches['section'];
        }

        return '';
    }

    private function sectionLine(string $contents, string $heading): ?int
    {
        if (preg_match('/^## '.preg_quote($heading, '/').'\s*$/m', $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return substr_count(substr($contents, 0, $matches[0][1]), "\n") + 1;
    }

    /**
     * @return list<list<string>>
     */
    private function firstTableRows(string $section): array
    {
        $rows = [];
        $inTable = false;

        foreach (explode("\n", $section) as $line) {
            $trimmed = trim($line);

            if (! str_starts_with($trimmed, '|')) {
                if ($inTable) {
                    break;
                }

                continue;
            }

            $inTable = true;

            if (preg_match('/^\|\s*-{3,}/', $trimmed) === 1) {
                continue;
            }

            $cells = array_map(
                fn (string $cell): string => trim($cell, " \t\n\r\0\x0B`"),
                explode('|', trim($trimmed, '|')),
            );

            if (isset($cells[0]) && in_array(strtolower($cells[0]), ['field', 'caller role', 'condition', 'failure'], true)) {
                continue;
            }

            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    private function listItemCount(string $section): int
    {
        return preg_match_all('/^\s*(?:[-*+]|\d+\.)\s+/m', $section);
    }

    private function normalizedCell(string $cell): string
    {
        return strtolower(trim($cell, " \t\n\r\0\x0B.`"));
    }
}
