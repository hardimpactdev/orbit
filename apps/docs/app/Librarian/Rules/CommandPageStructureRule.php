<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class CommandPageStructureRule implements GroupedRule
{
    private const int MIN_SECTIONS = 2;

    /**
     * @var list<string>
     */
    private const array LEGACY_LABELS = [
        '**Purpose:**',
        '**Description:**',
        '**Inputs:**',
        '**Output:**',
        '**Effects:**',
    ];

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
            foreach ($this->docs->markdownFiles($familyDirectory) as $file) {
                if (! $this->isPublicCommandPage($this->docs->relativePath($file))) {
                    continue;
                }

                array_push($findings, ...$this->checkPublicCommandPage($file));
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkPublicCommandPage(string $file): array
    {
        $contents = $this->docs->contents($file);
        $sectionCount = $this->countSectionHeadings($contents);

        if ($sectionCount < self::MIN_SECTIONS) {
            return [
                $this->finding(
                    $this->docs->relativePath($file),
                    sprintf(
                        'Public command page has %d `##` section heading(s). Use the modern section structure (`## Usage`, `## Arguments and options`, `## Behavior Summary`, `## Requirements`, `## Output Summary`, `## Examples`, `## Related`) so prose rules can lint each section.',
                        $sectionCount,
                    ),
                ),
            ];
        }

        $legacyLabel = $this->findLegacyLeadIn($contents);

        if ($legacyLabel === null) {
            return [];
        }

        return [
            $this->finding(
                $this->docs->relativePath($file),
                "Public command page uses the legacy `{$legacyLabel}` inline lead-in before the first `##` section. Replace the inline-bold labels with a free-form prose paragraph; the per-field details belong inside `## Arguments and options`, `## Behavior Summary`, and `## Output Summary`.",
            ),
        ];
    }

    private function isPublicCommandPage(string $relativePath): bool
    {
        if (preg_match('#^docs/domains/[1-9]\d*_[a-z0-9-]+/[1-9]\d*_[a-z0-9-]+/[^/]+\.md$#', $relativePath) !== 1) {
            return false;
        }

        if (str_contains($relativePath, '/technical/')) {
            return false;
        }

        return ! in_array(basename($relativePath, '.md'), ['README', 'CHANGELOG'], true);
    }

    private function countSectionHeadings(string $contents): int
    {
        $count = 0;
        $inFence = false;

        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/^\s*```/', $line) === 1) {
                $inFence = ! $inFence;

                continue;
            }

            if ($inFence) {
                continue;
            }

            if (preg_match('/^##\s+\S/', $line) === 1) {
                $count++;
            }
        }

        return $count;
    }

    private function findLegacyLeadIn(string $contents): ?string
    {
        $inFence = false;

        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/^\s*```/', $line) === 1) {
                $inFence = ! $inFence;

                continue;
            }

            if ($inFence) {
                continue;
            }

            if (preg_match('/^##\s+\S/', $line) === 1) {
                return null;
            }

            $trimmed = ltrim($line);

            foreach (self::LEGACY_LABELS as $label) {
                if (str_starts_with($trimmed, $label)) {
                    return $label;
                }
            }
        }

        return null;
    }

    private function finding(string $path, string $message): Finding
    {
        return new Finding(
            path: $path,
            line: 1,
            severity: FindingSeverity::Warning,
            rule: 'command_docs.command_page_structure',
            message: $message,
        );
    }
}
