<?php

declare(strict_types=1);

namespace Orbit\Core\Database;

use InvalidArgumentException;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class DatabaseQueryClassifier
{
    private const array READ_TOKENS = [
        'select',
        'show',
        'describe',
        'desc',
        'explain',
        'pragma',
    ];

    public function classify(string $sql): DatabaseQueryClassification
    {
        $normalized = $this->stripLeadingComments($sql);

        if ($normalized === '') {
            throw new InvalidArgumentException('SQL is required.');
        }

        $token = $this->firstToken($normalized);

        if (hash_equals('with', $token)) {
            return $this->classifyWithStatement($normalized);
        }

        $mode = in_array($token, self::READ_TOKENS, strict: true) ? 'read' : 'write';

        return new DatabaseQueryClassification(
            mode: $mode,
            requiresWriteMode: hash_equals('write', $mode),
        );
    }

    private function classifyWithStatement(string $sql): DatabaseQueryClassification
    {
        $offset = strlen('with');
        $offset = $this->skipWhitespaceAndComments($sql, $offset);
        $recursiveMatches = [];

        if (
            preg_match(
                pattern: '/\Grecursive\b/i',
                subject: $sql,
                matches: $recursiveMatches,
                flags: 0,
                offset: $offset,
            ) === 1
        ) {
            $offset += strlen($recursiveMatches[0]);
        }

        while (true) {
            $offset = $this->skipWhitespaceAndComments($sql, $offset);
            $cteMatches = [];

            if (
                preg_match(
                    pattern: '/\G(?:"[^"]+"|`[^`]+`|[A-Za-z_][A-Za-z0-9_]*)(?:\s*\([^)]*\))?\s+as\s*\(/i',
                    subject: $sql,
                    matches: $cteMatches,
                    flags: 0,
                    offset: $offset,
                ) !== 1
            ) {
                return $this->writeClassification();
            }

            $openParen = $offset + strlen($cteMatches[0]) - 1;
            $closeParen = $this->matchingParen($sql, $openParen);

            if ($closeParen === null) {
                return $this->writeClassification();
            }

            $body = substr($sql, $openParen + 1, $closeParen - $openParen - 1);
            $bodyClassification = $this->classify($body);

            if ($bodyClassification->requiresWriteMode) {
                return $bodyClassification;
            }

            $offset = $this->skipWhitespaceAndComments($sql, $closeParen + 1);

            if (($sql[$offset] ?? null) !== ',') {
                break;
            }

            $offset++;
        }

        $remaining = substr($sql, $offset);

        if ($this->stripLeadingComments($remaining) === '') {
            return $this->writeClassification();
        }

        return $this->classify($remaining);
    }

    private function writeClassification(): DatabaseQueryClassification
    {
        return new DatabaseQueryClassification(
            mode: 'write',
            requiresWriteMode: true,
        );
    }

    private function firstToken(string $sql): string
    {
        $tokenDelimiters = " \t\r\n(";
        $token = strtok($sql, $tokenDelimiters);

        return strtolower($token === false ? $sql : $token);
    }

    private function stripLeadingComments(string $sql): string
    {
        return substr(
            string: $sql,
            offset: $this->skipWhitespaceAndComments($sql, 0),
        );
    }

    private function skipWhitespaceAndComments(string $sql, int $offset): int
    {
        $length = strlen($sql);

        while ($offset < $length) {
            while ($offset < $length && ctype_space($sql[$offset])) {
                $offset++;
            }

            if (substr(string: $sql, offset: $offset, length: 2) === '--') {
                $lineEnd = strpos(haystack: $sql, needle: "\n", offset: $offset + 2);

                if ($lineEnd === false) {
                    return $length;
                }

                $offset = $lineEnd + 1;

                continue;
            }

            if (substr(string: $sql, offset: $offset, length: 2) === '/*') {
                $commentEnd = strpos(haystack: $sql, needle: '*/', offset: $offset + 2);

                if ($commentEnd === false) {
                    return $length;
                }

                $offset = $commentEnd + 2;

                continue;
            }

            break;
        }

        return $offset;
    }

    private function matchingParen(string $sql, int $openParen): ?int
    {
        $length = strlen($sql);
        $depth = 0;
        $quote = null;

        for ($index = $openParen; $index < $length; $index++) {
            $char = $sql[$index];
            $next = $sql[$index + 1] ?? '';

            if ($quote !== null) {
                if ($char === $quote) {
                    if (($quote === "'" || $quote === '"') && $next === $quote) {
                        $index++;

                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;

                continue;
            }

            if ($char === '-' && $next === '-') {
                $lineEnd = strpos(haystack: $sql, needle: "\n", offset: $index + 2);
                $index = $lineEnd === false ? $length : $lineEnd;

                continue;
            }

            if ($char === '/' && $next === '*') {
                $commentEnd = strpos(haystack: $sql, needle: '*/', offset: $index + 2);

                if ($commentEnd === false) {
                    return null;
                }

                $index = $commentEnd + 1;

                continue;
            }

            if ($char === '(') {
                $depth++;
            }

            if ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }
}
