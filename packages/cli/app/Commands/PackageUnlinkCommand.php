<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

final class PackageUnlinkCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'package:unlink
        {package : The package name to unlink}
        {app : The app project name to unlink it from}
        {--json : Output as JSON}';

    protected $description = 'Unlink a local package from an app';

    public function handle(): int
    {
        $package = $this->argument('package');
        $app = $this->argument('app');

        $projectsDir = getenv('HOME').'/projects';
        $appPath = "$projectsDir/$app";

        // Validate app path exists
        if (! File::isDirectory($appPath)) {
            if ($this->wantsJson()) {
                return $this->outputJsonError("App '$app' not found at $appPath");
            }
            $this->error("App '$app' not found at $appPath");

            return self::FAILURE;
        }

        // Run composer unlink
        $result = Process::path($appPath)->run("composer unlink $package");

        if (! $result->successful()) {
            if ($this->wantsJson()) {
                return $this->outputJsonError('Failed to unlink package: '.$result->errorOutput());
            }
            $this->error('Failed to unlink package:');
            $this->line($result->errorOutput());

            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'message' => "Package '$package' unlinked from '$app'",
                'package' => $package,
                'app' => $app,
            ]);
        }

        $this->info("Package '$package' unlinked from '$app'");
        $this->line($result->output());

        return self::SUCCESS;
    }
}
