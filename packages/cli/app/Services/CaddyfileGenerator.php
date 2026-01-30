<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CaddyfileGeneratorInterface;
use Illuminate\Support\Facades\File;

final readonly class CaddyfileGenerator implements CaddyfileGeneratorInterface
{
    protected string $caddyfilePath;

    public function __construct(
        protected ConfigManager $configManager,
        protected ProjectScanner $projectScanner,
        protected PhpManager $phpManager,
        protected WorktreeService $worktreeService,
        protected ServiceManager $serviceManager
    ) {
        $this->caddyfilePath = $this->configManager->getConfigPath().'/caddy/Caddyfile';
    }

    /**
     * Get the PHP-FPM socket path for a version.
     */
    protected function getSocketPath(string $version): string
    {
        return $this->phpManager->getSocketPath($version);
    }

    public function generate(): void
    {
        $this->generateCaddyfile();
    }

    protected function generateCaddyfile(): void
    {
        $projects = $this->projectScanner->scanProjects();
        $defaultPhp = $this->configManager->getDefaultPhpVersion();
        $tld = $this->configManager->get('tld') ?: 'test';
        $defaultSocket = $this->getSocketPath($defaultPhp);

        $caddyfile = '{
    local_certs
}

';

        // Add orbit management UI site
        $webAppPath = $this->configManager->getWebAppPath();
        if (is_dir($webAppPath)) {
            $caddyfile .= "orbit.{$tld} {
    tls internal
    root * {$webAppPath}/public
    encode gzip
    php_fastcgi unix/{$defaultSocket}
    file_server
}

";
        }

        // Generate entry for each project (skip orbit itself - it has its own entry above)
        $orbitDomain = "orbit.{$tld}";
        foreach ($projects as $project) {
            // Skip if this project would conflict with the orbit UI domain
            if (($project['domain'] ?? null) === $orbitDomain) {
                continue;
            }

            $socket = $project['has_custom_php']
                ? $this->getSocketPath($project['php_version'])
                : $defaultSocket;
            $root = $project['path'].'/public';

            $caddyfile .= "{$project['domain']} {
    tls internal
    root * {$root}
    encode gzip

    # Vite dev server proxy (header_up bypasses Vite allowedHosts bug)
    @vite path /@vite/* /@id/* /@fs/* /resources/* /node_modules/* /lang/* /__devtools__/*
    reverse_proxy @vite localhost:5173 {
        header_up Host localhost
    }

    @ws {
        header Connection *Upgrade*
        header Upgrade websocket
    }
    reverse_proxy @ws localhost:5173 {
        header_up Host localhost
    }

    php_fastcgi unix/{$socket}
    file_server
}

";
        }

        // Generate entries for worktrees
        $worktrees = $this->getWorktreesForCaddy();
        foreach ($worktrees as $worktree) {
            $socket = $this->getSocketPath($worktree['php_version']);
            $root = $worktree['path'].'/public';

            $caddyfile .= "{$worktree['domain']} {
    tls internal
    root * {$root}
    encode gzip
    php_fastcgi unix/{$socket}
    file_server
}

";
        }

        // Add Reverb WebSocket service if enabled
        if ($this->serviceManager->isEnabled('reverb')) {
            $caddyfile .= "reverb.{$tld} {
    tls internal
    @websocket {
        path /app /app/*
        header Connection *Upgrade*
        header Upgrade websocket
    }
    reverse_proxy @websocket localhost:8080
    reverse_proxy localhost:8080
}

";
        }

        File::put($this->caddyfilePath, $caddyfile);
    }

    public function reload(): bool
    {
        return $this->phpManager->getAdapter()->reloadCaddy();
    }

    public function reloadPhp(): bool
    {
        $defaultVersion = $this->configManager->get('default_php_version', '8.4');

        // Use graceful reload to avoid killing active connections
        return $this->phpManager->getAdapter()->reloadPhpFpm($defaultVersion);
    }

    protected function getWorktreesForCaddy(): array
    {
        try {
            return $this->worktreeService->getLinkedWorktreesForCaddy();
        } catch (\Exception) {
            return [];
        }
    }
}
