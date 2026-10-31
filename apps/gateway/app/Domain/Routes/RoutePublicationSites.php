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

    /** @return ?Collection<int,DevelopmentSite> */
    public function current(): ?Collection
    {
        return $this->sites;
    }

    /** @template T
     * @param  Collection<int,DevelopmentSite>  $sites
     * @param  Closure():T  $render
     * @return T
     */
    public function render(Collection $sites, Closure $render): mixed
    {
        $previous = $this->sites;
        $this->sites = $sites;
        try {
            return $render();
        } finally {
            $this->sites = $previous;
        }
    }
}
