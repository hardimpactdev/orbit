<?php

declare(strict_types=1);

namespace App\Services\Updates;

final class CheckoutPathResolver
{
    public function resolve(): string
    {
        $basePath = base_path();
        $isCli = basename($basePath) === 'cli' && basename(dirname($basePath)) === 'apps';

        return $isCli ? dirname($basePath, 2) : $basePath;
    }
}
