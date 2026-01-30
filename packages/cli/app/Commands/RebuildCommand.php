<?php
declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\ConfigManager;
use App\Services\DockerManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class RebuildCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'rebuild {--json : Output as JSON}';

    protected $description = 'Rebuild PHP images with latest extensions (Redis, etc.)';

    public function handle(ConfigManager $configManager, DockerManager $dockerManager): int
    {
        // Check if Docker is running
        $dockerCheck = Process::run('docker info');
        if (! $dockerCheck->successful()) {
            if ($this->wantsJson()) {
                return $this->outputJsonError('Docker is not running', ExitCode::DockerNotRunning->value);
            }
            $this->error('Docker is not running. Please start Docker/OrbStack first.');

            return ExitCode::DockerNotRunning->value;
        }

        $configPath = $configManager->getConfigPath();
        $phpPath = "{$configPath}/php";

        // Check if Dockerfiles exist, copy them if not
        if (! File::exists("{$phpPath}/Dockerfile.php83") || ! File::exists("{$phpPath}/Dockerfile.php84")) {
            $stubsPath = base_path('stubs/php');

            if ($this->wantsJson()) {
                // Copy silently in JSON mode
            } else {
                $this->info('Copying Dockerfiles...');
            }

            if (File::exists("{$stubsPath}/Dockerfile.php83")) {
                File::copy("{$stubsPath}/Dockerfile.php83", "{$phpPath}/Dockerfile.php83");
            }
            if (File::exists("{$stubsPath}/Dockerfile.php84")) {
                File::copy("{$stubsPath}/Dockerfile.php84", "{$phpPath}/Dockerfile.php84");
            }
            if (File::exists("{$stubsPath}/docker-compose.yml")) {
                File::copy("{$stubsPath}/docker-compose.yml", "{$phpPath}/docker-compose.yml");
            }
        }

        // Stop PHP containers first
        if (! $this->wantsJson()) {
            $this->task('Stopping PHP containers', fn () => $dockerManager->stop('php'));
        } else {
            $dockerManager->stop('php');
        }

        // Build PHP images
        if (! $this->wantsJson()) {
            $this->task('Building PHP images (this may take a while)', function () use ($dockerManager) {
                $result = $dockerManager->build('php');
                if (! $result && $dockerManager->getLastError()) {
                    $this->output->write(" <fg=red>{$dockerManager->getLastError()}</>");
                }

                return $result;
            });
        } else {
            $result = $dockerManager->build('php');
            if (! $result) {
                return $this->outputJsonError(
                    $dockerManager->getLastError() ?? 'Failed to build PHP images',
                    ExitCode::ServiceFailed->value
                );
            }
        }

        // Start PHP containers
        if (! $this->wantsJson()) {
            $this->task('Starting PHP containers', fn () => $dockerManager->start('php'));

            $this->newLine();
            $this->info('PHP images rebuilt with Redis and other extensions!');

            return self::SUCCESS;
        }

        $dockerManager->start('php');

        return $this->outputJsonSuccess([
            'message' => 'PHP images rebuilt successfully',
            'extensions' => ['redis', 'pcntl', 'intl', 'exif', 'gd', 'zip', 'bcmath'],
        ]);
    }
}
