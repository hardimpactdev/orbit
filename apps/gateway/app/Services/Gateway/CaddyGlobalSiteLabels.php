<?php

declare(strict_types=1);

namespace App\Services\Gateway;

final readonly class CaddyGlobalSiteLabels
{
    /**
     * @return list<string>
     */
    public function fromLine(string $line): array
    {
        $matches = [];

        if (preg_match('/^\s*(?P<header>[^#{}][^{]*)\{\s*(?:#.*)?$/', $line, $matches) !== 1) {
            return [];
        }

        $header = trim($matches['header']);

        if ($header === '' || str_starts_with($header, '(') || str_starts_with($header, '@')) {
            return [];
        }

        $parts = preg_split('/[\s,]+/', $header);

        if ($parts === false) {
            return [];
        }

        $labels = [];

        foreach ($parts as $part) {
            $label = $this->normalize($part);

            if ($label === null) {
                continue;
            }

            $labels[] = $label;
        }

        return array_values(array_unique($labels));
    }

    private function normalize(string $label): ?string
    {
        $label = trim($label);

        if ($label === '' || str_starts_with($label, ':')) {
            return null;
        }

        if (str_contains($label, '://')) {
            $host = parse_url($label, PHP_URL_HOST);

            $label = is_string($host) ? $host : '';
        }

        $label = trim($label, characters: '[]');

        if ($label === '' || ! str_contains($label, '.')) {
            return null;
        }

        return $label;
    }
}
