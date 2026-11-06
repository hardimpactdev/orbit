<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Infrastructure\AppDev\DevelopmentSite;
use Closure;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Collection;

/** Admission-only prospective sites. No model is saved and no operational callback reads this view. */
#[Scoped]
final class RoutePublicationSites
{
    /** @var ?Collection<int,DevelopmentSite> */
    private ?Collection $sites = null;

    /** Whether the sites are the whole fleet that a Route change leaves behind. */
    private bool $complete = false;

    /** @return ?Collection<int,DevelopmentSite> */
    public function current(): ?Collection
    {
        return $this->sites;
    }

    /** A Route change names no Route in private DNS unless this fleet has a site for it. Other captures keep naming Routes by status. */
    public function isCompleteFleet(): bool
    {
        return $this->complete && $this->sites !== null;
    }

    /** @template T
     * @param  Collection<int,DevelopmentSite>  $sites
     * @param  Closure():T  $render
     * @return T
     */
    public function render(Collection $sites, Closure $render, bool $complete = false): mixed
    {
        [$previous, $previousComplete] = [$this->sites, $this->complete];
        [$this->sites, $this->complete] = [$sites, $complete];
        try {
            return $render();
        } finally {
            [$this->sites, $this->complete] = [$previous, $previousComplete];
        }
    }
}
