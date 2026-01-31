<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

final class PackageLinkedCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'package:linked
        {app : The app project name to check}
        {--json : Output as JSON}';

    protected $description = 'List all linked packages for an app';

    public function handle(): int
    {
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

        // Run composer linked to get list
        $result = Process::path($appPath)->run('composer linked');

        // Parse the output to extract linked packages
        $output = trim($result->output());
        $linkedPackages = [];

        if (! empty($output) && $result->successful()) {
            // composer-link outputs in format: package/name -> /path/to/package
            $lines = explode("\n", $output);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                // Parse "vendor/package -> /path/to/source"
                if (preg_match('/^([\w\-\/]+)\s*->\s*(.+)$/', $line, $matches)) {
                    $linkedPackages[] = [
                        'name' => $matches[1],
                        'path' => $matches[2],
                    ];
                }
            }
        }

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'app' => $app,
                'linked_packages' => $linkedPackages,
            ]);
        }

        if (empty($linkedPackages)) {
            $this->info("No linked packages for '$app'");
        } else {
            $this->info("Linked packages for '$app':");
            foreach ($linkedPackages as $pkg) {
                $this->line("  - {$pkg['name']} -> {$pkg['path']}");
            }
        }

        return self::SUCCESS;
    }
}
