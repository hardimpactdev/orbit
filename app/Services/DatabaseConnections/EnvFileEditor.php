<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

final class EnvFileEditor
{
    /**
     * @return array<string, string>
     */
    public function parse(string $contents): array
    {
        $values = [];

        foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
            $parsed = $this->parseAssignment($line);

            if ($parsed === null) {
                continue;
            }

            [$key, $value] = $parsed;

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $updates
     */
    public function update(string $contents, array $updates): string
    {
        $lineEnding = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
        $remaining = $updates;

        foreach ($lines as $index => $line) {
            $parsed = $this->parseAssignment($line);

            if ($parsed === null) {
                continue;
            }

            [$key] = $parsed;

            if (! array_key_exists($key, $remaining)) {
                continue;
            }

            $lines[$index] = sprintf('%s=%s', $key, $this->formatValue($remaining[$key]));

            unset($remaining[$key]);
        }

        foreach ($remaining as $key => $value) {
            $lines[] = sprintf('%s=%s', $key, $this->formatValue($value));
        }

        return implode($lineEnding, $lines);
    }

    /**
     * @return array{string, string}|null
     */
    private function parseAssignment(string $line): ?array
    {
        $trimmed = ltrim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            return null;
        }

        $position = strpos($line, '=');

        if ($position === false) {
            return null;
        }

        $key = trim(substr($line, 0, $position));

        if ($key === '') {
            return null;
        }

        $value = substr($line, $position + 1);

        return [$key, $this->parseValue($value)];
    }

    private function parseValue(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        $quote = $trimmed[0];
        $last = substr($trimmed, -1);

        if (($quote === '"' || $quote === "'") && $last === $quote) {
            $inner = substr($trimmed, 1, -1);

            return $quote === '"'
                ? str_replace(['\\"', '\\\\'], ['"', '\\'], $inner)
                : str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
        }

        return $trimmed;
    }

    private function formatValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/\s/', $value) !== 1) {
            return $value;
        }

        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
