<?php

declare(strict_types=1);

namespace App\Services\Gateway;

final readonly class CaddyGlobalConfig
{
    /**
     * @var list<string>
     */
    private const array Imports = [
        '/etc/caddy/orbit/*.caddy',
        '/etc/caddy/sites/*.caddy',
    ];

    public function fresh(): string
    {
        return rtrim(implode("\n\n", [
            $this->globalOptions(),
            $this->snippets(),
            $this->imports(),
        ]))."\n";
    }

    public function ensure(string $contents): string
    {
        $contents = rtrim($contents);

        if ($contents === '') {
            return $this->fresh();
        }

        return $this->ensureImports($this->ensureSnippets($contents));
    }

    private function globalOptions(): string
    {
        return <<<'CADDY'
{
    local_certs
    admin off
}
CADDY;
    }

    private function ensureSnippets(string $contents): string
    {
        $updated = rtrim($contents);

        foreach ($this->snippetBlocks() as $name => $block) {
            if (str_contains($updated, "({$name})")) {
                continue;
            }

            $updated .= "\n\n{$block}";
        }

        return $updated;
    }

    private function ensureImports(string $contents): string
    {
        $updated = rtrim($contents);

        foreach (self::Imports as $import) {
            $line = "import {$import}";

            if (str_contains($updated, $line)) {
                continue;
            }

            $updated .= "\n\n{$line}";
        }

        return $updated."\n";
    }

    private function snippets(): string
    {
        return implode("\n\n", $this->snippetBlocks());
    }

    private function imports(): string
    {
        return implode("\n", array_map(
            static fn (string $import): string => "import {$import}",
            self::Imports,
        ));
    }

    /**
     * @return array<string, string>
     */
    private function snippetBlocks(): array
    {
        return [
            'security_headers' => <<<'CADDY'
(security_headers) {
    header {
        X-Content-Type-Options "nosniff"
        X-XSS-Protection "1; mode=block"
        Referrer-Policy "strict-origin-when-cross-origin"
        Permissions-Policy "camera=(), microphone=(), geolocation=()"
        -Server
    }
}
CADDY,
            'profiling_headers' => <<<'CADDY'
(profiling_headers) {
    request_header X-Caddy-Start "{time.now.unix_ms}"
    header {
        X-Caddy-End "{time.now.unix_ms}"
        defer
    }
}
CADDY,
            'security_txt' => <<<'CADDY'
(security_txt) {
}
CADDY,
            'cache_headers' => <<<'CADDY'
(cache_headers) {
    @static {
        path /build/*
    }
    header @static Cache-Control "public, max-age=31536000, immutable"
}
CADDY,
            'path_blocking_public_root' => <<<'CADDY'
(path_blocking_public_root) {
    @blocked path /.env /.env.* /.git/* /artisan
    respond @blocked 404
}
CADDY,
            'path_blocking_project_root' => <<<'CADDY'
(path_blocking_project_root) {
    @blocked path /.env /.env.* /.git/* /vendor/* /storage/* /config/* /database/* /node_modules/* /artisan
    respond @blocked 404
}
CADDY,
        ];
    }
}
