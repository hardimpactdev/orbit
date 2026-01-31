<?php

declare(strict_types=1);

namespace App\Actions\Install\Mac;

use App\Data\Install\InstallContext;
use HardImpact\Orbit\Core\Data\StepResult;
use App\Services\Install\InstallLogger;
use Illuminate\Support\Facades\Process;

final readonly class InstallPhp
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // First, ensure the PHP tap is added
        if (! $this->addPhpTap($logger)) {
            return StepResult::failed('Failed to add PHP tap');
        }

        // Install each PHP version
        foreach ($context->phpVersions as $version) {
            if ($this->isPhpVersionInstalled($version)) {
                $logger->skip("PHP {$version} already installed");

                continue;
            }

            $logger->step("Installing PHP {$version}...");

            $result = Process::timeout(600)->run("brew install shivammathur/php/php@{$version}");

            if (! $result->successful()) {
                return StepResult::failed("Failed to install PHP {$version}: ".$result->errorOutput());
            }

            $logger->success("PHP {$version} installed");
        }

        return StepResult::success();
    }

    private function addPhpTap(InstallLogger $logger): bool
    {
        $result = Process::run('brew tap shivammathur/php 2>&1');

        if (! $result->successful() && ! str_contains($result->output(), 'already tapped')) {
            return false;
        }

        return true;
    }

    private function isPhpVersionInstalled(string $version): bool
    {
        $result = Process::run("brew list shivammathur/php/php@{$version} 2>&1");

        return $result->successful();
    }
}
