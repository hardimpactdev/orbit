<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class CaddyfileGenerator
{
    protected string $configPath;

    protected string $caddyfilePath;

    protected string $homeDir;

    protected MacPhpFpmService $phpFpmService;

    public function __construct(MacPhpFpmService $phpFpmService)
    {
        $home = getenv('HOME');
        $this->homeDir = is_string($home) && $home !== '' ? $home : '/Users';
        $this->configPath = $this->homeDir.'/.config/launchpad';
        $this->caddyfilePath = $this->configPath.'/caddy/Caddyfile';
        $this->phpFpmService = $phpFpmService;
    }

    /**
     * Generate a host Caddyfile for PHP-FPM architecture (macOS).
     * Uses php_fastcgi with unix sockets instead of reverse_proxy to containers.
     *
     * @param  array<string, array{domain: string, path: string, php_version: string}>  $sites
     */
    public function generateHostCaddyfile(array $sites, string $defaultPhpVersion = '8.4', string $tld = 'test'): string
    {
        $caddyfile = "{\n";
        $caddyfile .= "    # Global options\n";
        $caddyfile .= "}\n\n";

        // Add launchpad management UI site
        $webAppPath = $this->configPath.'/web';
        if (is_dir($webAppPath)) {
            $socketPath = $this->phpFpmService->getSocketPath($defaultPhpVersion);
            $caddyfile .= $this->generateSiteBlock(
                "launchpad.{$tld}",
                $webAppPath.'/public',
                $socketPath
            );
        }

        // Generate entry for each site
        foreach ($sites as $site) {
            $phpVersion = $site['php_version'];
            $socketPath = $this->phpFpmService->getSocketPath($phpVersion);
            $root = $this->getDocumentRoot($site['path']);

            $caddyfile .= $this->generateSiteBlock($site['domain'], $root, $socketPath);
        }

        return $caddyfile;
    }

    /**
     * Generate a site block for the Caddyfile.
     */
    protected function generateSiteBlock(string $domain, string $root, string $socketPath): string
    {
        $block = "{$domain} {\n";
        $block .= "    tls internal\n";
        $block .= "    root * {$root}\n\n";

        // Vite dev server proxy rules
        $block .= "    @vite path /@vite/* /@id/* /@fs/* /resources/* /node_modules/* /lang/* /__devtools__/*\n";
        $block .= "    reverse_proxy @vite 127.0.0.1:5173\n\n";

        $block .= "    @ws {\n";
        $block .= "        header Connection *Upgrade*\n";
        $block .= "        header Upgrade websocket\n";
        $block .= "    }\n";
        $block .= "    reverse_proxy @ws 127.0.0.1:5173\n\n";

        // PHP-FPM via unix socket
        $block .= "    php_fastcgi unix/{$socketPath}\n";
        $block .= "    file_server\n";
        $block .= "}\n\n";

        return $block;
    }

    /**
     * Write the generated Caddyfile to disk.
     */
    public function writeHostCaddyfile(string $content): bool
    {
        $dir = dirname($this->caddyfilePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($this->caddyfilePath, $content) !== false;
    }

    /**
     * Setup the system Caddyfile at /opt/homebrew/etc/caddy/Caddyfile
     * to import the launchpad Caddyfile.
     */
    public function setupSystemCaddyfile(): bool
    {
        $systemCaddyfile = '/opt/homebrew/etc/caddy/Caddyfile';
        $importLine = "import {$this->caddyfilePath}";

        // Check if the import already exists
        if (file_exists($systemCaddyfile)) {
            $content = file_get_contents($systemCaddyfile);
            if ($content !== false && str_contains($content, $importLine)) {
                return true;
            }
        }

        // Create or append to system Caddyfile
        $content = "# Launchpad Caddyfile import\n";
        $content .= "{$importLine}\n";

        return file_put_contents($systemCaddyfile, $content) !== false;
    }

    /**
     * Reload Caddy using brew services (for macOS host Caddy).
     */
    public function reload(): bool
    {
        $result = Process::run('brew services reload caddy');

        return $result->successful();
    }

    /**
     * Get the document root for a site path.
     */
    protected function getDocumentRoot(string $basePath): string
    {
        // Laravel apps have a public directory
        $publicPath = $basePath.'/public';
        if (is_dir($publicPath)) {
            return $publicPath;
        }

        return $basePath;
    }

    /**
     * Get the path to the generated Caddyfile.
     */
    public function getCaddyfilePath(): string
    {
        return $this->caddyfilePath;
    }
}
