<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Boost\Mcp\Resources\ApplicationInfo;
use Laravel\Boost\Support\PackageRegistry;
use Laravel\Mcp\Response;
use Laravel\Roster\Package;

final class LaravelZeroApplicationInfo extends ApplicationInfo
{
    #[\Override]
    public function handle(): Response
    {
        return Response::json([
            'php_version' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
            'laravel_version' => app()->version(),
            'database_engine' => null,
            'packages' => $this->project->php()->packages()
                ->concat($this->project->js()->packages())
                ->map(fn (Package $package): array => [
                    'roster_name' => PackageRegistry::rosterName($package->name()),
                    'version' => $package->version(),
                    'package_name' => $package->name(),
                ]),
        ]);
    }
}
