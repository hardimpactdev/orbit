<?php

namespace App\Commands;

use Illuminate\Support\Facades\Artisan;
use LaravelZero\Framework\Commands\Command;

class DbMigrateCommand extends Command
{
    protected $signature = 'db:migrate 
        {--status : Show migration status only}
        {--fresh : Drop all tables and re-run all migrations}
        {--seed : Seed the database after migrations}';

    protected $description = 'Run database migrations for Orbit';

    public function handle(): int
    {
        if ($this->option('status')) {
            $this->info('Migration status:');

            return Artisan::call('migrate:status', [], $this->output);
        }

        if ($this->option('fresh')) {
            $this->warn('Dropping all tables and re-running migrations...');

            return Artisan::call('migrate:fresh', [
                '--force' => true,
                '--seed' => $this->option('seed'),
            ], $this->output);
        }

        // First run legacy schema migration (projects -> sites table)
        Artisan::call('schema:migrate', [], $this->output);

        $this->info('Running migrations...');

        $result = Artisan::call('migrate', ['--force' => true], $this->output);

        if ($result === 0 && $this->option('seed')) {
            Artisan::call('db:seed', ['--force' => true], $this->output);
        }

        return $result;
    }
}
