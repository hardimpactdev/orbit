<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Matches a host cwd to exactly one visible owned path via strict ancestor rules.
 */
final readonly class ApplicationLogPathAncestorMatcher
{
    public function __construct(
        private ApplicationLogPathNormalization $paths = new ApplicationLogPathNormalization,
    ) {}

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

            if (! $this->paths->isAncestor($path, $cwd)) {
                continue;
            }

            $matches[$selector] = $selector;
        }

        $count = count($matches);

        if ($count !== 1) {
            return [
                'ok' => false,
                'reason' => $count === 0 ? 'cwd_target_missing' : 'cwd_target_ambiguous',
                'count' => $count,
            ];
        }

        $selector = array_first($matches);

        if (! is_string($selector) || $selector === '') {
            return [
                'ok' => false,
                'reason' => 'cwd_target_missing',
                'count' => 0,
            ];
        }

        return [
            'ok' => true,
            'selector' => $selector,
        ];
    }
}
