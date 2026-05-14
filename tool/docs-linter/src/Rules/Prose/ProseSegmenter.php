<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules\Prose;

/**
 * Splits markdown into paragraphs, bullets, and sentences while stripping code fences,
 * inline code, links, paths, and CLI flags so prose rules count words, not punctuation.
 *
 * Used by `DocumentComplexityRule`, `LongSectionStructureRule`, `BulletComplexityRule`,
 * `CompoundNounStackRule`, and `ReaderAddressRule`.
 */
final class ProseSegmenter
{
    public const KIND_PARAGRAPH = 'paragraph';

    public const KIND_BULLET = 'bullet';

    /**
     * @return list<array{kind: string, text: string, line: int}>
     */
    public static function segment(string $contents, bool $includeTableRows = false): array
    {
        $paragraphs = [];
        $buffer = [];
        $bufferLine = 1;
        $inFence = false;

        foreach (explode("\n", $contents) as $index => $line) {
            $lineNumber = $index + 1;

            if (preg_match('/^\s*```/', $line) === 1) {
                self::flush($paragraphs, $buffer, $bufferLine);
                $inFence = ! $inFence;

                continue;
            }

            if ($inFence) {
                continue;
            }

            if (self::isHeadingLine($line) || self::isHorizontalRuleLine($line)) {
                self::flush($paragraphs, $buffer, $bufferLine);

                continue;
            }

            if (self::isTableLine($line)) {
                self::flush($paragraphs, $buffer, $bufferLine);

                if (! $includeTableRows || self::isTableSeparator($line)) {
                    continue;
                }

                foreach (self::tableCellTexts($line) as $cell) {
                    $paragraphs[] = [
                        'kind' => self::KIND_PARAGRAPH,
                        'text' => $cell,
                        'line' => $lineNumber,
                    ];
                }

                continue;
            }

            if (preg_match('/^\s*(?:[-*+]|\d+\.)\s+(?<text>.+)$/', $line, $matches) === 1) {
                self::flush($paragraphs, $buffer, $bufferLine);
                $paragraphs[] = [
                    'kind' => self::KIND_BULLET,
                    'text' => self::cleanProse($matches['text']),
                    'line' => $lineNumber,
                ];

                continue;
            }

            $text = self::cleanProse($line);

            if (trim($text) === '') {
                self::flush($paragraphs, $buffer, $bufferLine);

                continue;
            }

            if ($buffer === []) {
                $bufferLine = $lineNumber;
            }

            $buffer[] = $text;
        }

        self::flush($paragraphs, $buffer, $bufferLine);

        return $paragraphs;
    }

    /**
     * @return list<string>
     */
    public static function sentences(string $paragraph): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph) ?: [];

        return array_values(array_filter(array_map('trim', $sentences)));
    }

    /**
     * @return list<string>
     */
    public static function words(string $text): array
    {
        preg_match_all('/[A-Za-z][A-Za-z\'-]*/', $text, $matches);

        return $matches[0];
    }

    public static function cleanProse(string $line): string
    {
        $line = preg_replace('/`[^`]*`/', ' ', $line) ?? $line;
        $line = preg_replace('/\[([^\]]*)\]\([^\)]*\)/', ' $1 ', $line) ?? $line;
        $line = preg_replace('/https?:\/\/\S+/', ' ', $line) ?? $line;
        $line = preg_replace('/(?<!\w)--[a-z0-9][a-z0-9-]*/i', ' ', $line) ?? $line;
        $line = preg_replace('/(?:\.{0,2}\/|~\/|\/)[^\s]+/', ' ', $line) ?? $line;

        return trim(preg_replace('/\s+/', ' ', $line) ?? $line);
    }

    public static function isHeadingLine(string $line): bool
    {
        return str_starts_with(trim($line), '#');
    }

    public static function isTableLine(string $line): bool
    {
        return str_starts_with(trim($line), '|');
    }

    public static function isTableSeparator(string $line): bool
    {
        return preg_match('/^\s*\|?\s*-{3,}/', $line) === 1
            || preg_match('/^\s*\|(?:\s*:?-{3,}:?\s*\|?)+\s*$/', $line) === 1;
    }

    public static function isHorizontalRuleLine(string $line): bool
    {
        return preg_match('/^\s*-{3,}\s*$/', $line) === 1;
    }

    /**
     * @return list<string>
     */
    public static function tableCellTexts(string $line): array
    {
        $trimmed = trim($line);
        $trimmed = trim($trimmed, '|');
        $cells = explode('|', $trimmed);
        $texts = [];

        foreach ($cells as $cell) {
            $text = self::cleanProse(trim($cell));

            if ($text === '') {
                continue;
            }

            $texts[] = $text;
        }

        return $texts;
    }

    /**
     * @param  list<array{kind: string, text: string, line: int}>  $paragraphs
     * @param  list<string>  $buffer
     */
    private static function flush(array &$paragraphs, array &$buffer, int $line): void
    {
        if ($buffer === []) {
            return;
        }

        $paragraphs[] = [
            'kind' => self::KIND_PARAGRAPH,
            'text' => implode(' ', $buffer),
            'line' => $line,
        ];

        $buffer = [];
    }
}
