<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build\Sources;

use App\Domain\Instances\InstanceDevPorts;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Models\Node;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Every Route site on a Node: `app-dev` and `app-prod` workload and Router sites, custom proxy
 * Routes, analytics tracking hosts, Vite, hibernation wake, and public Ingress sites.
 * The stored Route state decides the sites; the existing renderer draws each one.
 */
final readonly class RouteCaddySiteSource implements NodeCaddySiteSource
{
    public const string BindPlaceholder = '__ORBIT_APP_BIND__';

    public function __construct(
        private DevelopmentSiteRepository $repository,
        private DevelopmentCaddyConfigRenderer $renderer,
    ) {}

    public function order(): int
    {
        return 40;
    }

    public function sites(Node $node): array
    {
        return $this->fromSites($this->repository->forNode($node));
    }

    /**
     * @param  Collection<int, DevelopmentSite>  $sites
     * @return list<CaddySite>
     */
    public function fromSites(Collection $sites): array
    {
        $sorted = $sites->sortBy(static fn (DevelopmentSite $site): string => $site->domain."\0".$site->scope);

        return [...array_values($sorted
            ->map(function (DevelopmentSite $site): CaddySite {
                $name = $site->scope;

                if ($name === '') {
                    throw new LogicException('A Caddy site scope must not be empty.');
                }

                return new CaddySite(
                    source: $this->source($site),
                    name: $name,
                    listener: $site->publicListener ? CaddyListenerRule::Public : CaddyListenerRule::Wildcard,
                    hosts: [$site->domain],
                    port: 443,
                    body: $this->renderer->render(collect([$site]), self::BindPlaceholder, self::BindPlaceholder),
                    bindPlaceholder: self::BindPlaceholder,
                    unixSockets: is_string($site->localUnixUpstream) && $site->localUnixUpstream !== ''
                        ? [$site->localUnixUpstream]
                        : [],
                );
            })
            ->all()), ...$this->devPortSites($sorted)];
    }

    /**
     * The development site of a container clone on a private Route, on the WireGuard address only: WireGuard peers reach it, LAN
     * neighbours and task VMs do not (docs/reference/development-containers.md#agent-development-servers).
     *
     * @param  Collection<int, DevelopmentSite>  $sites
     * @return list<CaddySite>
     */
    private function devPortSites(Collection $sites): array
    {
        return array_values($sites
            ->filter(static fn (DevelopmentSite $site): bool => ! $site->publicListener && $site->devUpstream !== null)
            ->map(fn (DevelopmentSite $site): CaddySite => new CaddySite(source: $this->source($site), name: "{$site->scope}-dev",
                listener: CaddyListenerRule::WireGuard, hosts: [$site->domain], port: InstanceDevPorts::Listener,
                body: $this->renderer->renderDevPorts($site, self::BindPlaceholder), bindPlaceholder: self::BindPlaceholder))
            ->all());
    }

    /** @return non-empty-string */
    private function source(DevelopmentSite $site): string
    {
        return match (true) {
            $site->publicListener => 'ingress',
            $site->environment === 'production' => 'app-prod',
            default => 'app-dev',
        };
    }
}
