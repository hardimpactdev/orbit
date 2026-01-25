<?php

namespace App\Commands\Setup;

use App\Services\CaddyfileGenerator;
use App\Services\CaddyManager;
use App\Services\ConfigManager;
use App\Services\DockerManager;
use App\Services\HorizonManager;
use App\Services\PhpManager;
use App\Services\PlatformService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Output\OutputInterface;

class MacSetup
{
    use SetupProgress;

    protected OutputInterface $output;

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function run(
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
    ): bool {
        $this->jsonOutput = $jsonOutput;
        $this->initProgress(15);

        try {
            // Step 1: Detect system
            $this->stepStart('Detecting system');
            $os = $this->detectSystem($platformService);
            $this->progressInfo("Detected {$os}");
            $this->stepComplete('Detecting system');

            // Step 2: Check/Install Homebrew
            $this->stepStart('Checking Homebrew');
            if (! $this->checkHomebrew($platformService)) {
                return false;
            }
            $this->stepComplete('Checking Homebrew');

            // Step 3: Check/Install OrbStack (if not skipped)
            $this->stepStart('Checking OrbStack');
            if (! $skipDocker && ! $this->checkOrbStack($platformService)) {
                return false;
            } elseif ($skipDocker) {
                $this->progressInfo('Docker installation skipped');
            }
            $this->stepComplete('Checking OrbStack');

            // Step 4: Add PHP tap
            $this->stepStart('Adding PHP tap');
            if (! $this->addPhpTap()) {
                return false;
            }
            $this->stepComplete('Adding PHP tap');

            // Step 5: Install PHP versions
            $this->stepStart('Installing PHP versions');
            if (! $this->installPhpVersions($phpVersions)) {
                return false;
            }
            $this->stepComplete('Installing PHP versions');

            // Step 6: Install Caddy
            $this->stepStart('Installing Caddy');
            if (! $this->installCaddy($platformService)) {
                return false;
            }
            $this->stepComplete('Installing Caddy');

            // Step 7: Install support tools
            $this->stepStart('Installing support tools');
            if (! $this->installSupportTools($platformService)) {
                return false;
            }
            $this->stepComplete('Installing support tools');

            // Step 8: Create directories
            $this->stepStart('Creating directories');
            if (! $this->createDirectories($configManager)) {
                return false;
            }
            $this->stepComplete('Creating directories');

            // Step 9: Configure PHP-FPM pools
            $this->stepStart('Configuring PHP-FPM');
            if (! $this->configurePhpFpm($phpManager, $phpVersions, $configManager)) {
                return false;
            }
            $this->stepComplete('Configuring PHP-FPM');

            // Step 10: Configure Caddy
            $this->stepStart('Configuring Caddy');
            if (! $this->configureCaddy($configManager, $caddyfileGenerator, $tld)) {
                return false;
            }
            $this->stepComplete('Configuring Caddy');

            // Step 11: Configure DNS resolver
            $this->stepStart('Configuring DNS resolver');
            if (! $this->configureDns($tld)) {
                return false;
            }
            $this->stepComplete('Configuring DNS resolver');

            // Step 12: Start PHP-FPM
            $this->stepStart('Starting PHP-FPM');
            if (! $this->startPhpFpm($phpVersions)) {
                return false;
            }
            $this->stepComplete('Starting PHP-FPM');

            // Step 13: Start Caddy
            $this->stepStart('Starting Caddy');
            if (! $this->startCaddy()) {
                return false;
            }
            $this->stepComplete('Starting Caddy');

            // Step 14: Init Docker services
            $this->stepStart('Initializing Docker services');
            if (! $skipDocker && ! $this->initDocker($dockerManager, $configManager, $tld)) {
                return false;
            } elseif ($skipDocker) {
                $this->progressInfo('Docker services skipped');
            }
            $this->stepComplete('Initializing Docker services');

            // Step 15: Install Horizon
            $this->stepStart('Installing Horizon');
            if (! $this->installHorizon($horizonManager, $configManager)) {
                return false;
            }
            $this->stepComplete('Installing Horizon');

            $this->setupComplete();

            return true;

        } catch (\Exception $e) {
            $this->setupFailed($e->getMessage());

            return false;
        }
    }

    protected function detectSystem(PlatformService $platformService): string
    {
        $arch = php_uname('m');
        $version = $platformService->getCommandOutput('sw_vers -productVersion');

        return "macOS {$version} ({$arch})";
    }

    protected function checkHomebrew(PlatformService $platformService): bool
    {
        if ($platformService->commandExists('brew')) {
            $brewPath = $platformService->getCommandOutput('command -v brew');
            $this->progressInfo("Homebrew already installed at {$brewPath}");

            return true;
        }

        $this->progressInfo('Installing Homebrew...');
        $result = Process::timeout(600)->run(
            '/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"'
        );

        if (! $result->successful()) {
            $this->stepError('Checking Homebrew', 'Failed to install Homebrew');

            return false;
        }

        $this->progressInfo('Homebrew installed successfully');

        return true;
    }

