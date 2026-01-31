<?php

declare(strict_types=1);

namespace App\Actions\Install\Linux;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class InstallPhp
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // First, ensure the Ondřej PPA is added
        if (! $this->addPhpPpa($logger)) {
            return StepResult::failed('Failed to add PHP PPA');
        }

        // Install each PHP version
        foreach ($context->phpVersions as $version) {
            if ($this->isPhpVersionInstalled($version)) {
                $logger->skip("PHP {$version} already installed");

                continue;
            }

            $logger->step("Installing PHP {$version}...");

            // Common PHP extensions for Laravel
            $extensions = [
                "php{$version}-fpm",
                "php{$version}-cli",
                "php{$version}-common",
                "php{$version}-mysql",
                "php{$version}-pgsql",
                "php{$version}-zip",
                "php{$version}-gd",
                "php{$version}-mbstring",
                "php{$version}-curl",
                "php{$version}-xml",
                "php{$version}-bcmath",
                "php{$version}-redis",
            ];

            $packages = implode(' ', $extensions);
            $result = Process::timeout(600)->run("sudo apt-get install -y {$packages}");

            if (! $result->successful()) {
                return StepResult::failed("Failed to install PHP {$version}: ".$result->errorOutput());
            }

            $logger->success("PHP {$version} installed");
        }

        return StepResult::success();
    }

    private function addPhpPpa(InstallLogger $logger): bool
    {
        // Check if already added
        $checkResult = Process::run('grep -r "ondrej/php" /etc/apt/sources.list.d/ 2>&1');
        if ($checkResult->successful()) {
            return true;
        }

        $logger->step('Adding Ondřej PHP PPA...');

        $result = Process::timeout(300)->run(
            'sudo apt-get update && sudo apt-get install -y software-properties-common && sudo add-apt-repository -y ppa:ondrej/php && sudo apt-get update'
        );

        return $result->successful();
    }

    private function isPhpVersionInstalled(string $version): bool
    {
        $result = Process::run("dpkg -l | grep php{$version}-fpm 2>&1");

        return $result->successful();
    }
}
