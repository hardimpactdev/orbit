<?php

declare(strict_types=1);

namespace App\Actions\Install\Mac;

use App\Data\Install\InstallContext;
use App\Data\Provision\StepResult;
use App\Services\Install\InstallLogger;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

final readonly class ConfigureDns
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        $tld = $context->tld;
        $resolverFile = "/etc/resolver/{$tld}";

        // Check if already configured
        if (File::exists($resolverFile)) {
            $content = File::get($resolverFile);
            if (str_contains($content, 'nameserver 127.0.0.1')) {
                $logger->skip("DNS resolver for .{$tld} already configured");

                return StepResult::success();
            }
        }

        // Detect Herd conflict
        if ($tld === 'test' && $this->isHerdInstalled()) {
            $logger->warn('Laravel Herd detected using .test TLD');
            $logger->info('Consider using --tld=lp to avoid conflicts');
        }

        // Create resolver (requires sudo)
        $logger->step('Creating DNS resolver (sudo required)...');

        $result = Process::run("sudo mkdir -p /etc/resolver && echo 'nameserver 127.0.0.1' | sudo tee {$resolverFile}");

        if (! $result->successful()) {
            return StepResult::failed('Failed to create DNS resolver file');
        }

        $logger->success("DNS resolver for .{$tld} configured");

        return StepResult::success();
    }

    private function isHerdInstalled(): bool
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';
        $herdPaths = [
            "{$home}/.config/herd",
            "{$home}/Library/Application Support/Herd",
        ];

        foreach ($herdPaths as $path) {
            if (is_dir($path)) {
                return true;
            }
        }

        return Process::run('command -v herd')->successful();
    }
}