    protected function checkOrbStack(PlatformService $platformService): bool
    {
        // Check if Docker is already available
        if ($platformService->hasDocker()) {
            $runtime = $platformService->hasOrbStack() ? 'OrbStack' : 'Docker';
            $this->progressInfo("{$runtime} is running");

            return true;
        }

        // Install OrbStack
        $this->progressInfo('Installing OrbStack...');
        $result = Process::timeout(300)->run('brew install --cask orbstack');

        if (! $result->successful()) {
            $this->stepError('Checking OrbStack', 'Failed to install OrbStack');

            return false;
        }

        // Open OrbStack to complete setup
        Process::run('open -a OrbStack');
        $this->progressInfo('OrbStack installed - waiting for initialization...');

        // Poll for readiness
        for ($i = 0; $i < 12; $i++) {
            sleep(5);
            // @phpstan-ignore-next-line - hasDocker() changes after OrbStack installation
            if ($platformService->hasDocker()) {
                $this->progressInfo('OrbStack ready');

                return true;
            }
        }

        $this->stepError('Checking OrbStack', 'OrbStack not ready after 60 seconds');

        return false;
    }

    protected function addPhpTap(): bool
    {
        $result = Process::run('brew tap shivammathur/php 2>&1');

        if (! $result->successful() && ! str_contains($result->output(), 'already tapped')) {
            $this->stepError('Adding PHP tap', 'Failed to add shivammathur/php tap');

            return false;
        }

        $this->progressInfo('PHP tap added');

        return true;
    }

    protected function installPhpVersions(array $versions): bool
    {
        foreach ($versions as $version) {
            $this->progressInfo("Installing PHP {$version}...");

            // Check if already installed
            $checkResult = Process::run("brew list shivammathur/php/php@{$version} 2>&1");
            if ($checkResult->successful()) {
                $this->progressInfo("PHP {$version} already installed");

                continue;
            }

            $result = Process::timeout(600)->run("brew install shivammathur/php/php@{$version}");

            if (! $result->successful()) {
                $this->stepError('Installing PHP versions', "Failed to install PHP {$version}");

                return false;
            }

            $this->progressInfo("PHP {$version} installed");
        }

        return true;
    }

    protected function installCaddy(PlatformService $platformService): bool
    {
        if ($platformService->commandExists('caddy')) {
            $this->progressInfo('Caddy already installed');

            return true;
        }

        $this->progressInfo('Installing Caddy...');
        $result = Process::timeout(300)->run('brew install caddy');

        if (! $result->successful()) {
            $this->stepError('Installing Caddy', 'Failed to install Caddy');

            return false;
        }

        $this->progressInfo('Caddy installed');

        return true;
    }

    protected function installSupportTools(PlatformService $platformService): bool
    {
        // Install Bun if missing
        if (! $platformService->commandExists('bun')) {
            $this->progressInfo('Installing Bun...');
            $result = Process::timeout(300)->run('brew install oven-sh/bun/bun');
            if (! $result->successful()) {
                $this->progressInfo('Warning: Failed to install Bun');
            } else {
                $this->progressInfo('Bun installed');
            }
        } else {
            $this->progressInfo('Bun already installed');
        }

        // Install Composer if missing
        if (! $platformService->commandExists('composer')) {
            $this->progressInfo('Installing Composer...');
            $result = Process::timeout(300)->run('brew install composer');
            if (! $result->successful()) {
                $this->progressInfo('Warning: Failed to install Composer');
            } else {
                $this->progressInfo('Composer installed');
            }
        } else {
            $this->progressInfo('Composer already installed');
        }

        return true;
    }

    protected function createDirectories(ConfigManager $configManager): bool
    {
        $configPath = $configManager->getConfigPath();
        $projectsPath = $_SERVER['HOME'].'/projects';

        $directories = [
            $configPath,
            "{$configPath}/php",
            "{$configPath}/caddy",
            "{$configPath}/dns",
            "{$configPath}/postgres",
            "{$configPath}/postgres/data",
            "{$configPath}/redis",
            "{$configPath}/redis/data",
            "{$configPath}/mailpit",
            "{$configPath}/horizon",
            "{$configPath}/logs",
            $projectsPath,
        ];

        foreach ($directories as $dir) {
            if (! File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true);
                $this->progressInfo("Created {$dir}");
            }
        }

