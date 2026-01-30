<?php
declare(strict_types=1);

namespace App\Commands;

use App\Commands\Setup\LinuxSetup;
use App\Commands\Setup\MacSetup;
use App\Services\CaddyfileGenerator;
use App\Services\CaddyManager;
use App\Services\ConfigManager;
use App\Services\DockerManager;
use App\Services\HorizonManager;
use App\Services\PhpManager;
use App\Services\PlatformService;
use LaravelZero\Framework\Commands\Command;

class SetupCommand extends Command
{
    protected $signature = 'setup
        {--tld=test : TLD for local development sites}
        {--php-versions=8.4,8.5 : PHP versions to install (comma-separated)}
        {--skip-docker : Skip Docker/OrbStack installation}
        {--json : Output progress as JSON for programmatic consumption}';

    protected $description = 'Set up Orbit on this machine (auto-detects Mac/Linux)';

    public function handle(
        ConfigManager $configManager,
        DockerManager $dockerManager,
        CaddyfileGenerator $caddyfileGenerator,
        CaddyManager $caddyManager,
        HorizonManager $horizonManager,
        PhpManager $phpManager,
        PlatformService $platformService
    ): int {
        $this->warn('⚠️  The "setup" command is deprecated and will be removed in a future version.');
        $this->line('   Please use "orbit install" instead.');
        $this->newLine();

        $platform = PHP_OS_FAMILY;

        // Parse options
        $tld = $this->option('tld');
        $phpVersions = explode(',', $this->option('php-versions'));
        $skipDocker = $this->option('skip-docker') === true;
        $jsonOutput = $this->option('json') === true;

        // Clean up version numbers (remove dots if needed)
        $phpVersions = array_map(fn ($version) => $this->normalizePhpVersion(trim((string) $version)), $phpVersions);

        if ($platform === 'Darwin') {
            return $this->setupMac(
                $tld,
                $phpVersions,
                $skipDocker,
                $jsonOutput,
                $configManager,
                $dockerManager,
                $caddyfileGenerator,
                $caddyManager,
                $horizonManager,
                $phpManager,
                $platformService
            );
        } elseif ($platform === 'Linux') {
            return $this->setupLinux(
                $tld,
                $phpVersions,
                $skipDocker,
                $jsonOutput,
                $configManager,
                $dockerManager,
                $caddyfileGenerator,
                $caddyManager,
                $horizonManager,
                $phpManager,
                $platformService
            );
        } else {
            if ($jsonOutput) {
                $this->output->writeln(json_encode([
                    'type' => 'complete',
                    'success' => false,
                    'error' => "Unsupported platform: {$platform}",
                ]));
            } else {
                $this->error("Unsupported platform: {$platform}");
            }

            return self::FAILURE;
        }
    }

    protected function setupMac(
        string $tld,
        array $phpVersions,
        bool $skipDocker,
        bool $jsonOutput,
        ConfigManager $configManager,
        DockerManager $dockerManager,
        CaddyfileGenerator $caddyfileGenerator,
        CaddyManager $caddyManager,
        HorizonManager $horizonManager,
        PhpManager $phpManager,
        PlatformService $platformService
    ): int {
        $setup = new MacSetup;
        $setup->setOutput($this->output);

        $success = $setup->run(
            $tld,
            $phpVersions,
            $skipDocker,
            $jsonOutput,
            $configManager,
            $dockerManager,
            $caddyfileGenerator,
            $caddyManager,
            $horizonManager,
            $phpManager,
            $platformService
        );

        return $success ? self::SUCCESS : self::FAILURE;
    }

    protected function setupLinux(
        string $tld,
        array $phpVersions,
        bool $skipDocker,
        bool $jsonOutput,
        ConfigManager $configManager,
        DockerManager $dockerManager,
        CaddyfileGenerator $caddyfileGenerator,
        CaddyManager $caddyManager,
        HorizonManager $horizonManager,
        PhpManager $phpManager,
        PlatformService $platformService
    ): int {
        $setup = new LinuxSetup;
        $setup->setOutput($this->output);

        $success = $setup->run(
            $tld,
            $phpVersions,
            $skipDocker,
            $jsonOutput,
            $configManager,
            $dockerManager,
            $caddyfileGenerator,
            $caddyManager,
            $horizonManager,
            $phpManager,
            $platformService
        );

        return $success ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Normalize PHP version to X.Y format (e.g., "84" -> "8.4", "8.4" -> "8.4")
     */
    protected function normalizePhpVersion(string $version): string
    {
        // Remove php@ prefix if present
        $version = str_replace(['php@', 'php'], '', $version);

        // If no dot, add one (84 -> 8.4)
        if (! str_contains($version, '.')) {
            $version = substr($version, 0, 1).'.'.substr($version, 1);
        }

        return $version;
    }
}
