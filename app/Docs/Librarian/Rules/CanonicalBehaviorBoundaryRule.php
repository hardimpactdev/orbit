<?php

declare(strict_types=1);

namespace App\Docs\Librarian\Rules;

use App\Docs\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class CanonicalBehaviorBoundaryRule implements GroupedRule
{
    /**
     * @var array<string, string>
     */
    private const array SECTIONS = [
        'Input Resolution' => 'Renderer-specific execution branches belong in input-mode or renderer companion files, not canonical Input Resolution.',
        'Behavior Contract' => 'Renderer-specific behavior belongs in `6.1` or `6.2` renderer contracts, not canonical Behavior Contract.',
    ];

    /**
     * @var list<string>
     */
    private const array RENDERER_DETAIL_PATTERNS = [
        '/\b(?:human|json)\s+renderer\b/i',
        '/\b(?:human|json)\s+output\b/i',
        '/\bboth\s+renderers\b/i',
    ];

    /**
     * @var list<string>
     */
    private const array EXIT_STATUS_PATTERNS = [
        '/\bexit(?:s|ed|ing)?\s+`?(?:0|1|2|3|4|5|77)`?\b/i',
        '/\bexit(?:s|ed|ing)?\s+(?:zero|non-zero)\b/i',
        '/\bexit\s+code\b/i',
        '/\bexit\s+status\b/i',
    ];

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
                $commandName = $this->docs->commandName($commandDirectory);
                $file = "{$commandDirectory}/technical/1_{$commandName}.md";

                if (! is_file($file)) {
                    continue;
                }

                array_push($findings, ...$this->checkFile($file, file_get_contents($file) ?: ''));
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkFile(string $file, string $contents): array
    {
        $findings = [];

        foreach (self::SECTIONS as $heading => $rendererMessage) {
            $section = $this->section($contents, $heading);

            if ($section === null) {
                continue;
            }

            foreach ($this->matchingLines($section['contents'], self::RENDERER_DETAIL_PATTERNS) as $lineOffset) {
                $findings[] = $this->finding($file, $rendererMessage, $section['line'] + $lineOffset);
            }

            foreach ($this->matchingLines($section['contents'], self::EXIT_STATUS_PATTERNS) as $lineOffset) {
                $findings[] = $this->finding(
                    $file,
                    'Exit-status policy belongs in Failure Semantics or the shared exit status policy, not canonical Input Resolution or Behavior Contract.',
                    $section['line'] + $lineOffset,
                );
            }
        }

        return $findings;
    }

    /**
     * @return array{contents: string, line: int}|null
     */
    private function section(string $contents, string $heading): ?array
    {
        if (preg_match('/^## '.preg_quote($heading, '/').'\s*$(?<section>.*?)(?:^## |\z)/ms', $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return [
            'contents' => $matches['section'][0],
            'line' => $this->lineForOffset($contents, $matches['section'][1]),
        ];
    }

    /**
     * @param  list<string>  $patterns
     * @return list<int>
     */
    private function matchingLines(string $contents, array $patterns): array
    {
        $matches = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line) !== 1) {
                    continue;
                }

                $matches[] = $index;

                break;
            }
        }

        return array_values(array_unique($matches));
    }

    private function lineForOffset(string $contents, int $offset): int
    {
        return substr_count(substr($contents, 0, $offset), "\n") + 1;
    }

    private function finding(string $path, string $message, int $line): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: $line,
            severity: FindingSeverity::Error,
            rule: 'command_docs.canonical_behavior_boundaries',
            message: $message,
        );
    }
}
