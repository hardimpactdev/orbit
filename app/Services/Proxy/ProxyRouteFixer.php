<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Models\ProxyRoute;

final readonly class ProxyRouteFixer
{
    public function __construct(
        private RemoteShell $remoteShell,
        private ProxyRouteRenderer $renderer,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fix(ProxyRoute $route, DriftEntry $entry): ?array
    {
        if (! in_array($entry->key, ['proxy.route_missing', 'proxy.route_mismatch'], true)) {
            return null;
        }

        if ($route->owner_type !== 'custom' || ! in_array($route->kind, ['proxy', 'redirect'], true)) {
            return null;
        }

        $route->loadMissing('node');

        $content = $this->renderer->render($route);
        $this->remoteShell->run($route->node, $this->installScript($route->domain, $content), ['throw' => true]);

        $route->forceFill([
            'source_hash' => hash('sha256', $content),
        ])->save();

        return [
            'family' => 'proxy',
            'node' => $route->node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Re-applied proxy route {$route->domain} from gateway intent.",
            'details' => [
                'route' => $route->domain,
            ],
        ];
    }

    private function installScript(string $domain, string $content): string
    {
        $sitePath = "/etc/caddy/sites/{$domain}.caddy";

        return sprintf(
            <<<'SH'
sudo install -d -m 0755 /etc/caddy/sites
cat <<'ORBIT_CADDY_SITE' | sudo tee %s >/dev/null
%s
ORBIT_CADDY_SITE
sudo systemctl reload caddy
SH,
            escapeshellarg($sitePath),
            $content,
        );
    }
}
