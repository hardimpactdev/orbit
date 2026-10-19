<?php

declare(strict_types=1);

namespace Orbit\Boost;

use RuntimeException;

/**
 * The stock Laravel Boost guidance sections that Orbit's Boost projects drop: frontend bundling advice does not
 * apply to them, and Orbit writes the documentation a change needs.
 */
final class GuidelineSections
{
    /** @var list<string> */
    public const array Removed = ['Frontend Bundling', 'Vite Error', 'Documentation Files'];

    public static function withoutRemoved(string $guidelines): string
    {
        $headings = implode('|', array_map(static fn (string $heading): string => preg_quote($heading, '/'), self::Removed));
        $result = preg_replace('/\n## (?:'.$headings.')\n.*?(?=\n(?:## |=== )|\z)/s', '', $guidelines);

        if (! is_string($result)) {
            throw new RuntimeException('Could not remove unsupported Boost guidance.');
        }

        return $result;
    }
}
