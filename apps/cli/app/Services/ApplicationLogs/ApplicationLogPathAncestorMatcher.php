<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Matches a host cwd to exactly one visible owned path via strict ancestor rules.
 *
 * A candidate path matches when cwd equals the owned path or is a descendant
 * (`cwd === path` or `cwd` starts with `path/`). Zero or multiple matches fail.
 */
final readonly class ApplicationLogPathAncestorMatcher
{
    public function normalize(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        return $normalized === '' ? '/' : $normalized;
    }

    public function isAncestor(string $ownedPath, string $cwd): bool
    {
        $owned = $this->normalize($ownedPath);
        $current = $this->normalize($cwd);

        if ($owned === '' || $current === '') {
            return false;
        }

        return $owned === $current || str_starts_with($current, "{$owned}/");
    }

    /**
     * @param  list<array{selector: string, path: string}>  $candidates
     * @return array{ok: true, selector: string}|array{ok: false, reason: string, count: int}
     */
    public function uniqueAncestorMatch(string $cwd, array $candidates): array
    {
        $matches = [];

        foreach ($candidates as $candidate) {
            $selector = $candidate['selector'] ?? null;
            $path = $candidate['path'] ?? null;

            if (! is_string($selector) || $selector === '' || ! is_string($path) || $path === '') {
                continue;
            }

            if (! $this->isAncestor($path, $cwd)) {
                continue;
            }

            $matches[$selector] = $selector;
        }

        $count = count($matches);

        if ($count === 1) {
            return [
                'ok' => true,
                'selector' => array_first($matches),
            ];
        }

        return [
            'ok' => false,
            'reason' => $count === 0 ? 'cwd_target_missing' : 'cwd_target_ambiguous',
            'count' => $count,
        ];
    }
}
