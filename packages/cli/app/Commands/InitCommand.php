<?php

declare(strict_types=1);

namespace App\Commands;

use App\Contracts\CaddyfileGeneratorInterface;
use App\Services\ConfigManager;
use App\Services\DockerManager;
use App\Services\PlatformService;
use App\Services\ServiceManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

final class InitCommand extends Command
{
    protected $signature = 'init
        {--yes : Skip all confirmations}
        {--skip-prerequisites : Skip prerequisite checks}';

    protected $description = 'First-time setup: creates config directory, pulls images, sets up DNS';

    protected bool $autoConfirm = false;

    public function handle(
        ServiceManager $serviceManager,
        ConfigManager $configManager,
        DockerManager $dockerManager,
        CaddyfileGeneratorInterface $caddyfileGenerator,
        PlatformService $platformService
    ): int {
        $this->warn('⚠️  The "init" command is deprecated and will be removed in a future version.');
        $this->line('   Please use "orbit install" instead.');
        $this->newLine();

        $this->autoConfirm = $this->option('yes');

        info('Initializing Orbit...');
        $this->newLine();

        // Check and install prerequisites
        if (! $this->option('skip-prerequisites')) {
            if (! $this->checkPrerequisites($platformService)) {
                return self::FAILURE;
            }
        }

        // Check if Docker is actually running (even if installed)
        if (! $platformService->hasDocker()) {
            $runtime = $platformService->getContainerRuntime() ?? 'Docker';
            $this->error("{$runtime} is installed but not running. Please start it first.");

            return self::FAILURE;
        }

        $configPath = $configManager->getConfigPath();
        $isFirstRun = ! File::exists("{$configPath}/config.json");

        if ($isFirstRun) {
            note('First-time setup detected');
        } else {
            note('Existing installation detected - updating configuration');
        }

        $this->newLine();

        // 1. Create directory structure
        $this->task('Creating directories', function () use ($configPath) {
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
                "{$configPath}/reverb",
                "{$configPath}/logs",
            ];

            foreach ($directories as $dir) {
                File::ensureDirectoryExists($dir);
            }

            return true;
        });

        // 2. Copy stubs
        $this->task('Copying configuration files', function () use ($configPath) {
            $stubsPath = base_path('stubs');

            // Copy all stub files
            $this->copyStubDirectory("{$stubsPath}/php", "{$configPath}/php");
            $this->copyStubDirectory("{$stubsPath}/caddy", "{$configPath}/caddy");
            $this->copyStubDirectory("{$stubsPath}/dns", "{$configPath}/dns");
            $this->copyStubDirectory("{$stubsPath}/postgres", "{$configPath}/postgres");
            $this->copyStubDirectory("{$stubsPath}/redis", "{$configPath}/redis");
            $this->copyStubDirectory("{$stubsPath}/mailpit", "{$configPath}/mailpit");
            $this->copyStubDirectory("{$stubsPath}/horizon", "{$configPath}/horizon");
            $this->copyStubDirectory("{$stubsPath}/reverb", "{$configPath}/reverb");

            // Copy config.json if it doesn't exist
            if (! File::exists("{$configPath}/config.json")) {
                File::copy("{$stubsPath}/config.json", "{$configPath}/config.json");
            }

            // Copy CLAUDE.md
            File::copy("{$stubsPath}/CLAUDE.md", "{$configPath}/CLAUDE.md");

            return true;
        });

        // 3. Install companion web app
        $this->task('Installing companion web app', fn () => $this->installWebApp($configManager));

        // 4. Generate initial Caddyfile
        $this->task('Generating Caddyfile', function () use ($caddyfileGenerator) {
            $caddyfileGenerator->generate();

            return true;
        });

        // 4.3. Generate dnsmasq.conf from DNS mappings
        $this->task('Generating dnsmasq configuration', function () use ($configManager) {
            $configManager->writeDnsmasqConf();

            return true;
        });

        // 4.5. Initialize services.yaml and generate docker-compose.yaml
        $this->task('Initializing service configuration', function () use ($serviceManager) {
            // ServiceManager will auto-create services.yaml from stub if missing
            $serviceManager->loadServices();

            // Generate docker-compose.yaml from services configuration
            return $serviceManager->regenerateCompose();
        });

        // 5. Create docker network
        $this->task('Creating Docker network', $dockerManager->createNetwork(...));

        // 6. Configure /etc/hosts for Redis
        $this->task('Configuring /etc/hosts', $this->configureHosts(...));

