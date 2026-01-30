<?php
declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->ensureDatabaseExists();
    }

    protected function ensureDatabaseExists(): void
    {
        $dbPath = config('database.connections.sqlite.database');

        if (! $dbPath || $dbPath === ':memory:') {
            return;
        }

        $configDir = dirname((string) $dbPath);

        if (! is_dir($configDir)) {
            @mkdir($configDir, 0755, true);
        }

        if (! file_exists($dbPath)) {
            @touch($dbPath);
        }
    }
}
