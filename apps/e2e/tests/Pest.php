<?php

declare(strict_types=1);

function repo_path(string $path = ''): string
{
    $root = dirname(__DIR__, 3);

    if ($path === '') {
        return $root;
    }

    return $root.'/'.ltrim($path, '/');
}
