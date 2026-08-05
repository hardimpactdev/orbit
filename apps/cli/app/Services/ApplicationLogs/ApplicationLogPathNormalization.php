<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Path normalization and ancestor checks for application-log cwd inventory.
 */
final readonly class ApplicationLogPathNormalization
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
}
