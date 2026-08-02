<?php

declare(strict_types=1);

namespace Orbit\Core\Caddy;

/**
 * Strips the exact obsolete local-CA intermediate_lifetime 3599d override from a
 * Caddyfile. Only mutates `ca local { ... }` blocks structurally; other CAs and
 * non-matching lifetimes are preserved. PEM storage is never touched.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class CaddyfileLocalCaIntermediateLifetime
{
    public const string OBSOLETE_VALUE = '3599d';

    public static function withoutObsoleteLocalOverride(string $contents): string
    {
        if (! str_contains($contents, self::OBSOLETE_VALUE)) {
            return $contents;
        }

        if (! preg_match('/\bca\s+local\s*\{/', $contents)) {
            return $contents;
        }

        $updated = self::mapNamedBlocks(
            $contents,
            namePattern: '/\bca\s+local\b/',
            transformBody: self::stripObsoleteLifetimeFromCaLocalBody(...),
        );

        if ($updated === $contents) {
            return $contents;
        }

        $updated = self::mapNamedBlocks(
            $updated,
            namePattern: '/\bpki\b/',
            transformBody: static fn (string $body): ?string => trim($body) === '' ? '' : null,
        );

        $collapsed = preg_replace(pattern: "/\n{3,}/", replacement: "\n\n", subject: $updated);

        return is_string($collapsed) ? $collapsed : $updated;
    }

    private static function stripObsoleteLifetimeFromCaLocalBody(string $body): ?string
    {
        $pattern = '/^[ \t]*intermediate_lifetime[ \t]+'.preg_quote(self::OBSOLETE_VALUE, delimiter: '/').'[ \t]*\n?/m';
        $stripped = preg_replace(pattern: $pattern, replacement: '', subject: $body);

        if (! is_string($stripped) || $stripped === $body) {
            return null;
        }

        return trim($stripped) === '' ? '' : $stripped;
    }

    /**
     * @param  callable(string): (?string)  $transformBody
     *         null keeps the block unchanged; empty string removes the whole
     *         named block; any other string replaces the block body.
     *
     * @mago-expect lint:halstead
     */
    private static function mapNamedBlocks(string $contents, string $namePattern, callable $transformBody): string
    {
        $offset = 0;
        $length = strlen($contents);
        $output = '';

        while ($offset < $length) {
            $next = self::nextNamedBlock($contents, $namePattern, $offset);

            if ($next === null) {
                $output .= substr($contents, $offset);

                break;
            }

            [
                'name_start' => $nameStart,
                'brace_pos' => $bracePos,
                'close_brace' => $closeBrace,
            ] = $next;

            if ($bracePos === null || $closeBrace === null) {
                $output .= substr($contents, $offset, $nameStart + 1 - $offset);
                $offset = $nameStart + 1;

                continue;
            }

            $body = substr($contents, $bracePos + 1, $closeBrace - $bracePos - 1);
            $replacementBody = $transformBody($body);
            $lineStart = self::lineStartAt($contents, $nameStart);

            if ($replacementBody === null) {
                $output .= substr($contents, $offset, $closeBrace - $offset + 1);
                $offset = $closeBrace + 1;

                continue;
            }

            if ($replacementBody === '') {
                // Drop the whole named block including its line indent so a
                // sibling block does not inherit doubled indentation.
                $output .= substr($contents, $offset, $lineStart - $offset);
                $offset = self::skipTrailingNewline($contents, $closeBrace + 1);

                continue;
            }

            $indent = self::lineIndentAt($contents, $nameStart);
            $output .= substr($contents, $offset, $nameStart - $offset);
            $output .= substr($contents, $nameStart, $bracePos - $nameStart + 1);
            $output .= self::normalizeBlockBody($replacementBody, $indent);
            $output .= '}';
            $offset = $closeBrace + 1;
        }

        return $output;
    }

    /**
     * @return array{name_start: int, brace_pos: ?int, close_brace: ?int}|null
     */
    private static function nextNamedBlock(string $contents, string $namePattern, int $offset): ?array
    {
        $slice = substr($contents, $offset);
        $match = [];

        if (preg_match($namePattern, $slice, $match) !== 1) {
            return null;
        }

        $matchedText = $match[0] ?? null;

        if (! is_string($matchedText) || $matchedText === '') {
            return null;
        }

        $relativeStart = strpos($slice, $matchedText);

        if ($relativeStart === false) {
            return null;
        }

        $nameStart = $offset + $relativeStart;
        $nameEnd = $nameStart + strlen($matchedText);
        $bracePos = self::nextNonWhitespace($contents, $nameEnd);

        if ($bracePos === null || $contents[$bracePos] !== '{') {
            return [
                'name_start' => $nameStart,
                'brace_pos' => null,
                'close_brace' => null,
            ];
        }

        return [
            'name_start' => $nameStart,
            'brace_pos' => $bracePos,
            'close_brace' => self::matchingCloseBrace($contents, $bracePos),
        ];
    }

    private static function nextNonWhitespace(string $contents, int $from): ?int
    {
        $length = strlen($contents);

        for ($i = $from; $i < $length; $i++) {
            if (! ctype_space($contents[$i])) {
                return $i;
            }
        }

        return null;
    }

    private static function matchingCloseBrace(string $contents, int $openBrace): ?int
    {
        $depth = 0;
        $length = strlen($contents);

        for ($i = $openBrace; $i < $length; $i++) {
            $char = $contents[$i];

            if ($char === '{') {
                $depth++;

                continue;
            }

            if ($char !== '}') {
                continue;
            }

            $depth--;

            if ($depth === 0) {
                return $i;
            }
        }

        return null;
    }

    private static function skipTrailingNewline(string $contents, int $from): int
    {
        $length = strlen($contents);

        if ($from < $length && $contents[$from] === "\r") {
            $from++;
        }

        if ($from < $length && $contents[$from] === "\n") {
            return $from + 1;
        }

        return $from;
    }

    private static function lineStartAt(string $contents, int $position): int
    {
        $lineStart = $position;

        while ($lineStart > 0 && $contents[$lineStart - 1] !== "\n") {
            $lineStart--;
        }

        return $lineStart;
    }

    private static function lineIndentAt(string $contents, int $position): string
    {
        $lineStart = self::lineStartAt($contents, $position);
        $indent = '';

        for ($i = $lineStart; $i < $position; $i++) {
            $char = $contents[$i];

            if ($char === ' ' || $char === "\t") {
                $indent .= $char;

                continue;
            }

            break;
        }

        return $indent;
    }

    private static function normalizeBlockBody(string $body, string $indent): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $body);
        $kept = [];

        foreach (is_array($lines) ? $lines : [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $kept[] = $line;
        }

        if ($kept === []) {
            return "\n{$indent}";
        }

        return "\n".implode("\n", $kept)."\n{$indent}";
    }
}
