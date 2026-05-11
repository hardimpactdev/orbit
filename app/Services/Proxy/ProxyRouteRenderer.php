<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Models\ProxyRoute;
use RuntimeException;

final readonly class ProxyRouteRenderer
{
    public function render(ProxyRoute $route): string
    {
        return match ($route->kind) {
            'app', 'workspace' => $this->renderPhpFastCgi($route),
            'proxy' => $this->renderProxy($route),
            'redirect' => $this->renderRedirect($route),
            default => throw new RuntimeException("Proxy route kind '{$route->kind}' is not renderable by the custom proxy route renderer."),
        };
    }

    public function sourceHash(ProxyRoute $route): string
    {
        return hash('sha256', $this->render($route));
    }

    private function renderProxy(ProxyRoute $route): string
    {
        $config = is_array($route->config) ? $route->config : [];
        $upstream = $config['target']['value'] ?? $config['upstream'] ?? $config['target'] ?? null;

        if (! is_string($upstream) || $upstream === '') {
            throw new RuntimeException("Proxy route '{$route->domain}' is missing an upstream target.");
        }

        $tls = $this->tlsDirective($route);

        return <<<CADDY
{$route->domain} {
    {$tls}
    reverse_proxy {$upstream}
}

CADDY;
    }

    private function renderRedirect(ProxyRoute $route): string
    {
        $config = is_array($route->config) ? $route->config : [];
        $target = $config['target']['value'] ?? $config['redirect'] ?? $config['redirect_url'] ?? null;
        $code = (int) ($config['code'] ?? $config['redirect_code'] ?? 302);

        if (! is_string($target) || $target === '') {
            throw new RuntimeException("Proxy route '{$route->domain}' is missing a redirect target.");
        }

        $tls = $this->tlsDirective($route);

        return <<<CADDY
{$route->domain} {
    {$tls}
    redir {$target}{uri} {$code}
}

CADDY;
    }

    private function renderPhpFastCgi(ProxyRoute $route): string
    {
        $route->loadMissing('app');

        $config = is_array($route->config) ? $route->config : [];
        $documentRoot = $config['document_root'] ?? null;
        $phpSocket = $config['php_socket'] ?? null;
        $tls = $config['tls'] ?? 'internal';

        if (! is_string($documentRoot) || $documentRoot === '') {
            throw new RuntimeException("Proxy route '{$route->domain}' is missing a document root.");
        }

        if (! is_string($phpSocket) || $phpSocket === '') {
            throw new RuntimeException("Proxy route '{$route->domain}' is missing a PHP socket.");
        }

        if (! is_string($tls) || $tls === '') {
            throw new RuntimeException("Proxy route '{$route->domain}' is missing TLS intent.");
        }

        $pathBlocking = $route->app?->document_root === '.'
            ? 'import path_blocking_project_root'
            : 'import path_blocking_public_root';

        return <<<CADDY
{$route->domain} {
    tls {$tls}
    root * {$documentRoot}
    encode gzip

    import security_headers
    import profiling_headers
    {$pathBlocking}
    import security_txt
    import cache_headers
    php_fastcgi unix/{$phpSocket}
    file_server
}

CADDY;
    }

    private function tlsDirective(ProxyRoute $route): string
    {
        $paths = $this->tlsPaths($route);

        return "tls {$paths['cert']} {$paths['key']}";
    }

    /**
     * @return array{cert: string, key: string}
     */
    private function tlsPaths(ProxyRoute $route): array
    {
        $config = is_array($route->config) ? $route->config : [];
        $cert = $config['tls']['cert_path'] ?? null;
        $key = $config['tls']['key_path'] ?? null;

        return [
            'cert' => is_string($cert) && $cert !== '' ? $cert : "/etc/orbit/certs/{$route->domain}.crt",
            'key' => is_string($key) && $key !== '' ? $key : "/etc/orbit/certs/{$route->domain}.key",
        ];
    }
}
