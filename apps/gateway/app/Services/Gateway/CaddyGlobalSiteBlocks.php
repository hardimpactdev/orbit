<?php

declare(strict_types=1);

namespace App\Services\Gateway;

final readonly class CaddyGlobalSiteBlocks
{
    /**
     * @return list<string>
     */
    public function domains(string $contents): array
    {
        $domains = [];
        $depth = 0;
        $lines = preg_split('/\R/', $contents);
        $labels = new CaddyGlobalSiteLabels;

        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            if ($depth === 0) {
                foreach ($labels->fromLine($line) as $label) {
                    $domains[] = $label;
                }
            }

            $depth = max(0, $depth + $this->braceDelta($line));
        }

        return array_values(array_unique($domains));
    }

    /**
     * @param  list<string>  $domains
     */
    public function remove(string $contents, array $domains): string
    {
        $domains = $this->normalizeDomains($domains);

        if ($domains === []) {
            return $contents;
        }

        $lines = preg_split('/\R/', $contents);

        if ($lines === false) {
            return $contents;
        }

        $kept = [];
        $count = count($lines);
        $depth = 0;
        $parser = new CaddyGlobalSiteLabels;

        for ($index = 0; $index < $count; $index++) {
            $line = $lines[$index];
            $labels = $depth === 0 ? $parser->fromLine($line) : [];

            if ($this->shouldRemoveBlock($labels, $domains)) {
                $balance = $this->braceDelta($line);

                while ($balance > 0 && ($index + 1) < $count) {
                    $index++;
                    $balance += $this->braceDelta($lines[$index]);
                }

                $depth = 0;

                continue;
            }

            $kept[] = $line;
            $depth = max(0, $depth + $this->braceDelta($line));
        }

        return rtrim(implode("\n", $kept))."\n";
    }

    /**
     * @param  list<string>  $labels
     * @param  list<string>  $domains
     */
    private function shouldRemoveBlock(array $labels, array $domains): bool
    {
        return $labels !== [] && array_intersect($labels, $domains) !== [] && array_diff($labels, $domains) === [];
    }

    /**
     * @param  list<string>  $domains
     * @return list<string>
     */
    private function normalizeDomains(array $domains): array
    {
        $normalized = [];

        foreach ($domains as $domain) {
            $domain = trim($domain);

            if ($domain === '') {
                continue;
            }

            $normalized[] = $domain;
        }

        return array_values(array_unique($normalized));
    }

    private function braceDelta(string $line): int
    {
        return substr_count($line, needle: '{') - substr_count($line, needle: '}');
    }
}
