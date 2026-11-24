<?php

declare(strict_types=1);

namespace App\Domain\Projects;

enum ProjectType: string
{
    case Monorepo = 'monorepo';
    case LaravelApp = 'laravel-app';
    case SymfonyApp = 'symfony-app';
    case LaravelPackage = 'laravel-package';
    case NodePackage = 'node-package';
    case Generic = 'generic';

    public function isWebServing(): bool
    {
        return $this === self::LaravelApp || $this === self::SymfonyApp;
    }

    /**
     * A generic Project holds no repository, so it has no branch, checkout or Instance (projects.md#generic-projects).
     */
    public function hasRepository(): bool
    {
        return $this !== self::Generic;
    }

    public function servesPhpByDefault(): bool
    {
        return $this->isWebServing();
    }

    /**
     * The checkout-relative framework entry point the source classifier inspects.
     */
    public function frameworkEntryPoint(): string
    {
        return $this === self::SymfonyApp ? 'bin/console' : 'artisan';
    }
}
