<?php

declare(strict_types=1);

if (! function_exists('repo_path')) {
    /**
     * Resolve a path inside the Orbit monorepo root from the apps/e2e harness.
     *
     * Resolved relative to this file so it works both inside the booted Laravel
     * application and from the standalone `bin/e2e-dev-topology*` scripts.
     */
    function repo_path(string $path = ''): string
    {
        $root = dirname(__DIR__, 4);

        if ($path === '') {
            return $root;
        }

        return $root.'/'.ltrim($path, '/');
    }
}
