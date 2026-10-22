<?php

declare(strict_types=1);

namespace App\Infrastructure\Routes;

use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Models\Node;
use App\Models\Route;

final readonly class AppRouteCaddyPublisher
{
    public function __construct(private DevelopmentSiteRepository $sites, private NodeCaddyBuilds $builds) {}

    public function publish(Route $route): void
    {
        $ids = $this->sites->all()->where('domain', $route->domain)->pluck('nodeId')->unique()->all();
        foreach (Node::query()->whereIn('id', $ids)->orderBy('id')->get() as $node) {
            $this->builds->build($node);
        }
    }
}
