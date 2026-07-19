<?php

declare(strict_types=1);

namespace App\Librarian;

final readonly class CommandCatalogPhpMethodSourceExtractor
{
    public function classHeaderSource(string $source): string
    {
        $match = [];

        if (! preg_match(
            '/\b(?:final\s+|readonly\s+|abstract\s+)?class\s+[A-Za-z_][A-Za-z0-9_]*\b/s',
            $source,
            $match,
            PREG_OFFSET_CAPTURE,
        )) {
            return '';
        }

        $classOffset = $match[0][1] ?? null;

        if (! is_numeric($classOffset)) {
            return '';
        }

        return substr(string: $source, offset: 0, length: (int) $classOffset + strlen($match[0][0]));
    }

    public function methodSource(string $source, string $method): ?string
    {
        $match = [];
        $pattern =
            '/((?:\s*#\[[^\]]+\]\s*)*)\s*(?:public|protected|private)\s+function\s+'
            .preg_quote(str: $method, delimiter: '/')
            .'\b[^{]*\{/s';

        if (! preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $startValue = $match[0][1] ?? null;

        if (! is_numeric($startValue)) {
            return null;
        }

        $start = (int) $startValue;
        $openBrace = $start + strlen($match[0][0]) - 1;

        if (($source[$openBrace] ?? null) !== '{') {
            return null;
        }

        $depth = 0;
        $length = strlen($source);

        for ($offset = $openBrace; $offset < $length; $offset++) {
            $char = $source[$offset];

            if ($char === '{') {
                $depth++;
            }

            if ($char === '}') {
                $depth--;
            }

            if ($depth === 0) {
                return substr(string: $source, offset: $start, length: $offset - $start + 1);
            }
        }

        return null;
    }
}
