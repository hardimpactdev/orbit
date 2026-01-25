<?php

namespace App\Commands;

use App\Services\DockerManager;
use LaravelZero\Framework\Commands\Command;

class LogsCommand extends Command
{
    protected $signature = 'logs
        {container : The container name to show logs for (e.g., orbit-php-83, orbit-caddy)}
        {--no-follow : Do not follow log output}';

    protected $description = 'Tail container logs';

    public function handle(DockerManager $dockerManager): int
    {
        $container = $this->argument('container');
        $follow = ! $this->option('no-follow');

        $this->info("Showing logs for {$container}...");
        $this->line('Press Ctrl+C to stop');
        $this->newLine();

        $dockerManager->logs($container, $follow);

        return self::SUCCESS;
    }
}
