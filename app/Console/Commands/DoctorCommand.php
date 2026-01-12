<?php

namespace App\Console\Commands;

use App\Services\MacPhpFpmService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class DoctorCommand extends Command
{
    protected $signature = 'doctor';

    protected $description = 'Check prerequisites for PHP-FPM migration';

    public function handle(MacPhpFpmService $phpFpmService): int
    {
        $this->info('🩺 Checking PHP-FPM migration prerequisites...');
        $this->newLine();

        $allPassed = true;

        // 1. Check Homebrew
        $this->line('Checking Homebrew...');
        $brewCheck = Process::run('command -v brew');
        if ($brewCheck->successful()) {
            $this->line('  <fg=green>✓</> Homebrew is installed');
        } else {
            $this->line('  <fg=red>✗</> Homebrew is not installed');
            $this->line('    <fg=gray>Install from: https://brew.sh</>');
            $allPassed = false;
        }

        // 2. Check PHP versions (8.3, 8.4, 8.5 from shivammathur/php)
        $this->line('Checking PHP versions...');
        $versions = $phpFpmService->detectInstalledPhpVersions();
        $requiredVersions = ['8.3', '8.4', '8.5'];
        $missingVersions = [];

        foreach ($requiredVersions as $version) {
            if (in_array($version, $versions)) {
                $this->line("  <fg=green>✓</> PHP {$version} is installed");
            } else {
                $this->line("  <fg=red>✗</> PHP {$version} is not installed");
                $missingVersions[] = $version;
            }
        }

        if (! empty($missingVersions)) {
            $allPassed = false;
            $this->line('    <fg=gray>Install missing versions:</>');
            $this->line('    <fg=gray>brew tap shivammathur/php</>');
            foreach ($missingVersions as $version) {
                $this->line("    <fg=gray>brew install shivammathur/php/php@{$version}</>");
            }
        }

        // 3. Check Caddy
        $this->line('Checking Caddy...');
        $caddyCheck = Process::run('command -v caddy');
        if ($caddyCheck->successful()) {
            $versionCheck = Process::run('caddy version');
            $parts = explode(' ', $versionCheck->output());
            $version = trim($parts[0]);
            $this->line("  <fg=green>✓</> Caddy is installed ({$version})");
        } else {
            $this->line('  <fg=red>✗</> Caddy is not installed');
            $this->line('    <fg=gray>Install with: brew install caddy</>');
            $allPassed = false;
        }

        // 4. Check Docker
        $this->line('Checking Docker...');
        $dockerCheck = Process::run('docker info 2>/dev/null');
        if ($dockerCheck->successful()) {
            $this->line('  <fg=green>✓</> Docker is running');
        } else {
            $this->line('  <fg=red>✗</> Docker is not running');
            $this->line('    <fg=gray>Start Docker Desktop</>');
            $allPassed = false;
        }

        // Summary
        $this->newLine();
        if ($allPassed) {
            $this->info('✅ All prerequisites met! You can run: php artisan migrate:to-fpm');

            return Command::SUCCESS;
        } else {
            $this->error('❌ Some prerequisites are missing. Please install them and run this command again.');

            return Command::FAILURE;
        }
    }
}
