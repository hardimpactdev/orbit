<?php

namespace App\Commands\Service;

use App\Concerns\WithJsonOutput;
use App\Services\ServiceManager;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class ServiceEnableCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'service:enable 
                            {service : Service name to enable}
                            {--json : Output as JSON}';

    protected $description = 'Enable a service';

    public function handle(ServiceManager $serviceManager): int
    {
        $serviceName = $this->argument('service');

        try {
            $success = $serviceManager->enable($serviceName);

            if (! $success) {
                return $this->wantsJson()
                    ? $this->outputJsonError("Failed to enable service: {$serviceName}")
                    : $this->handleError("Failed to enable service: {$serviceName}");
            }

            // Regenerate docker-compose.yaml to reflect changes
            $serviceManager->regenerateCompose();

            // Check for service-specific dependencies
            $this->checkServiceDependencies($serviceName);

            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'service' => $serviceName,
                    'enabled' => true,
                    'message' => "Service {$serviceName} has been enabled",
                ]);
            }

            $this->newLine();
            $this->info("  Service '{$serviceName}' has been enabled");
            $this->line("  <fg=gray>Run 'orbit start {$serviceName}' to start the service</>");
            $this->newLine();

            return self::SUCCESS;

        } catch (RuntimeException $e) {
            return $this->wantsJson()
                ? $this->outputJsonError($e->getMessage())
                : $this->handleError($e->getMessage());
        }
    }

    /**
     * Check and install service-specific host dependencies.
     */
    protected function checkServiceDependencies(string $serviceName): void
    {
        if ($serviceName === 'mysql' && PHP_OS_FAMILY === 'Darwin') {
            $this->checkMysqlClient();
        }
    }

    /**
     * Check if mysql-client is installed and offer to install it.
     */
    protected function checkMysqlClient(): void
    {
        // Check if mysql command exists
        exec('which mysql 2>/dev/null', $output, $exitCode);
        
        if ($exitCode !== 0) {
            if ($this->wantsJson()) {
                return;
            }

            $this->newLine();
            $this->warn('  ⚠ MySQL client not found on your system.');
            $this->line('  <fg=gray>Laravel needs the mysql CLI to load schema dumps.</>');
            
            if ($this->confirm('  Install mysql-client via Homebrew?', true)) {
                $this->line('  Installing mysql-client...');
                
                exec('brew install mysql-client 2>&1', $brewOutput, $brewExit);
                
                if ($brewExit === 0) {
                    $this->info('  ✓ mysql-client installed');
                    $this->newLine();
                    $this->warn('  Add to your shell PATH:');
                    $this->line('  echo \'export PATH="/opt/homebrew/opt/mysql-client/bin:$PATH"\' >> ~/.zshrc');
                    $this->line('  source ~/.zshrc');
                } else {
                    $this->error('  Failed to install mysql-client');
                }
            }
        }
    }

    protected function handleError(string $message): int
    {
        $this->newLine();
        $this->error("  {$message}");
        $this->newLine();

        return self::FAILURE;
    }
}
