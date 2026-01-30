<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\CaddyfileGeneratorInterface;
use App\Services\CaddyfileGenerator;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load MCP routes for AI tool integration
        if (file_exists($aiRoutes = base_path('routes/ai.php'))) {
            require $aiRoutes;
        }
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind interface to concrete implementation for mockability
        $this->app->bind(CaddyfileGeneratorInterface::class, CaddyfileGenerator::class);

        // Register HTTP client factory
        $this->app->singleton(Factory::class, fn ($app) => new Factory);

        // Alias for facade
        $this->app->alias(Factory::class, 'http');

        // Manually load commands for PHAR compatibility
        if ($this->app->runningInConsole()) {
            // Use CommandRegistry if available (for PHAR builds)
            if (class_exists(\App\CommandRegistry::class)) {
                $this->commands(\App\CommandRegistry::getCommands());
            } else {
                // Fallback for development
                $this->commands($this->getCommandClasses());
            }
        }
    }

    /**
     * Get all command classes from the Commands directory.
     */
    protected function getCommandClasses(): array
    {
        $commands = [];
        $commandsPath = $this->app->basePath('app/Commands');

        if (is_dir($commandsPath)) {
            $files = glob($commandsPath.'/*.php');
            foreach ($files as $file) {
                $class = 'App\\Commands\\'.basename($file, '.php');
                if (class_exists($class) && is_subclass_of($class, \Illuminate\Console\Command::class)) {
                    $commands[] = $class;
                }
            }
        }

        return $commands;
    }
}
