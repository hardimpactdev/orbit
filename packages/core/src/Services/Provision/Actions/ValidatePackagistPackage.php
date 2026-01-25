<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Provision\Actions;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Http;

final readonly class ValidatePackagistPackage
{
    public function handle(string $package, ProvisionLoggerContract $logger): StepResult
    {
        $logger->info("Validating Packagist package: {$package}");

        // Validate package format (vendor/name)
        if (! preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9]([_.-]?[a-z0-9]+)*$/i', $package)) {
            return StepResult::failed(
                "Invalid package format '{$package}'. Expected format: vendor/package"
            );
        }

        try {
            $response = Http::timeout(10)->get("https://packagist.org/packages/{$package}.json");

            if ($response->successful()) {
                $logger->info("Package '{$package}' found on Packagist");

                return StepResult::success(['package' => $package]);
            }

            return StepResult::failed(
                "Package '{$package}' not found on Packagist. ".
                'Did you mean --clone or --fork to use a GitHub repository?'
            );
        } catch (\Exception $e) {
            return StepResult::failed(
                "Failed to validate package on Packagist: {$e->getMessage()}"
            );
        }
    }
}
