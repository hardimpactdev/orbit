<?php

declare(strict_types=1);

namespace App\Actions\Install\Mac;

use App\Data\Install\InstallContext;
use HardImpact\Orbit\Core\Data\StepResult;
use App\Services\Install\InstallLogger;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

final readonly class ConfigurePhpFpm
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Configure PHP-FPM for each installed version
        foreach ($context->phpVersions as $version) {
            if (! $this->isPhpVersionInstalled($version)) {
                $logger->skip("PHP {$version} not installed, skipping FPM configuration");

                continue;
            }

            $logger->step("Configuring PHP-FPM for PHP {$version}...");

            // Disable default www.conf pool to prevent port 9000 conflict
            if (! $this->disableDefaultPool($version, $logger)) {
                return StepResult::failed("Failed to disable default www.conf pool for PHP {$version}");
            }

            // Create socket directory
            $socketDir = "{$context->configDir}/php";
            if (! File::isDirectory($socketDir)) {
                if (! File::makeDirectory($socketDir, 0755, true)) {
                    return StepResult::failed("Failed to create socket directory: {$socketDir}");
                }
            }

            // Create Orbit pool configuration
            if (! $this->createOrbitPool($version, $context, $logger)) {
                return StepResult::failed("Failed to create Orbit pool configuration for PHP {$version}");
            }

            // Validate configuration
            if (! $this->validateConfiguration($version, $logger)) {
                return StepResult::failed("PHP-FPM configuration validation failed for PHP {$version}");
            }

            $logger->success("PHP-FPM configured for PHP {$version}");
        }

        return StepResult::success();
    }

    private function isPhpVersionInstalled(string $version): bool
    {
        // Check for shivammathur tap formula first
        $result = Process::run("brew list shivammathur/php/php@{$version} 2>&1");
        if ($result->successful()) {
            return true;
        }

        // Fall back to checking for homebrew-core formula (php@8.4 or php for latest)
        // PHP 8.5 is currently the default 'php' formula in homebrew-core
        if ($version === '8.5') {
            $result = Process::run('brew list php 2>&1');
        } else {
            $result = Process::run("brew list php@{$version} 2>&1");
        }

        return $result->successful();
    }

    private function disableDefaultPool(string $version, InstallLogger $logger): bool
    {
        $poolDir = "/opt/homebrew/etc/php/{$version}/php-fpm.d";
        $wwwConf = "{$poolDir}/www.conf";
        $wwwConfDisabled = "{$poolDir}/www.conf.disabled";

        // Check if www.conf exists and needs to be disabled
        if (File::exists($wwwConf)) {
            $logger->step("Disabling default www.conf pool for PHP {$version}...");

            if (! File::move($wwwConf, $wwwConfDisabled)) {
                return false;
            }
        } elseif (File::exists($wwwConfDisabled)) {
            $logger->skip("Default www.conf pool already disabled for PHP {$version}");
        }

        return true;
    }

    private function createOrbitPool(string $version, InstallContext $context, InstallLogger $logger): bool
    {
        $poolDir = "/opt/homebrew/etc/php/{$version}/php-fpm.d";
        $poolConfigPath = "{$poolDir}/orbit.conf";
        // Use normalized version (no dot) for socket path to match MacAdapter::getSocketPath()
        $normalizedVersion = str_replace('.', '', $version);
        $socketPath = "{$context->configDir}/php/php{$normalizedVersion}.sock";
        $logPath = "{$context->configDir}/logs/php{$normalizedVersion}-fpm.log";

        // Ensure log directory exists
        $logDir = dirname($logPath);
        if (! File::isDirectory($logDir)) {
            File::makeDirectory($logDir, 0755, true);
        }

        // Load stub template
        $stubPath = base_path('stubs/php-fpm-pool.conf.stub');
        if (! File::exists($stubPath)) {
            return false;
        }

        $stub = File::get($stubPath);

        // Get current user and group
        $user = trim(Process::run('whoami')->output());
        $group = trim(Process::run('id -gn')->output());
        $home = $context->homeDir;
        $envPath = trim(Process::run('echo $PATH')->output());

        // Replace placeholders
        $config = str_replace([
            'ORBIT_PHP_VERSION',
            'ORBIT_USER',
            'ORBIT_GROUP',
            'ORBIT_SOCKET_PATH',
            'ORBIT_LOG_PATH',
            'ORBIT_ENV_PATH',
            'ORBIT_HOME',
        ], [
            $version,
            $user,
            $group,
            $socketPath,
            $logPath,
            $envPath,
            $home,
        ], $stub);

        // Write pool configuration
        return File::put($poolConfigPath, $config) !== false;
    }

    private function validateConfiguration(string $version, InstallLogger $logger): bool
    {
        $logger->step("Validating PHP-FPM configuration for PHP {$version}...");

        // Get the correct php-fpm binary path for this version
        // PHP 8.5 uses /opt/homebrew/opt/php/sbin/php-fpm
        // Older versions use /opt/homebrew/opt/php@X.Y/sbin/php-fpm
        $formula = $version === '8.5' ? 'php' : "php@{$version}";
        $fpmBinary = "/opt/homebrew/opt/{$formula}/sbin/php-fpm";

        // Test configuration syntax
        $result = Process::run("{$fpmBinary} -t 2>&1");

        if (! $result->successful()) {
            $logger->error('PHP-FPM configuration test failed: '.$result->errorOutput());

            return false;
        }

        return true;
    }
}