        // 7. Configure DNS for the host system
        $this->task('Configuring DNS', fn () => $this->configureDns($platformService, $configManager));

        // 8. Build DNS container
        $this->task('Building DNS container', function () use ($dockerManager) {
            $result = $dockerManager->build('dns');
            if (! $result && $dockerManager->getLastError()) {
                $this->output->write(" <fg=red>{$dockerManager->getLastError()}</>");
            }

            return $result;
        });

        // 9. Build PHP images (with Redis and other extensions)
        spin(
            fn () => $dockerManager->build('php'),
            'Building PHP images (this may take a while)...'
        );
        $this->line('  <fg=green>✓</> Building PHP images');

        // 10. Pull service images (in parallel-ish, at least show progress)
        $this->pullImages($dockerManager);

        // 11. Start all services
        $this->task('Starting services', function () use ($dockerManager) {
            $dockerManager->startAll();

            return true;
        });

        // 12. Install composer-link globally for package development
        $this->task('Installing composer-link plugin', function () {
            // Check if already installed
            $checkResult = Process::run('composer global show sandersander/composer-link 2>/dev/null');
            if ($checkResult->successful()) {
                return true;
            }

            $result = Process::run('composer global config --no-plugins allow-plugins.sandersander/composer-link true && composer global require sandersander/composer-link --quiet');

            return $result->successful();
        });

        $this->newLine();
        $this->showCompletionMessage($platformService, $configManager);