        return true;
    }

    protected function configurePhpFpm(PhpManager $phpManager, array $versions, ConfigManager $configManager): bool
    {
        foreach ($versions as $version) {
            try {
                // Configure pool (creates config file and manages linking)
                $phpManager->configurePool($version);
                $this->progressInfo("Configured FPM pool for PHP {$version}");
            } catch (\Exception $e) {
                $this->stepError('Configuring PHP-FPM', "Failed to configure PHP {$version}: {$e->getMessage()}");

                return false;
            }
        }

        return true;
    }

    protected function configureCaddy(ConfigManager $configManager, CaddyfileGenerator $generator, string $tld): bool
    {
        $configPath = $configManager->getConfigPath();
        $caddyfilePath = "{$configPath}/caddy/Caddyfile";

        // Save TLD to config
        $configManager->set('tld', $tld);

        // Generate initial Caddyfile (writes file internally)
        $generator->generate();
        $this->progressInfo("Created Caddyfile at {$caddyfilePath}");

        // Create Caddy global config that imports our Caddyfile
        $globalConfigDir = '/opt/homebrew/etc';
        $globalCaddyfile = "{$globalConfigDir}/Caddyfile";

        if (! File::exists($globalCaddyfile) || ! str_contains(File::get($globalCaddyfile), $caddyfilePath)) {
            $importLine = "import {$caddyfilePath}";
            File::put($globalCaddyfile, $importLine.PHP_EOL);
            $this->progressInfo('Configured Caddy to import Orbit config');
        }

        return true;
    }

    protected function configureDns(string $tld): bool
    {
        $resolverFile = "/etc/resolver/{$tld}";

        // Check if already configured
        if (File::exists($resolverFile)) {
            $content = File::get($resolverFile);
            if (str_contains($content, 'nameserver 127.0.0.1')) {
                $this->progressInfo("DNS resolver for .{$tld} already configured");

                return true;
            }
        }

        // Detect Herd conflict
        if ($tld === 'test' && $this->isHerdInstalled()) {
            $this->progressInfo('Warning: Laravel Herd detected using .test TLD');
            $this->progressInfo('Consider using --tld=lp to avoid conflicts');
        }

        // Create resolver (requires sudo)
        $this->progressInfo('Creating DNS resolver (sudo required)...');

        $result = Process::run("sudo mkdir -p /etc/resolver && echo 'nameserver 127.0.0.1' | sudo tee {$resolverFile}");

        if (! $result->successful()) {
            $this->stepError('Configuring DNS resolver', 'Failed to create DNS resolver file');

            return false;
        }

        $this->progressInfo("DNS resolver for .{$tld} configured");

        return true;
    }

    protected function isHerdInstalled(): bool
    {
        $herdPaths = [
            $_SERVER['HOME'].'/.config/herd',
            $_SERVER['HOME'].'/Library/Application Support/Herd',
        ];

        foreach ($herdPaths as $path) {
            if (is_dir($path)) {
                return true;
            }
        }

        return Process::run('command -v herd')->successful();
    }

    protected function startPhpFpm(array $versions): bool
    {
        foreach ($versions as $version) {
            $this->progressInfo("Starting PHP {$version} FPM...");

            $result = Process::run("brew services start shivammathur/php/php@{$version}");

            if (! $result->successful()) {
                $this->progressInfo("Warning: Failed to start PHP {$version} FPM");
            } else {
                $this->progressInfo("PHP {$version} FPM started");
            }
        }

        return true;
    }

    protected function startCaddy(): bool
    {
        $this->progressInfo('Starting Caddy...');

        $result = Process::run('brew services start caddy');

        if (! $result->successful()) {
            $this->stepError('Starting Caddy', 'Failed to start Caddy service');

            return false;
        }

        $this->progressInfo('Caddy started');

        return true;
    }

    protected function initDocker(DockerManager $dockerManager, ConfigManager $configManager, string $tld): bool
    {
        try {
            // Create network
            $this->progressInfo('Creating Docker network...');
            $dockerManager->createNetwork();

            // Start containers
            $this->progressInfo('Starting Docker containers...');
            $dockerManager->startAll();

            $this->progressInfo('Docker services started');

            return true;
        } catch (\Exception $e) {
            $this->stepError('Initializing Docker services', $e->getMessage());

            return false;
        }
    }

    protected function installHorizon(HorizonManager $horizonManager, ConfigManager $configManager): bool
    {
        // Check if web app is installed
        $webPath = $configManager->getWebAppPath();
        if (! File::isDirectory($webPath)) {
            $this->progressInfo('Web app not installed - skipping Horizon');

            return true;
        }

        // Check if already installed
        if ($horizonManager->isInstalled()) {
            $this->progressInfo('Horizon already installed');

            return true;
        }

        try {
            $this->progressInfo('Installing Horizon service...');

            if (! $horizonManager->install()) {
                $this->stepError('Installing Horizon', 'Failed to install Horizon service');

                return false;
            }

            // Start the service
            if ($horizonManager->start()) {
                $this->progressInfo('Horizon service started');
            } else {
                $this->progressInfo('Warning: Horizon installed but failed to start');
            }

            return true;
        } catch (\Exception $e) {
            $this->stepError('Installing Horizon', $e->getMessage());

            return false;
        }
    }
}
