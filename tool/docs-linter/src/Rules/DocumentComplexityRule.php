<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;
use OrbitDocsLinter\CommandDocsLintSeverity;

final class DocumentComplexityRule implements CommandDocsLintRule
{
    private const MaxSentenceWords = 55;

    private const MaxParagraphWords = 150;

    private const MaxBulletWords = 45;

    private const MaxLix = 75.0;

    private const MaxLongSentenceShare = 0.35;

    public function id(): string
    {
        return 'command_docs.document_complexity';
    }

    public function group(): string
    {
        return 'complexity';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($this->markdownFiles($context) as $file) {
            $contents = $context->read($file);

            array_push(
                $findings,
                ...$this->duplicateHeadingFindings($context, $file, $contents),
                ...$this->proseFindings($context, $file, $contents),
            );
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
    private function duplicateHeadingFindings(CommandDocsLintContext $context, string $file, string $contents): array
    {
        $findings = [];
        $seen = [];

        foreach (explode("\n", $contents) as $index => $line) {
            if (preg_match('/^(?<level>#{1,6})\s+(?<heading>.+?)\s*$/', $line, $matches) !== 1) {
                continue;
            }

            $heading = trim($matches['heading'], " \t`");
            $key = strtolower($heading);

            if (! isset($seen[$key])) {
                $seen[$key] = true;

                continue;
            }

            $findings[] = $this->warning(
                context: $context,
                path: $file,
                line: $index + 1,
                message: "Duplicate heading label `{$heading}` in one document. Rename one heading so LLMs can reference the section unambiguously.",
            );
        }

        return $findings;
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function proseFindings(CommandDocsLintContext $context, string $file, string $contents): array
    {
        $findings = [];
        $paragraphs = $this->paragraphs($contents);
        $sentenceLengths = [];
        $wordCount = 0;
        $longWordCount = 0;

        foreach ($paragraphs as $paragraph) {
            if ($paragraph['kind'] === 'bullet') {
                $words = $this->words($paragraph['text']);

                if (count($words) > self::MaxBulletWords) {
                    $findings[] = $this->warning(
                        context: $context,
                        path: $file,
                        line: $paragraph['line'],
                        message: sprintf('Bullet item has %d prose words, above calibrated threshold %d. Split condition, actor, action, and result.', count($words), self::MaxBulletWords),
                    );
                }

                continue;
            }

            $paragraphWords = $this->words($paragraph['text']);

            if (count($paragraphWords) > self::MaxParagraphWords) {
                $findings[] = $this->warning(
                    context: $context,
                    path: $file,
                    line: $paragraph['line'],
                    message: sprintf('Paragraph has %d prose words, above calibrated threshold %d. Split the paragraph into separate requirement blocks.', count($paragraphWords), self::MaxParagraphWords),
                );
            }

            foreach ($this->sentences($paragraph['text']) as $sentence) {
                $words = $this->words($sentence);

                if ($words === []) {
                    continue;
                }

                $sentenceLengths[] = count($words);
                $wordCount += count($words);
                $longWordCount += count(array_filter($words, fn (string $word): bool => strlen($word) > 6));

                if (count($words) > self::MaxSentenceWords) {
                    $findings[] = $this->warning(
                        context: $context,
                        path: $file,
                        line: $paragraph['line'],
                        message: sprintf('Sentence has %d prose words, above calibrated threshold %d. Split condition, actor, action, and result.', count($words), self::MaxSentenceWords),
                    );
                }
            }
        }

        if ($wordCount === 0 || $sentenceLengths === []) {
            return $findings;
        }

        $lix = (float) (array_sum($sentenceLengths) / count($sentenceLengths)) + (($longWordCount * 100) / $wordCount);

        if ($lix > self::MaxLix) {
            $findings[] = $this->warning(
                context: $context,
                path: $file,
                line: 1,
                message: sprintf('Document LIX is %.1f, above calibrated threshold %.1f. Prefer shorter sentences and simpler prose words.', $lix, self::MaxLix),
            );
        }

        $longSentences = count(array_filter($sentenceLengths, fn (int $length): bool => $length > 30));
        $longSentenceShare = $longSentences / count($sentenceLengths);

        if (count($sentenceLengths) >= 4 && $longSentenceShare > self::MaxLongSentenceShare) {
            $findings[] = $this->warning(
                context: $context,
                path: $file,
                line: 1,
                message: sprintf('%.0f%% of sentences exceed 30 prose words, above calibrated threshold %.0f%%. Split dense requirement prose.', $longSentenceShare * 100, self::MaxLongSentenceShare * 100),
            );
        }

        return $findings;
    }

    /**
     * @return list<array{kind: string, text: string, line: int}>
     */
    private function paragraphs(string $contents): array
    {
        $paragraphs = [];
        $buffer = [];
        $bufferLine = 1;
        $inFence = false;

        foreach (explode("\n", $contents) as $index => $line) {
            $lineNumber = $index + 1;

            if (preg_match('/^\s*```/', $line) === 1) {
                $this->flushParagraph($paragraphs, $buffer, $bufferLine);
                $inFence = ! $inFence;

                continue;
            }

            if ($inFence || $this->isIgnoredLine($line)) {
                $this->flushParagraph($paragraphs, $buffer, $bufferLine);

                continue;
            }

            if (preg_match('/^\s*(?:[-*+]|\d+\.)\s+(?<text>.+)$/', $line, $matches) === 1) {
                $this->flushParagraph($paragraphs, $buffer, $bufferLine);
                $paragraphs[] = [
                    'kind' => 'bullet',
                    'text' => $this->cleanProse($matches['text']),
                    'line' => $lineNumber,
                ];

                continue;
            }

            $text = $this->cleanProse($line);

            if (trim($text) === '') {
                $this->flushParagraph($paragraphs, $buffer, $bufferLine);

                continue;
            }

            if ($buffer === []) {
                $bufferLine = $lineNumber;
            }

            $buffer[] = $text;
        }

        $this->flushParagraph($paragraphs, $buffer, $bufferLine);

        return $paragraphs;
    }

    /**
     * @param  list<array{kind: string, text: string, line: int}>  $paragraphs
     * @param  list<string>  $buffer
     */
    private function flushParagraph(array &$paragraphs, array &$buffer, int $line): void
    {
        if ($buffer === []) {
            return;
        }

        $paragraphs[] = [
            'kind' => 'paragraph',
            'text' => implode(' ', $buffer),
            'line' => $line,
        ];

        $buffer = [];
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

    /**
     * @return list<string>
     */
    private function sentences(string $paragraph): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph) ?: [];

        return array_values(array_filter(array_map('trim', $sentences)));
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        preg_match_all('/[A-Za-z][A-Za-z\'-]*/', $text, $matches);

        return $matches[0];
    }

    private function warning(CommandDocsLintContext $context, string $path, int $line, string $message): CommandDocsLintFinding
    {
        return new CommandDocsLintFinding(
            path: $context->relativePath($path),
            ruleId: $this->id(),
            message: $message,
            severity: CommandDocsLintSeverity::Warning,
            line: $line,
        );
    }
}
