<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;
use OrbitDocsLinter\CommandDocsLintSeverity;

final class RequirementSmellRule implements CommandDocsLintRule
{
    /**
     * @var list<string>
     */
    private const Phrases = [
        'appropriate',
        'reasonable',
        'sufficient',
        'proper',
        'relevant',
        'as needed',
        'if possible',
        'where applicable',
        'etc.',
        'and/or',
    ];

    public function id(): string
    {
        return 'command_docs.requirement_smell';
    }

    public function group(): string
    {
        return 'prose';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($this->markdownFiles($context) as $file) {
            array_push($findings, ...$this->fileFindings($context, $file, $context->read($file)));
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function markdownFiles(CommandDocsLintContext $context): array
    {
        $files = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            foreach ($context->markdownFiles($familyDirectory, recursive: false) as $file) {
                $files[] = $file;
            }

            foreach ($context->commandDirectories($familyDirectory) as $commandDirectory) {
                foreach ($context->markdownFiles($commandDirectory, recursive: false) as $file) {
                    $files[] = $file;
                }

                foreach ($context->technicalMarkdownFiles("{$commandDirectory}/technical") as $file) {
                    $files[] = $file;
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function fileFindings(CommandDocsLintContext $context, string $file, string $contents): array
    {
        $findings = [];
        $inFence = false;

        foreach (explode("\n", $contents) as $index => $line) {
            if (preg_match('/^\s*```/', $line) === 1) {
                $inFence = ! $inFence;

                continue;
            }

            if ($inFence || $this->isIgnoredLine($line)) {
                continue;
            }

            $prose = $this->cleanProse($line);

            if ($prose === '') {
                continue;
            }

            foreach (self::Phrases as $phrase) {
                if (! $this->containsPhrase($prose, $phrase)) {
                    continue;
                }

                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($file),
                    ruleId: $this->id(),
                    message: "Ambiguous phrase `{$phrase}`. Name the actor, condition, obligation, and observable result.",
                    severity: CommandDocsLintSeverity::Warning,
                    line: $index + 1,
                );
            }
        }

        return $findings;
    }

    private function isIgnoredLine(string $line): bool
    {
        $trimmed = trim($line);

        return $trimmed === ''
            || str_starts_with($trimmed, '#')
            || str_starts_with($trimmed, '|')
            || preg_match('/^\s*\|?\s*-{3,}/', $line) === 1;
    }

    private function cleanProse(string $line): string
    {
        $line = preg_replace('/`[^`]*`/', ' ', $line) ?? $line;
        $line = preg_replace('/https?:\/\/\S+/', ' ', $line) ?? $line;
        $line = preg_replace('/(?<!\w)--[a-z0-9][a-z0-9-]*/i', ' ', $line) ?? $line;
        $line = preg_replace('/(?:\.{0,2}\/|~\/|\/)[^\s]+/', ' ', $line) ?? $line;

        return trim(preg_replace('/\s+/', ' ', $line) ?? $line);
    }

    private function containsPhrase(string $prose, string $phrase): bool
    {
        if ($phrase === 'etc.') {
            return preg_match('/\betc\./i', $prose) === 1;
        }

        if ($phrase === 'and/or') {
            return str_contains(strtolower($prose), 'and/or');
        }

        return preg_match('/\b'.preg_quote($phrase, '/').'\b/i', $prose) === 1;
    }
}
