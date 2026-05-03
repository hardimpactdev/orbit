<?php

declare(strict_types=1);

namespace App\Services\Dns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class LocalResolver
{
    private ?string $overridePlatform = null;

    public function setPlatform(string $platform): void
    {
        $this->overridePlatform = $platform;
    }

    public function platform(): string
    {
        if ($this->overridePlatform !== null) {
            return $this->overridePlatform;
        }

        return match (PHP_OS_FAMILY) {
            'Darwin' => 'macos',
            default => 'unsupported',
        };
    }

    public function isSupported(): bool
    {
        return $this->platform() === 'macos';
    }

    public function backend(): string
    {
        return 'dnsmasq';
    }

    public function configDir(): string
    {
        return storage_path('app/orbit/dnsmasq.d');
    }

    public function isDnsmasqInstalled(): bool
    {
        $result = Process::timeout(10)->run('which dnsmasq');

        return $result->successful();
    }

    public function existingTarget(string $tld): ?string
    {
        $configPath = $this->configPath($tld);

        if (! File::exists($configPath)) {
            return null;
        }

        $content = File::get($configPath);

        if (preg_match('/address=\/\\.'.preg_quote($tld, '/').'\/(.+)/', $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * @return array{status: string, changed: bool, error?: string}
     */
    public function resolve(string $tld, string $target): array
    {
        $existing = $this->existingTarget($tld);

        if ($existing === $target) {
            return ['status' => 'already_resolved', 'changed' => false];
        }

        $configDir = $this->configDir();
        File::ensureDirectoryExists($configDir);

        $masterConfig = $this->masterConfigPath();
        $this->ensureConfDirLine($masterConfig, $configDir);

        File::put($this->configPath($tld), "address=/.{$tld}/{$target}\n");

        $resolverResult = Process::timeout(10)->run(
            "sudo mkdir -p /etc/resolver && echo 'nameserver 127.0.0.1' | sudo tee ".escapeshellarg("/etc/resolver/{$tld}").' > /dev/null'
        );

        if (! $resolverResult->successful()) {
            return ['status' => 'write_failed', 'changed' => false, 'error' => $resolverResult->errorOutput()];
        }

        $restartResult = Process::timeout(30)->run('brew services restart dnsmasq');

        if (! $restartResult->successful()) {
            return ['status' => 'refresh_failed', 'changed' => true, 'error' => $restartResult->errorOutput()];
        }

        return ['status' => 'resolved', 'changed' => true];
    }

    /**
     * @return array{status: string, changed: bool, error?: string}
     */
    public function reset(string $tld): array
    {
        $configPath = $this->configPath($tld);
        $hasConfig = File::exists($configPath);
        $hasResolver = Process::timeout(10)->run("test -f /etc/resolver/{$tld}")->successful();

        if (! $hasConfig && ! $hasResolver) {
            return ['status' => 'already_absent', 'changed' => false];
        }

        if ($hasConfig) {
            File::delete($configPath);
        }

        if ($hasResolver) {
            $removeResult = Process::timeout(10)->run('sudo rm '.escapeshellarg("/etc/resolver/{$tld}"));

            if (! $removeResult->successful()) {
                return ['status' => 'write_failed', 'changed' => true, 'error' => $removeResult->errorOutput()];
            }
        }

        $restartResult = Process::timeout(30)->run('brew services restart dnsmasq');

        if (! $restartResult->successful()) {
            return ['status' => 'refresh_failed', 'changed' => true, 'error' => $restartResult->errorOutput()];
        }

        return ['status' => 'reset', 'changed' => true];
    }

    private function configPath(string $tld): string
    {
        return $this->configDir()."/{$tld}.conf";
    }

    private function masterConfigPath(): string
    {
        $prefixResult = Process::timeout(10)->run('brew --prefix');
        $prefix = $prefixResult->successful() ? trim($prefixResult->output()) : '/opt/homebrew';

        return "{$prefix}/etc/dnsmasq.conf";
    }

    private function ensureConfDirLine(string $masterConfig, string $configDir): void
    {
        File::ensureDirectoryExists(dirname($masterConfig));

        $confDirLine = "conf-dir={$configDir}/,*.conf";

        if (File::exists($masterConfig)) {
            $contents = File::get($masterConfig);

            if (! str_contains($contents, $confDirLine)) {
                File::append($masterConfig, "\n{$confDirLine}\n");
            }
        } else {
            File::put($masterConfig, "{$confDirLine}\n");
        }
    }
}
