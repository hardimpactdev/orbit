<?php

declare(strict_types=1);

namespace App\E2E\Support;

final class E2EPestSupportTree
{
    public static function canonicalDirectory(): string
    {
        return repo_path('apps/e2e/tests/E2E/Support');
    }

    public static function generatedRunnerDirectory(): string
    {
        return repo_path('apps/e2e/tests/Feature/Commands/Support');
    }

    public static function copyTo(string $destination): void
    {
        if (! is_dir($destination) && ! mkdir($destination, 0777, true) && ! is_dir($destination)) {
            throw new \RuntimeException("Could not create E2E support directory [{$destination}].");
        }

        $supportFiles = glob(self::canonicalDirectory().'/*.php') ?: [];

        if ($supportFiles === []) {
            $canonicalDirectory = self::canonicalDirectory();

            throw new \RuntimeException(
                "Canonical E2E Pest support tree is missing PHP files [{$canonicalDirectory}].",
            );
        }

        foreach ($supportFiles as $supportFile) {
            $supportTarget = $destination.'/'.basename($supportFile);

            if (! copy($supportFile, $supportTarget)) {
                throw new \RuntimeException("Could not copy E2E support file [{$supportFile}].");
            }
        }
    }
}
