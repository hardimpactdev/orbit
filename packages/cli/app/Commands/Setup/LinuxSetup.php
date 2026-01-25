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

class LinuxSetup
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
        $this->initProgress(12);

        try {
            // Step 1: Detect system
            $this->stepStart('Detecting system');
            $os = $this->detectSystem();
            $this->progressInfo("Detected {$os}");
            $this->stepComplete('Detecting system');

            // Step 2: Check/Install Docker
            $this->stepStart('Checking Docker');
            if (! $skipDocker && ! $this->checkDocker($platformService)) {
                return false;
            } elseif ($skipDocker) {
                $this->progressInfo('Docker installation skipped');
            }
            $this->stepComplete('Checking Docker');

            // Step 3: Add Ondřej PPA
            $this->stepStart('Adding PHP PPA');
            if (! $this->addPhpPpa()) {
                return false;
            }
            $this->stepComplete('Adding PHP PPA');

            // Step 4: Install PHP-FPM
            $this->stepStart('Installing PHP-FPM');
            if (! $this->installPhpFpm($phpVersions)) {
                return false;
            }
            $this->stepComplete('Installing PHP-FPM');

            // Step 5: Install Caddy
            $this->stepStart('Installing Caddy');
            if (! $this->installCaddy($platformService)) {
                return false;
            }
            $this->stepComplete('Installing Caddy');

            // Step 6: Install support tools
            $this->stepStart('Installing support tools');
            if (! $this->installSupportTools($platformService)) {
                return false;
            }
            $this->stepComplete('Installing support tools');

            // Step 7: Create directories
            $this->stepStart('Creating directories');
            if (! $this->createDirectories($configManager)) {
                return false;
            }
            $this->stepComplete('Creating directories');

            // Step 8: Configure PHP-FPM
            $this->stepStart('Configuring PHP-FPM');
            if (! $this->configurePhpFpm($phpManager, $phpVersions, $configManager)) {
                return false;
            }
            $this->stepComplete('Configuring PHP-FPM');

            // Step 9: Configure Caddy
            $this->stepStart('Configuring Caddy');
            if (! $this->configureCaddy($configManager, $caddyfileGenerator, $tld)) {
                return false;
            }
            $this->stepComplete('Configuring Caddy');

            // Step 10: Start PHP-FPM
            $this->stepStart('Starting PHP-FPM');
            if (! $this->startPhpFpm($phpVersions)) {
                return false;
            }
            $this->stepComplete('Starting PHP-FPM');

            // Step 11: Start Caddy
            $this->stepStart('Starting Caddy');
            if (! $this->startCaddy()) {
                return false;
            }
            $this->stepComplete('Starting Caddy');

            // Step 12: Install Horizon
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

    protected function detectSystem(): string
    {
        $result = Process::run('cat /etc/os-release | grep PRETTY_NAME | cut -d= -f2 | tr -d "\""');

        return $result->successful() ? trim($result->output()) : 'Linux';
    }

    protected function checkDocker(PlatformService $platformService): bool
    {
        if ($platformService->hasDocker()) {
            $this->progressInfo('Docker is running');

            return true;
        }

        $this->progressInfo('Installing Docker...');

        $result = Process::timeout(600)->run(
            'curl -fsSL https://get.docker.com | sh && sudo usermod -aG docker $USER'
        );

        if (! $result->successful()) {
            $this->stepError('Checking Docker', 'Failed to install Docker');

            return false;
        }

        $this->progressInfo('Docker installed successfully');
        $this->progressInfo('Note: You may need to log out and back in for Docker group membership to take effect');

        return true;
    }

    protected function addPhpPpa(): bool
    {
        // Check if already added
        $checkResult = Process::run('grep -r "ondrej/php" /etc/apt/sources.list.d/ 2>&1');
        if ($checkResult->successful()) {
            $this->progressInfo('PHP PPA already added');

            return true;
        }

        $this->progressInfo('Adding Ondřej PHP PPA...');

        $result = Process::timeout(300)->run(
            'sudo apt-get update && sudo apt-get install -y software-properties-common && sudo add-apt-repository -y ppa:ondrej/php && sudo apt-get update'
        );

        if (! $result->successful()) {
            $this->stepError('Adding PHP PPA', 'Failed to add Ondřej PPA');

            return false;
        }

        $this->progressInfo('PHP PPA added');

        return true;
    }

    protected function installPhpFpm(array $versions): bool
    {
        foreach ($versions as $version) {
            $this->progressInfo("Installing PHP {$version} FPM...");

            // Check if already installed
            $checkResult = Process::run("dpkg -l | grep php{$version}-fpm 2>&1");
            if ($checkResult->successful()) {
                $this->progressInfo("PHP {$version} FPM already installed");

                continue;
            }

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
                $this->stepError('Installing PHP-FPM', "Failed to install PHP {$version}");

                return false;
            }

            $this->progressInfo("PHP {$version} FPM installed");
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

        $result = Process::timeout(300)->run(
            'sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https curl && '
            .'curl -1sLf "https://dl.cloudsmith.io/public/caddy/stable/gpg.key" | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg && '
            .'curl -1sLf "https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt" | sudo tee /etc/apt/sources.list.d/caddy-stable.list && '
            .'sudo apt update && sudo apt install -y caddy'
        );

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
            $result = Process::timeout(300)->run('curl -fsSL https://bun.sh/install | bash');
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
            $result = Process::timeout(300)->run(
                'php -r "copy(\"https://getcomposer.org/installer\", \"composer-setup.php\");" && '
                .'php composer-setup.php --install-dir=/usr/local/bin --filename=composer && '
                .'php -r "unlink(\"composer-setup.php\");"'
            );
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

        // Update systemd Caddyfile path
        $systemCaddyfile = '/etc/caddy/Caddyfile';
        $importLine = "import {$caddyfilePath}";

        $result = Process::run("echo '{$importLine}' | sudo tee {$systemCaddyfile}");
        if ($result->successful()) {
            $this->progressInfo('Configured Caddy to import Orbit config');
        }

        return true;
    }

    protected function startPhpFpm(array $versions): bool
    {
        foreach ($versions as $version) {
            $this->progressInfo("Starting PHP {$version} FPM...");

            $result = Process::run("sudo systemctl enable php{$version}-fpm && sudo systemctl start php{$version}-fpm");

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

        $result = Process::run('sudo systemctl enable caddy && sudo systemctl start caddy');

        if (! $result->successful()) {
            $this->stepError('Starting Caddy', 'Failed to start Caddy service');

            return false;
        }

        $this->progressInfo('Caddy started');

        return true;
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
