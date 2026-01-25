<?php

declare(strict_types=1);

namespace App\Commands;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLinuxPipeline;
use App\Services\Install\InstallLogger;
use App\Services\Install\InstallMacPipeline;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

final class InstallCommand extends Command
{
    protected $signature = 'install
        {--tld=test : Top-level domain for local sites}
        {--php-versions=8.4,8.5 : PHP versions to install (comma-separated)}
        {--skip-docker : Skip Docker/OrbStack installation}
        {--skip-trust : Skip SSL certificate trust}
        {--yes : Non-interactive mode}';

    protected $description = 'Install Orbit and configure your development environment';

    private const MIN_PHP_VERSION = '8.4.0';

    public function handle(): int
    {
        // Validate prerequisites installed by bootstrap
        if (! $this->validatePrerequisites()) {
            return self::FAILURE;
        }

        $context = InstallContext::fromOptions($this->options());
        $logger = new InstallLogger($this);

        $platform = PHP_OS_FAMILY === 'Darwin' ? 'macOS' : 'Linux';

        $logger->title('Installing Orbit');
        $logger->info("Platform: {$platform}");
        $logger->info("TLD: .{$context->tld}");
        $logger->info('PHP versions: '.implode(', ', $context->phpVersions));
        $logger->newLine();

        // Select platform-specific pipeline
        $pipeline = PHP_OS_FAMILY === 'Darwin'
            ? app(InstallMacPipeline::class)
            : app(InstallLinuxPipeline::class);

        $result = $pipeline->run($context, $logger);

        if ($result->isFailed()) {
            $logger->newLine();
            $logger->error('Installation failed: '.$result->error);

            return self::FAILURE;
        }

        $logger->newLine();
        $logger->success('Orbit installed successfully!');
        $logger->newLine();
        $logger->info("Dashboard: https://orbit.{$context->tld}");
        $logger->info('Create a project: orbit project:create myapp');

        return self::SUCCESS;
    }

    private function validatePrerequisites(): bool
    {
        // Check PHP version
        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
            $this->error('PHP '.self::MIN_PHP_VERSION.'+ is required. Current version: '.PHP_VERSION);
            $this->line('');
            $this->line('Run the bootstrap installer to install prerequisites:');
            $this->info('  curl -fsSL https://raw.githubusercontent.com/hardimpactdev/orbit-cli/main/install.sh | bash');

            return false;
        }

        // Check Composer
        $composerCheck = Process::run('composer --version');
        if (! $composerCheck->successful()) {
            $this->error('Composer is required but not found.');
            $this->line('');
            $this->line('Run the bootstrap installer to install prerequisites:');
            $this->info('  curl -fsSL https://raw.githubusercontent.com/hardimpactdev/orbit-cli/main/install.sh | bash');

            return false;
        }

        return true;
    }
}
