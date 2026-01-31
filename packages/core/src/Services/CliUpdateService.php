<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CliUpdateService
{
    protected string $bundledPath;

    protected string $userPath;

    protected string $downloadUrl = 'https://github.com/nckrtl/orbit-cli/releases/latest/download/orbit.phar';

    public function __construct()
    {
        // Bundled CLI path (included with the app)
        $this->bundledPath = base_path('bin/orbit.phar');

        // User-installed CLI path (for updates)
        $home = getenv('HOME');
        if ($home === false) {
            $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? posix_getpwuid(posix_getuid())['dir'] ?? '/tmp';
        }
        $this->userPath = $home.'/.local/bin/orbit';
    }

    public function isInstalled(): bool
    {
        return file_exists($this->bundledPath) || file_exists($this->userPath);
    }

    public function getPharPath(): string
    {
        // Prefer bundled CLI, fall back to user-installed
        if (file_exists($this->bundledPath)) {
            return $this->bundledPath;
        }

        return $this->userPath;
    }

    public function getStatus(): array
    {
        $path = $this->getPharPath();
        $isBundled = file_exists($this->bundledPath);

        return [
            'installed' => $this->isInstalled(),
            'path' => $path,
            'bundled' => $isBundled,
            'version' => $this->isInstalled() ? $this->getVersion() : null,
        ];
    }

    protected function getVersion(): ?string
    {
        if (! $this->isInstalled()) {
            return null;
        }

        $path = $this->getPharPath();
        $phpBinary = PHP_BINARY;
        $output = shell_exec("{$phpBinary} {$path} --version 2>/dev/null");

        return $output ? trim($output) : null;
    }

    public function install(): array
    {
        // If bundled CLI exists, we're already installed
        if (file_exists($this->bundledPath)) {
            return [
                'success' => true,
                'already_installed' => true,
                'path' => $this->bundledPath,
                'version' => $this->getVersion(),
            ];
        }

        try {
            // Ensure directory exists for user path
            $dir = dirname($this->userPath);
            if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
                return [
                    'success' => false,
                    'error' => "Failed to create directory: {$dir}",
                ];
            }

            // Download the phar file
            Log::info("Downloading Orbit CLI from {$this->downloadUrl}");

            $response = Http::withOptions([
                'allow_redirects' => true,
                'timeout' => 60,
            ])->get($this->downloadUrl);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => "Failed to download CLI: HTTP {$response->status()}",
                ];
            }

            // Write the file
            if (file_put_contents($this->userPath, $response->body()) === false) {
                return [
                    'success' => false,
                    'error' => "Failed to write CLI to {$this->userPath}",
                ];
            }

            // Make it executable
            if (! chmod($this->userPath, 0755)) {
                return [
                    'success' => false,
                    'error' => 'Failed to make CLI executable',
                ];
            }

            Log::info("Orbit CLI installed successfully at {$this->userPath}");

            return [
                'success' => true,
                'path' => $this->userPath,
                'version' => $this->getVersion(),
            ];
        } catch (\Exception $e) {
            Log::error("Failed to install Orbit CLI: {$e->getMessage()}");

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function ensureInstalled(): array
    {
        if ($this->isInstalled()) {
            return [
                'success' => true,
                'already_installed' => true,
                'path' => $this->getPharPath(),
                'version' => $this->getVersion(),
            ];
        }

        return $this->install();
    }

    public function checkAndUpdate(): array
    {
        if (! $this->isInstalled()) {
            return $this->install();
        }

        // For now, just reinstall to get the latest version
        // TODO: Compare versions before downloading
        return $this->install();
    }
}