        return self::SUCCESS;
    }

    protected function checkPrerequisites(PlatformService $platformService): bool
    {
        $checks = $platformService->checkPrerequisites();
        $allPassed = true;
        $installable = [];

        $this->line('<fg=cyan>Checking prerequisites...</>');
        $this->newLine();

        foreach ($checks as $key => $check) {
            $optional = $check['optional'] ?? false;
            $status = $check['installed'];

            if ($status) {
                $version = $check['version'] ? " ({$check['version']})" : '';
                $this->line("  <fg=green>✓</> {$check['name']}{$version}");
            } else {
                $icon = $optional ? '<fg=yellow>○</>' : '<fg=red>✗</>';
                $this->line("  {$icon} {$check['name']} - {$check['required']}");

                if (! $optional) {
                    $allPassed = false;
                    if ($check['installable']) {
                        $installable[$key] = $check;
                    }
                }
            }
        }

        $this->newLine();

        // If something is missing and can be installed, offer to install
        if (! $allPassed && ! empty($installable)) {
            $shouldInstall = $this->autoConfirm || confirm(
                'Would you like to install missing prerequisites?',
                default: true
            );

            if ($shouldInstall) {
                foreach ($installable as $key => $check) {
                    $this->installPrerequisite($key, $platformService);
                }

                // Re-check after installation
                $checks = $platformService->checkPrerequisites();
                $allPassed = true;
                foreach ($checks as $check) {
                    if (! $check['installed'] && ! ($check['optional'] ?? false)) {
                        $allPassed = false;
                    }
                }
            }
        }

        if (! $allPassed) {
            $this->error('Some prerequisites are missing and could not be installed.');
            $this->line('Please install them manually and run init again.');

            return false;
        }

        return true;
    }

    protected function installPrerequisite(string $key, PlatformService $platformService): void
    {
        $success = match ($key) {
            'php' => spin(
                fn () => $platformService->installPhp('8.4'),
                'Installing PHP 8.4...'
            ),
            'docker' => spin(
                fn () => $platformService->installDocker(),
                'Installing Docker...'
            ),
            'composer' => spin(
                fn () => $platformService->installComposer(),
                'Installing Composer...'
            ),
            'dig' => spin(
                fn () => $platformService->installDig(),
                'Installing dig...'
            ),
            default => false,
        };

        if ($success) {
            $this->line("  <fg=green>✓</> Installed {$key}");
        } else {
            $this->line("  <fg=red>✗</> Failed to install {$key}");
        }
    }

    protected function configureDns(PlatformService $platformService, ConfigManager $configManager): bool
    {
        $tld = $configManager->getTld();
        $dnsStatus = $platformService->getDnsStatus($tld);

        // Orbit DNS is already running - perfect, nothing to do
        if (($dnsStatus['status'] ?? '') === 'orbit_dns_running') {
            return true;
        }

        // On macOS with existing dnsmasq for this TLD, we're good
        if (($dnsStatus['status'] ?? '') === 'dnsmasq_configured') {
            return true;
        }

        // On Linux with systemd-resolved conflict, configure it
        if ($platformService->isLinux() && ($dnsStatus['status'] ?? '') === 'systemd_resolved_conflict') {
            if (! $this->autoConfirm && ! confirm(
                'systemd-resolved is using port 53. Configure it to allow Docker DNS?',
                default: true
            )) {
                warning('DNS may not work correctly without this configuration.');

                return true; // Continue anyway
            }

            $result = $platformService->configureSystemdResolved();
            if (! $result) {
                warning('Failed to configure systemd-resolved. You may need to do this manually.');

                return true; // Continue anyway
            }

            // Also update resolv.conf
            $platformService->configureResolvConf();

            return true;
        }

        // Port 53 in use by something else (not orbit-dns)
        if (($dnsStatus['status'] ?? '') === 'port_53_conflict') {
            warning('Port 53 is in use by another process. Docker DNS may not work.');
            $this->line('  Please free port 53 or configure your system DNS to point to 127.0.0.1');

            return true; // Continue anyway
        }

        return true;
    }

    protected function pullImages(DockerManager $dockerManager): void
    {
        // Note: Caddy runs on the host, not in Docker
        $services = ['postgres', 'redis', 'mailpit'];

        foreach ($services as $service) {
            $this->task("Pulling {$service} image", function () use ($dockerManager, $service) {
                $result = $dockerManager->pull($service);
                if (! $result && $dockerManager->getLastError()) {
                    $this->output->write(" <fg=red>{$dockerManager->getLastError()}</>");
                }

                return $result;
            });
        }
    }

    protected function showCompletionMessage(PlatformService $platformService, ConfigManager $configManager): void
    {
        $tld = $configManager->getTld();
        $dnsStatus = $platformService->getDnsStatus($tld);

        // Check if DNS is already working (orbit-dns running or dnsmasq configured)
        $dnsReady = in_array($dnsStatus['status'] ?? '', ['orbit_dns_running', 'dnsmasq_configured', 'ready']);

        if (! $dnsReady) {
            if ($platformService->isMacOS()) {
                warning('Configure your DNS to point to 127.0.0.1:');
                $this->line('  System Settings → Network → Wi-Fi → Details → DNS');
                $this->line('  Or: sudo networksetup -setdnsservers Wi-Fi 127.0.0.1');
            } elseif ($platformService->isLinux()) {
                if ($platformService->isSystemdResolvedStubDisabled()) {
                    $this->line('<fg=green>DNS configured automatically via systemd-resolved.</>');
                } else {
                    warning('Ensure /etc/resolv.conf points to 127.0.0.1');
                    $this->line('  Or configure systemd-resolved manually.');
                }
            }
            $this->newLine();
        }

        info('Done! Run: orbit start');
        $this->line("  Your sites will be available at https://*.{$tld}");
    }

    protected function copyStubDirectory(string $source, string $destination): void
    {
        if (! File::isDirectory($source)) {
            return;
        }

        File::ensureDirectoryExists($destination);

        foreach (File::files($source) as $file) {
            $destPath = "{$destination}/{$file->getFilename()}";
            // Don't overwrite existing docker-compose.yml files
            if ($file->getFilename() === 'docker-compose.yml' && File::exists($destPath)) {
                continue;
            }
            File::copy($file->getPathname(), $destPath);
        }
    }

    protected function installWebApp(ConfigManager $configManager): bool
    {
        $sourcePath = base_path('web');
        $destPath = $configManager->getWebAppPath();

        // Check if source exists (in development or phar)
        if (! File::isDirectory($sourcePath)) {
            // Source not found - this might be a minimal CLI installation
            return true;
        }

        // Copy web app files (excluding vendor, node_modules, .env)
        $this->copyWebAppDirectory($sourcePath, $destPath);

        // Generate .env file
        $this->generateWebAppEnv($configManager);

        // Run composer install
        $result = Process::timeout(300)
            ->path($destPath)
            ->run('composer install --no-dev --no-interaction --optimize-autoloader');

        if (! $result->successful()) {
            return false;
        }

        // Run migrations
        $migrateResult = Process::timeout(60)
            ->path($destPath)
            ->run('php artisan migrate --force');

        if (! $migrateResult->successful()) {
            return false;
        }

        // Ensure SQLite database exists before seeding
        $dbPath = "{$destPath}/database.sqlite";
        if (! File::exists($dbPath)) {
            File::put($dbPath, '');
        }

        // Seed local environment using the orbit:init command
        // Use --name with hostname to avoid interactive prompt
        $hostname = gethostname() ?: 'Local';
        $seedResult = Process::timeout(60)
            ->path($destPath)
            ->run("php artisan orbit:init --name=\"{$hostname}\"");

        return $seedResult->successful();
    }

    protected function copyWebAppDirectory(string $source, string $destination): void
    {
        $excludeDirs = ['vendor', 'node_modules', '.git', 'storage/logs', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views'];
        $excludeFiles = ['.env'];

        File::ensureDirectoryExists($destination);

        // Copy files recursively, excluding specified paths
        $this->recursiveCopy($source, $destination, $excludeDirs, $excludeFiles);

        // Ensure storage directories exist with proper permissions
        $storageDirs = [
            "{$destination}/storage/app",
            "{$destination}/storage/framework/cache",
            "{$destination}/storage/framework/sessions",
            "{$destination}/storage/framework/views",
            "{$destination}/storage/logs",
            "{$destination}/bootstrap/cache",
        ];

        foreach ($storageDirs as $dir) {
            File::ensureDirectoryExists($dir);
            chmod($dir, 0775);
        }
    }

    /**
     * @param  array<int, string>  $excludeDirs
     * @param  array<int, string>  $excludeFiles
     */
    protected function recursiveCopy(string $source, string $destination, array $excludeDirs, array $excludeFiles, string $relativePath = ''): void
    {
        $items = File::files($source);
        $directories = File::directories($source);

        // Copy files
        foreach ($items as $file) {
            $filename = $file->getFilename();
            if (in_array($filename, $excludeFiles)) {
                continue;
            }
            File::copy($file->getPathname(), "{$destination}/{$filename}");
        }

        // Copy directories recursively
        foreach ($directories as $dir) {
            $dirname = basename((string) $dir);
            $newRelativePath = $relativePath ? "{$relativePath}/{$dirname}" : $dirname;

            // Skip excluded directories
            $skip = false;
            foreach ($excludeDirs as $excludeDir) {
                if ($dirname === $excludeDir || str_starts_with($newRelativePath, (string) $excludeDir)) {
                    $skip = true;
                    break;
                }
            }

            if ($skip) {
                continue;
            }

            $newDest = "{$destination}/{$dirname}";
            File::ensureDirectoryExists($newDest);
            $this->recursiveCopy($dir, $newDest, $excludeDirs, $excludeFiles, $newRelativePath);
        }
    }

    protected function generateWebAppEnv(ConfigManager $configManager): void
    {
        $webAppPath = $configManager->getWebAppPath();
        $tld = $configManager->getTld();
        $reverbConfig = $configManager->getReverbConfig();

        // Generate a random app key
        $appKey = 'base64:'.base64_encode(random_bytes(32));

        $env = <<<ENV
APP_NAME=Orbit
APP_ENV=production
APP_KEY={$appKey}
APP_DEBUG=false
APP_URL=https://orbit.{$tld}
ORBIT_MODE=web

LOG_CHANNEL=single
LOG_LEVEL=error

# Database for environments/sites
DB_CONNECTION=sqlite
DB_DATABASE={$webAppPath}/database.sqlite

# Redis for everything
REDIS_CLIENT=phpredis
REDIS_HOST=orbit-redis
REDIS_PASSWORD=null
REDIS_PORT=6379

# Queue via Redis
QUEUE_CONNECTION=redis

# Let Horizon track failed jobs in Redis
QUEUE_FAILED_DRIVER=null

# Cache and sessions via Redis
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120

# Broadcasting via Reverb
BROADCAST_CONNECTION=reverb

REVERB_APP_ID={$reverbConfig['app_id']}
REVERB_APP_KEY={$reverbConfig['app_key']}
REVERB_APP_SECRET={$reverbConfig['app_secret']}
REVERB_HOST={$reverbConfig['host']}
REVERB_PORT={$reverbConfig['port']}
REVERB_SCHEME=https
ENV;

        File::put("{$webAppPath}/.env", $env);
    }

    protected function configureHosts(): bool
    {
        $hostsEntry = '127.0.0.1 orbit-redis';
        $hostsFile = '/etc/hosts';

        // Check if entry already exists
        $result = Process::run("grep -q 'orbit-redis' {$hostsFile}");
        if ($result->successful()) {
            // Entry already exists
            return true;
        }

        // Add the entry using sudo
        $result = Process::run("echo '{$hostsEntry}' | sudo tee -a {$hostsFile} > /dev/null");

        return $result->successful();
    }
}
