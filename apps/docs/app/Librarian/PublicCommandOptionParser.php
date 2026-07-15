<?php

declare(strict_types=1);

namespace App\Librarian;

final class PublicCommandOptionParser
{
    public function normativeUsageSignature(string $contents): ?string
    {
        $usage = trim($this->section($contents, 'Usage'));

        if ($usage === '') {
            return null;
        }

        $matches = [];

        if (preg_match('/\A```(?:bash|text|shell)\s*\n(?<body>.*?)^```\z/ms', $usage, $matches) !== 1) {
            return null;
        }

        $lines = array_values(array_filter(
            array_map('trim', explode("\n", trim($matches['body']))),
            static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
        ));

        if (count($lines) !== 1 || ! str_starts_with($lines[0], 'orbit ')) {
            return null;
        }

        return $lines[0];
    }

    public function section(string $contents, string $heading): string
    {
        $matches = [];
        $quotedHeading = preg_quote(str: $heading, delimiter: '/');

        if (preg_match("/^## {$quotedHeading}\\s*\$(?<section>.*?)(?=^## |\\z)/ms", $contents, $matches) !== 1) {
            return '';
        }

        return $matches['section'];
    }

    /**
     * @return list<string>
     */
    public function options(string $contents): array
    {
        $matches = [];
        preg_match_all('/(?<![a-zA-Z0-9])--[a-z0-9][a-z0-9-]*/', $contents, $matches);

        $options = array_values(array_unique($matches[0]));
        sort($options);

        return $options;
    }
}
