<?php

namespace App\Services;

class CliUpdateService
{
    protected string $pharPath;

    public function __construct()
    {
        $home = getenv('HOME');
        if ($home === false) {
            $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? posix_getpwuid(posix_getuid())['dir'] ?? '/tmp';
        }
        $this->pharPath = $home . '/.local/bin/launchpad';
    }

    public function isInstalled(): bool
    {
        return file_exists($this->pharPath);
    }

    public function getPharPath(): string
    {
        return $this->pharPath;
    }

    public function getStatus(): array
    {
        return [
            'installed' => $this->isInstalled(),
            'path' => $this->pharPath,
            'version' => $this->isInstalled() ? $this->getVersion() : null,
        ];
    }

    protected function getVersion(): ?string
    {
        if (!$this->isInstalled()) {
            return null;
        }

        $output = shell_exec("php {$this->pharPath} --version 2>/dev/null");
        return $output ? trim($output) : null;
    }

    public function checkAndUpdate(): array
    {
        return [
            'success' => true,
            'message' => 'CLI update not yet implemented',
        ];
    }
}
