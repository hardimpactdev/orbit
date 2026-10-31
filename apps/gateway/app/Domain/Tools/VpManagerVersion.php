<?php

declare(strict_types=1);

namespace App\Domain\Tools;

final readonly class VpManagerVersion
{
    public static function satisfies(string $observed, string $required): bool
    {
        $normalized = new SemverVersionNormalizer()->normalize(preg_replace('/\Avp\s+/', '', trim($observed)) ?? '');

        return $normalized !== null && version_compare($normalized, $required, '>=');
    }
}
