<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\TemplateAnalyzer;

/**
 * Parser for .env files following Laravel/Dotenv conventions.
 *
 * Handles:
 * - Comments (lines starting with #)
 * - Quoted values (single and double quotes)
 * - Variable interpolation markers (${VAR} format)
 * - Multiline values (not common but supported)
 * - Export prefix (export VAR=value)
 */
final class EnvParser
{
    /**
     * @var array<string, string>
     */
    private array $variables = [];

    /**
     * Parse .env content and return all variables.
     *
     * @return array<string, string>
     */
    public function parse(string $content): array
    {
        $this->variables = [];

        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $this->parseLine($line);
        }

        return $this->variables;
    }

    /**
     * Parse .env content and return only specific keys.
     *
     * @param  array<int, string>  $keys
     * @return array<string, string|null>
     */
    public function parseOnly(string $content, array $keys): array
    {
        $all = $this->parse($content);

        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $all[$key] ?? null;
        }

        return $result;
    }

    /**
     * Get a single value from .env content.
     */
    public function get(string $content, string $key): ?string
    {
        $all = $this->parse($content);

        return $all[$key] ?? null;
    }

    private function parseLine(string $line): void
    {
        // Trim whitespace
        $line = trim($line);

        // Skip empty lines
        if ($line === '') {
            return;
        }

        // Skip comments
        if (str_starts_with($line, '#')) {
            return;
        }

        // Handle 'export' prefix (export VAR=value)
        if (str_starts_with($line, 'export ')) {
            $line = substr($line, 7);
        }

        // Find the = separator
        $separatorPos = strpos($line, '=');
        if ($separatorPos === false) {
            return;
        }

        $key = trim(substr($line, 0, $separatorPos));
        $value = substr($line, $separatorPos + 1);

        // Skip invalid keys
        if ($key === '' || ! $this->isValidKey($key)) {
            return;
        }

        // Parse the value
        $this->variables[$key] = $this->parseValue($value);
    }

    private function isValidKey(string $key): bool
    {
        // Key must start with letter or underscore, contain only alphanumeric and underscores
        return (bool) preg_match('/^[A-Za-z_]\w*$/', $key);
    }

    private function parseValue(string $value): string
    {
        $value = trim($value);

        // Empty value
        if ($value === '') {
            return '';
        }

        // Handle quoted strings
        if ($this->isQuoted($value)) {
            return $this->parseQuotedValue($value);
        }

        // Unquoted value - remove inline comments
        $commentPos = strpos($value, ' #');
        if ($commentPos !== false) {
            return trim(substr($value, 0, $commentPos));
        }

        return $value;
    }

    private function isQuoted(string $value): bool
    {
        $firstChar = $value[0] ?? '';

        return $firstChar === '"' || $firstChar === "'";
    }

    private function parseQuotedValue(string $value): string
    {
        $quote = $value[0];
        $value = substr($value, 1);

        // Find closing quote
        $endPos = strpos($value, $quote);
        if ($endPos === false) {
            // Unclosed quote - take everything
            return $value;
        }

        $value = substr($value, 0, $endPos);

        // For double quotes, process escape sequences
        if ($quote === '"') {
            return $this->processEscapeSequences($value);
        }

        return $value;
    }

    private function processEscapeSequences(string $value): string
    {
        $replacements = [
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\"' => '"',
            '\\\\' => '\\',
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $value
        );
    }
}
